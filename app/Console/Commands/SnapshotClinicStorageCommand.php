<?php

namespace App\Console\Commands;

use App\Support\Usage\UsageMeter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

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

        $clinics = DB::table('clinics')->get(['id', 'uuid']);
        $files = $this->fileBytes($clinics);
        $now = now();
        $records = $clinics->map(fn ($clinic) => ['clinic_id' => (int) $clinic->id, 'date' => $now->toDateString(),
            'requests' => 0, 'stored_rows' => $rows[(int) $clinic->id] ?? 0, 'file_bytes' => $files[(int) $clinic->id] ?? null,
            'created_at' => $now, 'updated_at' => $now])->all();
        foreach (array_chunk($records, 200) as $chunk) {
            DB::table('clinic_usage_daily')->upsert($chunk, ['clinic_id', 'date'], ['stored_rows', 'file_bytes', 'updated_at']);
        }
        $clinicIds = $clinics->pluck('id')->all();

        $this->info(sprintf('Counted %d tables for %d clinics.', $tables->count(), count($clinicIds)));

        return self::SUCCESS;
    }

    /**
     * Bytes of uploaded files per clinic: everything under clinics/{uuid}/ (TenantStorage) on the
     * public disk (logos, receipts) and the local disk (patient documents). One listing per clinic
     * and disk; sizes come with the listing, so S3 needs no request per file. Null when unreadable.
     */
    private function fileBytes($clinics): array
    {
        $bytes = [];
        foreach (['public', 'local'] as $diskName) {
            // A disk that can't be listed (e.g. no bucket configured offline) is skipped as a whole.
            try {
                $driver = Storage::disk($diskName)->getDriver();
                foreach ($clinics as $clinic) {
                    if (! $clinic->uuid) continue;
                    $total = 0;
                    foreach ($driver->listContents('clinics/'.$clinic->uuid, true) as $item) {
                        if ($item instanceof FileAttributes) $total += (int) $item->fileSize();
                    }
                    $bytes[(int) $clinic->id] = ($bytes[(int) $clinic->id] ?? 0) + $total;
                }
            } catch (\Throwable $e) {
                $this->warn("Skipped files on the $diskName disk: ".$e->getMessage());
            }
        }

        return $bytes;
    }
}
