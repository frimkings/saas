<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class RestoreBackupCommand extends Command
{
    protected $signature   = 'backup:restore {file? : Backup zip filename to restore from} {--force : Skip the confirmation prompt}';
    protected $description = 'Restore the database and uploaded files from a backup archive.';

    public function handle(): int
    {
        $disk   = Storage::disk('backups');
        $folder = config('backup.backup.name');

        // 1 — pick a file
        $file = $this->argument('file');

        if (!$file) {
            $files = collect($disk->files($folder))
                ->filter(fn ($f) => str_ends_with($f, '.zip'))
                ->map(fn ($f) => [
                    'path' => $f,
                    'name' => basename($f),
                    'ts'   => $disk->lastModified($f),
                ])
                ->sortByDesc('ts')
                ->values();

            if ($files->isEmpty()) {
                $this->error('No backup archives found in storage/app/backups/.');
                return 1;
            }

            $choice = $this->choice(
                'Which backup do you want to restore?',
                $files->pluck('name')->all(),
                0
            );

            $file = $files->firstWhere('name', $choice)['path'];
        }

        $fullPath = $disk->path($file);

        if (!file_exists($fullPath)) {
            $this->error("File not found: {$fullPath}");
            return 1;
        }

        // 2 — confirm
        $this->warn('');
        $this->warn('  ⚠  This will OVERWRITE the current database and uploaded files.');
        $this->warn('     Restoring: ' . basename($file));
        $this->warn('');

        if (!$this->option('force') && !$this->confirm('Are you sure you want to continue?', false)) {
            $this->info('Restore cancelled.');
            return 0;
        }

        // 3 — extract
        $tempDir = storage_path('app/restore-temp-' . time());
        @mkdir($tempDir, 0755, true);

        $this->info('Extracting archive…');
        $zip = new ZipArchive();

        if ($zip->open($fullPath) !== true) {
            $this->error('Could not open zip archive. It may be corrupted or password-protected.');
            return 1;
        }

        $zip->extractTo($tempDir);
        $zip->close();

        // 4 — restore database (handles both compressed .sql.gz and plain .sql dumps)
        $sqlGzFiles = $this->findFiles($tempDir, '*.sql.gz');
        $sqlFiles   = $this->findFiles($tempDir, '*.sql');

        if (!empty($sqlGzFiles)) {
            $sqlGzFile = $sqlGzFiles[0];
            $sqlFile   = substr($sqlGzFile, 0, -3); // strip .gz

            $this->info('Decompressing database dump…');
            $this->gunzip($sqlGzFile, $sqlFile);

            $this->info('Importing database…');
            $this->importSql($sqlFile);
            @unlink($sqlFile);
        } elseif (!empty($sqlFiles)) {
            $this->info('Importing database…');
            $this->importSql($sqlFiles[0]);
        } else {
            $this->warn('No database dump found in archive — skipping DB restore.');
        }

        // 5 — restore uploaded files
        $publicSrc = $this->findDirectory($tempDir, 'public');

        if ($publicSrc) {
            $this->info('Restoring uploaded files…');
            $this->copyDir($publicSrc, storage_path('app/public'));
            $this->info('  Files restored → storage/app/public/');
        } else {
            $this->warn('No uploaded-files folder found in archive — skipping file restore.');
        }

        // 6 — restore .env (save as .env.restored for safety)
        $envFiles = $this->findFiles($tempDir, '.env');

        if (!empty($envFiles)) {
            $dest = base_path('.env.restored');
            copy($envFiles[0], $dest);
            $this->info('  .env saved as .env.restored — review it and apply manually if needed.');
        }

        // 7 — cleanup
        $this->deleteDir($tempDir);

        $this->info('');
        $this->info('✔  Restore complete. Restart your web server if the database credentials changed.');
        return 0;
    }

    // ── Helpers ──────────────────────────────────────────────

    private function gunzip(string $source, string $dest): void
    {
        $gz  = gzopen($source, 'rb');
        $out = fopen($dest, 'wb');
        while (!gzeof($gz)) {
            fwrite($out, gzread($gz, 65536));
        }
        gzclose($gz);
        fclose($out);
    }

    private function importSql(string $sqlFile): void
    {
        $cfg  = config('database.connections.' . config('database.default'));
        $bin  = $this->mysqlBinary();
        $pass = $cfg['password'] !== '' ? '--password=' . escapeshellarg($cfg['password']) : '';

        $cmd = sprintf(
            '%s --host=%s --port=%d --user=%s %s %s < %s 2>&1',
            $bin,
            escapeshellarg($cfg['host']),
            (int) ($cfg['port'] ?? 3306),
            escapeshellarg($cfg['username']),
            $pass,
            escapeshellarg($cfg['database']),
            escapeshellarg($sqlFile)
        );

        $out = shell_exec($cmd);

        if ($out && !str_contains(strtolower($out), 'warning: using a password')) {
            $this->warn("  mysql output: {$out}");
        }
    }

    private function mysqlBinary(): string
    {
        $candidates = [
            'C:\\xampp\\mysql\\bin\\mysql.exe',
            'C:\\mysql\\bin\\mysql.exe',
            'mysql',
        ];

        foreach ($candidates as $bin) {
            if (file_exists($bin)) {
                return '"' . $bin . '"';
            }
        }

        return 'mysql'; // hope it's in PATH
    }

    private function copyDir(string $src, string $dest): void
    {
        if (!is_dir($dest)) {
            @mkdir($dest, 0755, true);
        }

        foreach (scandir($src) as $item) {
            if ($item === '.' || $item === '..') continue;
            $s = $src . DIRECTORY_SEPARATOR . $item;
            $d = $dest . DIRECTORY_SEPARATOR . $item;
            is_dir($s) ? $this->copyDir($s, $d) : copy($s, $d);
        }
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function findFiles(string $dir, string $pattern): array
    {
        $results = [];
        $items   = @scandir($dir);
        if (!$items) return [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $results = array_merge($results, $this->findFiles($path, $pattern));
            } elseif (fnmatch($pattern, $item)) {
                $results[] = $path;
            }
        }

        return $results;
    }

    private function findDirectory(string $base, string $name): ?string
    {
        $items = @scandir($base);
        if (!$items) return null;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $base . DIRECTORY_SEPARATOR . $item;
            if (!is_dir($path)) continue;
            if ($item === $name) return $path;
            $found = $this->findDirectory($path, $name);
            if ($found) return $found;
        }

        return null;
    }
}
