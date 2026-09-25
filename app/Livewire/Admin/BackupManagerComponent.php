<?php

namespace App\Livewire\Admin;

use App\Models\Setting;
use App\Services\LicenseService;
use App\Support\BackupDiagnostics;
use App\Support\Feature;
use App\Support\MysqlDumpPath;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\Process\Process;

class BackupManagerComponent extends Component
{
    public bool  $isRunning   = false;
    public bool  $isRestoring = false;
    public array $copyResults = [];

    // Extra destination management
    public string $newPath    = '';
    public array  $extraPaths = [];

    // Folder browser
    public bool   $browserOpen    = false;
    public string $browserPath    = '';
    public array  $browserDirs    = [];
    public array  $browserDrives  = [];

    // Create new folder (inside browser)
    public bool   $creatingFolder = false;
    public string $newFolderName  = '';

    protected $rules = [
        'newPath' => 'required|string|max:500',
    ];

    protected $messages = [
        'newPath.required' => 'Please enter a folder path.',
    ];

    public function mount(): void
    {
        $this->authorise();
        $this->extraPaths = Setting::getSettings()->backup_extra_paths ?? [];
    }

    // Fix C1: re-run role check on every Livewire AJAX call, not just initial render
    public function hydrate(): void
    {
        $this->authorise();
    }

    private function authorise(): void
    {
        abort_if(!auth()->user()?->hasRole('Super Admin'), 403);
        abort_if(!LicenseService::has(Feature::MANUAL_BACKUP), 403);
    }

    public function openBrowser(): void
    {
        $this->browserDrives  = $this->getWindowsDrives();
        $this->browserPath    = '';
        $this->browserDirs    = [];
        $this->browserOpen    = true;
        $this->creatingFolder = false;
        $this->newFolderName  = '';
    }

    public function closeBrowser(): void
    {
        $this->browserOpen    = false;
        $this->creatingFolder = false;
        $this->newFolderName  = '';
    }

    public function browserNavigate(string $path): void
    {
        if (!is_dir($path)) return;

        $this->browserPath    = $path;
        $this->browserDirs    = $this->listSubdirectories($path);
        $this->creatingFolder = false;
        $this->newFolderName  = '';
    }

    public function browserUp(): void
    {
        if (!$this->browserPath) return;

        $this->creatingFolder = false;
        $this->newFolderName  = '';

        $parent = dirname($this->browserPath);

        if ($parent === $this->browserPath) {
            $this->browserPath = '';
            $this->browserDirs = [];
            return;
        }

        $this->browserNavigate($parent);
    }

    public function selectCurrentFolder(): void
    {
        if (!$this->browserPath || !is_dir($this->browserPath)) return;

        $this->newPath     = $this->browserPath;
        $this->browserOpen = false;
        $this->addPath();
    }

    public function toggleCreateFolder(): void
    {
        $this->creatingFolder = !$this->creatingFolder;
        $this->newFolderName  = '';
        $this->resetErrorBag('newFolderName');
    }

    public function createFolder(): void
    {
        $name = trim($this->newFolderName);

        if (!$name || !$this->browserPath) return;

        if (preg_match('/[\/\\\\:*?"<>|]/', $name)) {
            $this->addError('newFolderName', 'Name cannot contain: \\ / : * ? " < > |');
            return;
        }

        $newPath = rtrim($this->browserPath, '/\\') . DIRECTORY_SEPARATOR . $name;

        if (is_dir($newPath)) {
            $this->addError('newFolderName', 'A folder with that name already exists.');
            return;
        }

        if (!@mkdir($newPath, 0755, true)) {
            $this->addError('newFolderName', 'Could not create folder — check permissions.');
            return;
        }

        $this->creatingFolder = false;
        $this->newFolderName  = '';
        $this->browserNavigate($newPath);
    }

