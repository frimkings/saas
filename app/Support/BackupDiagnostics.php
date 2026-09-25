<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackupDiagnostics
{
    public static function check(): array
    {
        $configured = trim((string) env('DB_DUMP_BINARY_PATH', ''));
        $resolved = MysqlDumpPath::resolve($configured);
        $executable = MysqlDumpPath::executable($resolved);
        $backupRoot = storage_path('app/backups');
        $temporaryDirectory = config('backup.backup.temporary_directory') ?? storage_path('app/backup-temp');
        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');

        $dbBackupReady = $executable !== null
            && is_dir($backupRoot)
            && is_writable($backupRoot)
            && static::backupDiskReadable()
            && static::directoryReady($temporaryDirectory)
            && static::procOpenEnabled();

        return [
            'configured_path' => $configured ?: 'Auto-detect',
            'resolved_path' => $resolved ?: 'Not found',
            'executable' => $executable ?: 'Not found',
            'binary_exists' => $executable !== null,
            'binary_version' => $executable ? static::binaryVersion($executable) : 'mysqldump.exe was not found.',
            'server_version' => static::serverVersion(),
            'database_name' => $database ?: 'Not configured',
            'database_user' => $username ?: 'Not configured',
            'backup_root' => $backupRoot,
            'backup_root_writable' => is_dir($backupRoot) && is_writable($backupRoot),
            'backup_disk_readable' => static::backupDiskReadable(),
            'temporary_directory' => $temporaryDirectory,
            'temporary_directory_ready' => static::directoryReady($temporaryDirectory),
            'zip_extension_loaded' => extension_loaded('zip'),
            'proc_open_enabled' => static::procOpenEnabled(),
            'db_backup_ready' => $dbBackupReady,
            'full_backup_ready' => $dbBackupReady && extension_loaded('zip'),
            'last_error' => cache('backup_last_error'),
            'candidates' => MysqlDumpPath::candidatePaths(),
        ];
    }

    private static function binaryVersion(string $executable): string
    {
        try {
            $process = new Process([$executable, '--version']);
            $process->setTimeout(10);
            $process->run();

            return trim($process->getOutput() ?: $process->getErrorOutput()) ?: 'Version output unavailable.';
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    private static function serverVersion(): string
    {
        try {
            $row = DB::selectOne('select version() as version');

            return (string) ($row->version ?? 'Unknown');
        } catch (\Throwable $e) {
            return 'Unavailable: ' . $e->getMessage();
        }
    }

    private static function backupDiskReadable(): bool
    {
        try {
            Storage::disk('backups')->files(config('backup.backup.name'));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function directoryReady(string $path): bool
    {
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        return is_dir($path) && is_writable($path);
    }

    private static function procOpenEnabled(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('proc_open', $disabled, true);
    }
}
