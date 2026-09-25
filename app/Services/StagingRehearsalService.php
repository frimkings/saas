<?php

namespace App\Services;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class StagingRehearsalService
{
    public function run(): array
    {
        $checks = [];
        $add = function (string $key, string $label, bool $passed, string $severity, string $detail, string $remediation = '') use (&$checks): void {
            $checks[$key] = compact('label', 'passed', 'severity', 'detail', 'remediation');
        };

        $readiness = app(DeploymentReadinessService::class)->run(false);
        $add('production_readiness', 'Production readiness gate', $readiness['ready'], 'critical',
            strtoupper($readiness['status']).' - '.$readiness['summary']['failed'].' critical failure(s)',
            'Resolve every critical item reported by platform:deployment-readiness.');

        $requiredRoutes = [
            'login', 'dashboard', 'tenant.branch.switch', 'secretary.patients',
            'secretary.appointments', 'doctor.patient-records', 'cashier.seller-desk',
            'admin.reports', 'platform.dashboard',
        ];
        $missingRoutes = collect($requiredRoutes)->reject(fn (string $name) => Route::has($name))->values();
        $add('routes', 'Critical application routes registered', $missingRoutes->isEmpty(), 'critical',
            $missingRoutes->isEmpty() ? count($requiredRoutes).' routes verified' : 'Missing: '.$missingRoutes->implode(', '),
            'Restore missing route declarations before deployment.');

        $requiredTables = ['clinics', 'branches', 'clinic_user', 'branch_user', 'branch_user_role', 'patients', 'consultations', 'sales', 'jobs', 'failed_jobs'];
        $missingTables = collect($requiredTables)->reject(fn (string $table) => Schema::hasTable($table))->values();
        $add('schema', 'Operational and queue tables available', $missingTables->isEmpty(), 'critical',
            $missingTables->isEmpty() ? count($requiredTables).' tables verified' : 'Missing: '.$missingTables->implode(', '),
            'Apply all migrations to the staging database.');

        try {
            DB::beginTransaction();
            DB::select('SELECT 1');
            DB::rollBack();
            $add('transaction', 'Database transaction and rollback probe', true, 'critical', 'Rollback completed');
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            $add('transaction', 'Database transaction and rollback probe', false, 'critical', $e->getMessage(), 'Correct database transaction support before cutover.');
        }

        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) ($event->command ?? ''));
        $requiredCommands = ['system:health-heartbeat', 'platform:deployment-readiness', 'subscriptions:evaluate-lifecycle', 'tenancy:run-scheduled'];
        $missingCommands = collect($requiredCommands)->reject(fn (string $command) => $commands->contains(fn (string $scheduled) => str_contains($scheduled, $command)))->values();
        $add('scheduler', 'Critical schedules registered', $missingCommands->isEmpty(), 'critical',
            $missingCommands->isEmpty() ? count($commands).' scheduled events loaded' : 'Missing: '.$missingCommands->implode(', '),
            'Restore the missing scheduled commands and run schedule:list.');

        $backup = $this->latestBackupIntegrity();
        $add('backup_integrity', 'Latest backup is readable', $backup['passed'], 'critical', $backup['detail'],
            'Create a fresh backup and validate it before rehearsing restoration on an isolated database.');

        $publicStorage = public_path('storage');
        $storageReady = is_link($publicStorage) || is_dir($publicStorage);
        $add('public_storage', 'Public storage link available', $storageReady, 'warning', $publicStorage,
            'Run php artisan storage:link on the deployment host.');

        $configCached = is_file(base_path('bootstrap/cache/config.php'));
        $add('config_cache', 'Configuration cache built', $configCached, 'warning', $configCached ? 'Cached' : 'Not cached',
            'Run php artisan config:cache after setting production environment values.');

        $critical = collect($checks)->where('passed', false)->where('severity', 'critical')->count();
        $warnings = collect($checks)->where('passed', false)->where('severity', 'warning')->count();

        return [
            'passed' => $critical === 0,
            'status' => $critical > 0 ? 'blocked' : ($warnings > 0 ? 'warning' : 'passed'),
            'generated_at' => now()->toIso8601String(),
            'summary' => ['passed' => collect($checks)->where('passed', true)->count(), 'warnings' => $warnings, 'failed' => $critical, 'total' => count($checks)],
            'checks' => $checks,
            'readiness' => $readiness,
            'recovery_instruction' => 'Restore the verified backup only into an isolated staging database, run migrations, then execute the clinic acceptance suite.',
        ];
    }

    private function latestBackupIntegrity(): array
    {
        try {
            $disk = Storage::disk('backups');
            $path = collect($disk->allFiles())
                ->filter(fn (string $file) => preg_match('/\.(zip|sql)$/i', $file))
                ->sortByDesc(fn (string $file) => $disk->lastModified($file))
                ->first();
            if (! $path) return ['passed' => false, 'detail' => 'No ZIP or SQL backup found'];
            $size = (int) $disk->size($path);
            if ($size < 1024) return ['passed' => false, 'detail' => basename($path).' is unexpectedly small ('.$size.' bytes)'];

            if (str_ends_with(strtolower($path), '.zip')) {
                if (! class_exists(ZipArchive::class)) return ['passed' => false, 'detail' => 'PHP ZIP extension is unavailable'];
                $zip = new ZipArchive();
                $opened = $zip->open($disk->path($path));
                $entries = $opened === true ? $zip->numFiles : 0;
                if ($opened === true) $zip->close();
                return ['passed' => $opened === true && $entries > 0, 'detail' => basename($path)." ({$entries} archive entries, {$size} bytes)"];
            }

            $handle = fopen($disk->path($path), 'rb');
            if ($handle === false) return ['passed' => false, 'detail' => basename($path).' could not be opened'];
            try {
                $sample = fread($handle, 1048576) ?: '';
            } finally {
                fclose($handle);
            }
            $looksLikeSql = preg_match('/(CREATE TABLE|INSERT INTO|-- MySQL|SET SQL_MODE)/i', $sample) === 1;
            return ['passed' => $looksLikeSql, 'detail' => basename($path)." ({$size} bytes)"];
        } catch (\Throwable $e) {
            return ['passed' => false, 'detail' => $e->getMessage()];
        }
    }
}
