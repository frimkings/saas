<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReconcileMigrationHistory extends Command
{
    protected $signature = 'schema:reconcile-migrations
        {--apply : Record verified legacy migrations as applied}
        {--force : Allow --apply without confirmation in production}';

    protected $description = 'Safely reconcile known legacy migrations whose schema changes already exist';

    public function handle(): int
    {
        if (!Schema::hasTable('migrations')) {
            $this->error('The migrations table does not exist.');
            return self::FAILURE;
        }

        $applied = DB::table('migrations')->pluck('migration')->flip();
        $rows = [];
        $ready = [];
        $incomplete = [];

        foreach ($this->manifest() as $migration => $requirements) {
            if ($applied->has($migration)) {
                $rows[] = [$migration, 'applied', 'Already recorded'];
                continue;
            }

            $missing = $this->missingRequirements($requirements);

            if ($missing) {
                $rows[] = [$migration, 'incomplete', implode(', ', $missing)];
                $incomplete[] = $migration;
                continue;
            }

            $rows[] = [$migration, 'ready', 'Schema verified'];
            $ready[] = $migration;
        }

        $this->table(['Migration', 'Status', 'Details'], $rows);

        if (!$this->option('apply')) {
            $this->newLine();
            $this->info('Dry run only. Use --apply after reviewing the verified entries.');
            return $incomplete ? self::FAILURE : self::SUCCESS;
        }

        if (!$ready) {
            if ($incomplete) {
                $this->error('No verified entries are ready; incomplete migrations remain pending.');
                return self::FAILURE;
            }

            $this->info('Migration history is already reconciled.');
            return self::SUCCESS;
        }

        if (
            app()->environment('production')
            && !$this->option('force')
            && !$this->confirm('Record the verified migrations in the production migration history?')
        ) {
            $this->warn('Cancelled. No migration history was changed.');
            return self::FAILURE;
        }

        // Historical entries belong to the baseline batch. Recording them as
        // the newest batch would make a routine rollback target old live tables.
        $baselineBatch = (int) (DB::table('migrations')->min('batch') ?: 1);

        DB::transaction(function () use ($ready, $baselineBatch): void {
            foreach ($ready as $migration) {
                DB::table('migrations')->insertOrIgnore([
                    'migration' => $migration,
                    'batch' => $baselineBatch,
                ]);
            }
        });

        $this->info(count($ready) . " verified migration(s) recorded in baseline batch {$baselineBatch}.");

        if ($incomplete) {
            $this->warn(count($incomplete) . ' incomplete migration(s) remain pending and must run normally.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function missingRequirements(array $requirements): array
    {
        $table = $requirements['table'];

        if (!Schema::hasTable($table)) {
            return ["missing table: {$table}"];
        }

        return collect($requirements['columns'])
            ->reject(fn (string $column) => Schema::hasColumn($table, $column))
            ->map(fn (string $column) => "missing {$table}.{$column}")
            ->values()
            ->all();
    }

    private function manifest(): array
    {
        return [
            '2026_06_28_000001_create_consultation_notes_table' => [
                'table' => 'consultation_notes',
                'columns' => ['id', 'consultation_id', 'patient_id', 'user_id', 'note_type', 'note'],
            ],
            '2026_06_28_000002_create_report_deliveries_table' => [
                'table' => 'report_deliveries',
                'columns' => ['id', 'delivery_key', 'period', 'report_payload', 'recipients', 'status'],
            ],
            '2026_06_28_000003_create_system_health_statuses_table' => [
                'table' => 'system_health_statuses',
                'columns' => ['id', 'key', 'value', 'checked_at'],
            ],
            '2026_07_07_000001_add_insurance_member_name_to_patients_table' => [
                'table' => 'patients',
                'columns' => ['insurance_member_name'],
            ],
            '2026_07_14_000001_create_consultation_notes_table' => [
                'table' => 'consultation_notes',
                'columns' => ['id', 'consultation_id', 'patient_id', 'note_type', 'note'],
            ],
            '2026_07_14_000002_create_lens_options_table' => [
                'table' => 'lens_options',
                'columns' => ['id', 'family', 'display_name'],
            ],
            '2026_07_15_000001_add_scheduling_fields_to_appointments_table' => [
                'table' => 'appointments',
                'columns' => ['doctor_id', 'duration_minutes', 'arrived_at', 'doctor_started_at', 'completed_at'],
            ],
            '2026_07_15_000002_add_workflow_fields_to_lens_orders_table' => [
                'table' => 'lens_orders',
                'columns' => ['lab_cost', 'stock_reserved_at', 'cancelled_at'],
            ],
        ];
    }
}