    private function getWindowsDrives(): array
    {
        $wmicInfo = $this->getDriveInfoFromWmic();
        $drives   = [];

        foreach (range('A', 'Z') as $letter) {
            $path = $letter . ':\\';
            if (!is_dir($path)) continue;

            $info  = $wmicInfo[$path] ?? [];
            $type  = $info['type']  ?? 3;
            $label = $info['label'] ?? '';
            $free  = $info['free']  ?? 0;
            $size  = $info['size']  ?? 0;

            [$icon, $iconColor, $typeLabel] = match ($type) {
                2       => ['fas fa-usb',           '#0f9d58', 'Removable Drive'],
                4       => ['fas fa-network-wired', '#9c27b0', 'Network Drive'],
                5       => ['fas fa-compact-disc',  '#ff9800', 'CD / DVD Drive'],
                default => ['fas fa-hdd',           '#4285f4', 'Local Disk'],
            };

            $drives[] = [
                'path'      => $path,
                'letter'    => $letter . ':',
                'label'     => $label ?: $typeLabel,
                'icon'      => $icon,
                'iconColor' => $iconColor,
                'typeLabel' => $typeLabel,
                'free'      => $free,
                'size'      => $size,
                'freeHuman' => $free > 0 ? $this->humanFileSize($free) : '',
                'sizeHuman' => $size > 0 ? $this->humanFileSize($size) : '',
            ];
        }

        return $drives;
    }

    private function getDriveInfoFromWmic(): array
    {
        $info = [];
        try {
            $raw = @shell_exec('wmic logicaldisk get Caption,DriveType,FreeSpace,Size,VolumeName /format:csv 2>nul');
            if (!$raw) return $info;

            $lines = array_values(array_filter(array_map('trim', explode("\n", $raw))));
            if (count($lines) < 2) return $info;

            $headers = array_flip(array_map('trim', str_getcsv($lines[0])));

            for ($i = 1; $i < count($lines); $i++) {
                if (empty($lines[$i])) continue;
                $cols    = str_getcsv($lines[$i]);
                $caption = trim($cols[$headers['Caption']] ?? '');
                if (!$caption) continue;

                $info[$caption . '\\'] = [
                    'type'  => (int) ($cols[$headers['DriveType']]  ?? 3),
                    'free'  => (int) ($cols[$headers['FreeSpace']]  ?? 0),
                    'size'  => (int) ($cols[$headers['Size']]       ?? 0),
                    'label' => trim($cols[$headers['VolumeName']]   ?? ''),
                ];
            }
        } catch (\Throwable) {}

        return $info;
    }

    private function listSubdirectories(string $path): array
    {
        $dirs = [];
        try {
            $items = @scandir($path);
            if (!$items) return [];

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $full = rtrim($path, '\/') . DIRECTORY_SEPARATOR . $item;
                if (is_dir($full) && !str_starts_with($item, '$')) {
                    $dirs[] = ['name' => $item, 'path' => $full];
                }
            }
        } catch (\Throwable) {}

        usort($dirs, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $dirs;
    }

    public function runBackup(): void
    {
        $this->isRunning   = true;
        $this->copyResults = [];
        cache()->forget('backup_last_error');

        $this->runRawDatabaseBackup();
    }

    public function runFullBackup(): void
    {
        $this->isRunning   = true;
        $this->copyResults = [];
        cache()->forget('backup_last_error');

        $this->runBackupCommand(
            'backup:run --disable-notifications',
            'Full backup completed successfully. Database, uploads, and configured files were archived.'
        );
    }

    private function runBackupCommand(string $command, string $successMessage): void
    {
        try {
            $exitCode = Artisan::call($command);
            $output = Artisan::output();
            $success = $exitCode === 0 && str_contains($output, 'Backup completed');

            if ($success && !empty($this->extraPaths)) {
                Artisan::call('backup:copy-to-drives');
                $this->copyResults = cache('backup_copy_results', []);
            }

            $this->dispatch('notify', ...($success
                ? ['type' => 'success', 'message' => $successMessage]
                : ['type' => 'warning', 'message' => 'Backup finished but the output was unexpected. Check storage manually.']
            ));

            if (!$success) {
                cache()->put('backup_last_error', trim($output) ?: "Backup command exited with code {$exitCode}.", now()->addDays(7));
            }
        } catch (\Throwable $e) {
            $output = trim(Artisan::output());
            $message = get_class($e) . ': ' . $e->getMessage();

            if ($output !== '') {
                $message .= "\n\nCommand output:\n" . $output;
            }

            if ($e->getPrevious()) {
                $message .= "\n\nPrevious error:\n" . get_class($e->getPrevious()) . ': ' . $e->getPrevious()->getMessage();
            }

            cache()->put('backup_last_error', $message, now()->addDays(7));
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Backup failed. Check your database connection and mysqldump configuration.']);
        }

        $this->isRunning = false;
    }

