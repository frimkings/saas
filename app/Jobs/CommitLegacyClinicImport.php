<?php

namespace App\Jobs;

use App\Models\LegacyImportBatch;
use App\Services\LegacyClinicImportService;
use App\Services\LegacyImportEvidenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Illuminate\Support\Str;

class CommitLegacyClinicImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 900;
    public array $backoff = [10, 30, 60];

    public function __construct(public int $batchId) {}

    public function handle(LegacyClinicImportService $service, LegacyImportEvidenceService $evidence): void
    {
        $batch = LegacyImportBatch::findOrFail($this->batchId);
        if ($batch->committed_at) { if (! $batch->evidence_archived_at) $evidence->archive($batch); return; }

        $options = $batch->result['import_options'] ?? null;
        if (! is_array($options)) throw new \RuntimeException('Saved import options are unavailable.');

        $batch->update([
            'status' => 'importing', 'attempts' => $batch->attempts + 1, 'started_at' => now(),
            'finished_at' => null, 'error' => null, 'progress_percent' => 5, 'current_table' => 'staging_restore',
            'resume_token' => $batch->resume_token ?: (string) Str::uuid(),
            'resume_checkpoint' => ['phase' => 'starting', 'table' => 'staging_restore', 'processed' => 0, 'total' => $batch->total_rows], 'checkpoint_at' => now(),
        ]);

        config(['database.connections.legacy_import_progress' => config('database.connections.'.config('database.default'))]);
        DB::purge('legacy_import_progress');

        try {
            $this->commitBatch($service, $batch, $options);
        } catch (Throwable $exception) {
            // Data errors (constraint violations, bad values) fail identically on every attempt, so stop instead of retrying.
            if ($exception instanceof QueryException && in_array(substr((string) $exception->getCode(), 0, 2), ['22', '23'], true)) {
                $this->fail($exception);
                return;
            }
            LegacyImportBatch::whereKey($this->batchId)->whereNull('committed_at')->update([
                'status' => 'queued', 'current_table' => 'retry_scheduled', 'error' => 'Attempt '.$this->attempts().' failed: '.$exception->getMessage(),
            ]);
            throw $exception;
        }

        LegacyImportBatch::whereKey($this->batchId)->update([
            'progress_percent' => 100, 'current_table' => 'complete', 'finished_at' => now(),
        ]);
        $evidence->archive(LegacyImportBatch::findOrFail($this->batchId));
    }

    private function commitBatch(LegacyClinicImportService $service, LegacyImportBatch $batch, array $options): void
    {
        $service->commit($batch, $options, function (int $percent, string $table, int $processed, int $total): void {
            DB::connection('legacy_import_progress')->table('legacy_import_batches')->where('id', $this->batchId)->update([
                'progress_percent' => max(0, min(99, $percent)), 'current_table' => $table,
                'processed_rows' => $processed, 'total_rows' => $total,
                'resume_checkpoint' => json_encode(['phase' => 'copying', 'table' => $table, 'processed' => $processed, 'total' => $total]), 'checkpoint_at' => now(),
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        LegacyImportBatch::whereKey($this->batchId)->whereNull('committed_at')->update([
            'status' => 'failed', 'current_table' => 'failed', 'finished_at' => now(),
            'error' => $exception?->getMessage() ?? 'The queued import failed.',
        ]);
    }
}
