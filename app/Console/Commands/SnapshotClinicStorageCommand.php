<?php

namespace App\Console\Commands;

use App\Support\Usage\UsageMeter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Daily: how many database rows each clinic holds, across every table with a clinic_id (Platform → Usage). */
class SnapshotClinicStorageCommand extends Command
{
    protected $signature = 'usage:snapshot-storage {--force : Run even when usage metering is off}';
    protected $description = "Record each clinic's stored database rows for today";

    public function handle(): int
    {
        if (! UsageMeter::enabled() && ! $this->option('force')) {
            $this->info('Usage metering is off.');
            return self::SUCCESS;
        }

        $tables = collect(DB::select(
            "SELECT c.TABLE_NAME AS t FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES s ON s.TABLE_SCHEMA = c.TABLE_SCHEMA AND s.TABLE_NAME = c.TABLE_NAME AND s.TABLE_TYPE = 'BASE TABLE'
             WHERE c.TABLE_SCHEMA = ? AND c.COLUMN_NAME = 'clinic_id' AND c.TABLE_NAME <> 'clinic_usage_daily'",
            [DB::getDatabaseName()],
        ))->map(fn ($row) => (string) $row->t)->unique()->values();

        $rows = [];
        foreach ($tables as $table) {
            // Views and odd tables shouldn't stop the snapshot.
            try {
                $counts = DB::table($table)->whereNotNull('clinic_id')->groupBy('clinic_id')
                    ->selectRaw('clinic_id, COUNT(*) AS n')->pluck('n', 'clinic_id');
            } catch (\Throwable $e) {
                $this->warn("Skipped $table: ".$e->getMessage());
                continue;
            }
            foreach ($counts as $clinicId => $n) {
                $rows[(int) $clinicId] = ($rows[(int) $clinicId] ?? 0) + (int) $n;
            }
        }

        $clinicIds = DB::table('clinics')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $now = now();
        $records = collect($clinicIds)->map(fn ($id) => ['clinic_id' => $id, 'date' => $now->toDateString(),
            'requests' => 0, 'stored_rows' => $rows[$id] ?? 0, 'created_at' => $now, 'updated_at' => $now])->all();
        foreach (array_chunk($records, 200) as $chunk) {
            DB::table('clinic_usage_daily')->upsert($chunk, ['clinic_id', 'date'], ['stored_rows', 'updated_at']);
        }

        $this->info(sprintf('Counted %d tables for %d clinics.', $tables->count(), count($clinicIds)));

        return self::SUCCESS;
    }
}