    private function runRawDatabaseBackup(): void
    {
        $credentialsFile = null;

        try {
            $connection = config('database.connections.mysql');
            $database = (string) ($connection['database'] ?? '');

            if ($database === '') {
                throw new RuntimeException('Database name is not configured.');
            }

            $dumpPath = MysqlDumpPath::resolve(env('DB_DUMP_BINARY_PATH'));
            $executable = MysqlDumpPath::executable($dumpPath);

            if (!$executable) {
                throw new RuntimeException('mysqldump.exe was not found. Check DB_DUMP_BINARY_PATH or Laragon MySQL.');
            }

            $disk = Storage::disk('backups');
            $folder = trim((string) config('backup.backup.name'), '/\\');
            $disk->makeDirectory($folder);

            $safeDatabase = preg_replace('/[^A-Za-z0-9_-]+/', '-', $database) ?: 'database';
            $filename = 'database-' . $safeDatabase . '-' . now()->format('Y-m-d-H-i-s') . '.sql';
            $relativePath = $folder . '/' . $filename;
            $absolutePath = $disk->path($relativePath);

            if (!is_dir(dirname($absolutePath))) {
                mkdir(dirname($absolutePath), 0755, true);
            }

            $tempDir = config('backup.backup.temporary_directory') ?? storage_path('app/backup-temp');
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $credentialsFile = tempnam($tempDir, 'mysql-backup-');
            if ($credentialsFile === false) {
                throw new RuntimeException('Could not create temporary MySQL credentials file.');
            }

            file_put_contents($credentialsFile, $this->mysqlCredentialsFileContents($connection));

            $command = [
                $executable,
                '--defaults-extra-file=' . $credentialsFile,
                '--skip-comments',
                '--extended-insert',
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--default-character-set=' . ($connection['charset'] ?? 'utf8mb4'),
                '--result-file=' . $absolutePath,
                $database,
            ];

            $process = new Process($command);
            $process->setTimeout((int) ($connection['dump']['timeout'] ?? 300));
            $process->run();

            if (!$process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'mysqldump failed.');
            }

            clearstatcache(true, $absolutePath);
            if (!is_file($absolutePath) || filesize($absolutePath) <= 0) {
                throw new RuntimeException('mysqldump finished but the SQL file was not created.');
            }

            $this->copyBackupFileToExtraPaths($absolutePath, $filename);

            $this->dispatch('notify', ...[
                'type' => 'success',
                'message' => 'Database SQL backup completed successfully. The .sql file appears in the list below.',
            ]);
        } catch (\Throwable $e) {
            cache()->put('backup_last_error', get_class($e) . ': ' . $e->getMessage(), now()->addDays(7));
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Database SQL backup failed. Check Backup Diagnostics.']);
        } finally {
            if ($credentialsFile && is_file($credentialsFile)) {
                @unlink($credentialsFile);
            }

            $this->isRunning = false;
        }
    }

    private function mysqlCredentialsFileContents(array $connection): string
    {
        $lines = [
            '[client]',
            $this->mysqlOptionLine('user', $connection['username'] ?? ''),
            $this->mysqlOptionLine('port', $connection['port'] ?? 3306),
        ];

        if (($connection['password'] ?? '') !== '') {
            $lines[] = $this->mysqlOptionLine('password', $connection['password']);
        }

        if (($connection['unix_socket'] ?? '') !== '') {
            $lines[] = $this->mysqlOptionLine('socket', $connection['unix_socket']);
        } else {
            $lines[] = $this->mysqlOptionLine('host', $connection['host'] ?? '127.0.0.1');
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function mysqlOptionLine(string $key, mixed $value): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);

        return $key . '="' . $escaped . '"';
    }

    private function copyBackupFileToExtraPaths(string $sourcePath, string $filename): void
    {
        $this->copyResults = [];

        if (empty($this->extraPaths)) {
            return;
        }

        foreach ($this->extraPaths as $path) {
            if (!is_dir($path)) {
                $this->copyResults[] = ['path' => $path, 'ok' => false, 'reason' => 'Drive not connected'];
                continue;
            }

            if (!is_writable($path)) {
                $this->copyResults[] = ['path' => $path, 'ok' => false, 'reason' => 'Not writable'];
                continue;
            }

            try {
                copy($sourcePath, rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $filename);
                $this->copyResults[] = ['path' => $path, 'ok' => true, 'reason' => ''];
            } catch (\Throwable $e) {
                $this->copyResults[] = ['path' => $path, 'ok' => false, 'reason' => $e->getMessage()];
            }
        }
    }

    public function cleanBackups(): void
    {
        try {
            Artisan::call('backup:prune-custom');
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Backups pruned — latest kept per day/week/month/year.']);
        } catch (\Throwable $e) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Pruning failed: ' . $e->getMessage()]);
        }
    }

    // Fix C2: accept a numeric index, resolve the real path server-side.
    // The browser never controls a filesystem path — only an integer offset
    // into the server-computed backup list.
    public function requestDeleteBackup(int $index): void
    {
        $backup = $this->resolveBackupByIndex($index);
        if (!$backup) return;
        $this->dispatch('show-backup-delete-confirmation', ...[
            'index' => $index,
            'name'  => $backup['name'],
        ]);
    }

    public function deleteBackup(int $index): void
    {
        $backup = $this->resolveBackupByIndex($index);
        abort_unless($backup, 404);
        Storage::disk('backups')->delete($backup['path']);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Backup deleted.']);
    }

    public function requestRestore(int $index): void
    {
        $backup = $this->resolveBackupByIndex($index);
        if (!$backup) return;
        if (($backup['extension'] ?? '') !== 'zip') {
            $this->dispatch('notify', ...['type' => 'info', 'message' => 'Only full ZIP backups can be restored from this button. SQL files are database-only exports.']);
            return;
        }
        $this->dispatch('show-backup-restore-confirmation', ...[
            'index' => $index,
            'name'  => $backup['name'],
        ]);
    }

    public function restoreBackup(int $index): void
    {
        $backup = $this->resolveBackupByIndex($index);
        abort_unless($backup, 404);

        $this->isRestoring = true;

        $exitCode = Artisan::call('backup:restore', [
            'file'    => $backup['path'],
            '--force' => true,
        ]);

        $this->isRestoring = false;

        if ($exitCode === 0) {
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Backup restored from ' . $backup['name'] . '. Refresh the page if the UI looks stale.']);
        } else {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Restore failed. Output: ' . trim(Artisan::output())]);
        }
    }

    // Resolve a backup by its numeric index in the sorted server-side list
    private function resolveBackupByIndex(int $index): ?array
    {
        return $this->buildBackupList()->get($index);
    }

    public function addPath(): void
    {
        $this->validateOnly('newPath');

        $path = rtrim(trim($this->newPath), '/\\');

        if (!is_dir($path)) {
            $this->addError('newPath', 'Directory not found. Make sure the drive is connected and the path exists.');
            return;
        }

        if (!is_writable($path)) {
            $this->addError('newPath', 'Directory is not writable. Check folder permissions.');
            return;
        }

        if (in_array($path, $this->extraPaths)) {
            $this->addError('newPath', 'This path is already in the list.');
            return;
        }

        $this->extraPaths[] = $path;
        $this->saveDestinations();
        $this->newPath = '';
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Backup destination added.']);
    }

    public function removePath(int $index): void
    {
        unset($this->extraPaths[$index]);
        $this->extraPaths = array_values($this->extraPaths);
        $this->saveDestinations();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Destination removed.']);
    }

    private function saveDestinations(): void
    {
        Setting::getSettings()->update([
            'backup_extra_paths' => !empty($this->extraPaths) ? $this->extraPaths : null,
        ]);
    }

    public function render()
    {
        $backups = $this->buildBackupList();
        return view('livewire.admin.backup-manager-component', [
            'backups'   => $backups,
            'totalSize' => $this->humanFileSize($backups->sum('size')),
            'diagnostics' => BackupDiagnostics::check(),
        ])->layout('layouts.admin.admin-layout');
    }

    // Single authoritative backup list — render() and resolveBackupByIndex() both call this.
    // Index position in this collection is the only thing the browser ever sends back.
    private function buildBackupList(): \Illuminate\Support\Collection
    {
        $disk = Storage::disk('backups');
        return collect($disk->files(config('backup.backup.name')))
            ->map(fn ($path) => [
                'path'          => $path,
                'name'          => basename($path),
                'extension'     => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                'size'          => $disk->size($path),
                'size_human'    => $this->humanFileSize($disk->size($path)),
                'last_modified' => $disk->lastModified($path),
            ])
            ->sortByDesc('last_modified')
            ->values();
    }

    private function humanFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)       return number_format($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
