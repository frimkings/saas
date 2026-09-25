<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class LegacyImportAnalyzer
{
    public function analyze(string $file): array
    {
        if (! is_file($file) || filesize($file) === 0) {
            throw new RuntimeException('The uploaded SQL file is empty or unavailable.');
        }

        $database = 'legacy_analyze_'.Str::lower(Str::random(16));
        $baseConfig = config('database.connections.mysql');
        $config = array_merge($baseConfig, [
            'username' => config('legacy_import.database_username') ?: $baseConfig['username'],
            'password' => config('legacy_import.database_password') ?? $baseConfig['password'],
        ]);
        config(['database.connections.legacy_import_admin' => $config]);
        DB::purge('legacy_import_admin');
        $admin = DB::connection('legacy_import_admin');
        $admin->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            $process = new Process(['mysql', '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'], $database]);
            $process->setEnv(array_merge($_ENV, ['MYSQL_PWD' => (string) ($config['password'] ?? '')]));
            $stream = fopen($file, 'rb');
            if ($stream === false) throw new RuntimeException('The uploaded SQL file could not be opened.');
            $process->setInput($stream)->setTimeout(300)->run();
            fclose($stream);
            if (! $process->isSuccessful()) {
                throw new RuntimeException('SQL preflight restore failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
            }

            config(['database.connections.legacy_import' => array_merge($config, ['database' => $database])]);
            DB::purge('legacy_import');
            $legacy = DB::connection('legacy_import');
            $schema = $legacy->getSchemaBuilder();
            $tables = collect($legacy->select(
                'SELECT TABLE_NAME AS table_name FROM information_schema.tables WHERE table_schema = ? AND TABLE_TYPE = ?',
                [$database, 'BASE TABLE']
            ))->pluck('table_name')->unique()->sort()->values();
            $counts = [];
            foreach ($tables as $table) $counts[$table] = $legacy->table($table)->count();

            $emails = $schema->hasTable('users') && $schema->hasColumn('users', 'email')
                ? $legacy->table('users')->whereNotNull('email')->pluck('email')->map(fn ($email) => strtolower(trim((string) $email)))->filter()->unique()->values()->all()
                : [];
            $existingEmails = User::query()->whereIn(DB::raw('LOWER(email)'), $emails)->pluck('email')->map(fn ($email) => strtolower($email))->values()->all();
            $identities = [];
            if ($schema->hasTable('users')) {
                $userColumns = $schema->getColumnListing('users');
                foreach ($legacy->table('users')->orderBy('id')->get() as $legacyUser) {
                    $email = in_array('email', $userColumns, true) ? strtolower(trim((string) $legacyUser->email)) : '';
                    $identities[] = [
                        'legacy_id' => (int) $legacyUser->id,
                        'name' => in_array('name', $userColumns, true) ? (string) $legacyUser->name : 'Legacy User '.$legacyUser->id,
                        'email' => $email,
                        'existing' => $email !== '' && in_array($email, $existingEmails, true),
                    ];
                }
            }
            $clinicName = null;
            if ($schema->hasTable('settings')) {
                foreach (['clinic_name', 'name', 'company_name'] as $column) {
                    if ($schema->hasColumn('settings', $column)) {
                        $clinicName = trim((string) $legacy->table('settings')->value($column)) ?: null;
                        if ($clinicName) break;
                    }
                }
            }
            $compatibility = app(LegacyImportCompatibilityValidator::class)->validate($legacy, $database);

            return [
                'tables' => $tables->all(), 'counts' => $counts, 'clinic_name' => $clinicName,
                'suggested_slug' => Str::slug($clinicName ?? ''), 'emails' => $emails, 'identities' => $identities,
                'existing_emails' => $existingEmails,
                'size' => filesize($file), 'count_source' => 'staging_database', 'compatibility' => $compatibility, 'analyzed_at' => now()->toIso8601String(),
            ];
        } finally {
            DB::purge('legacy_import');
            $admin->statement("DROP DATABASE IF EXISTS `{$database}`");
            DB::purge('legacy_import_admin');
        }
    }
}
