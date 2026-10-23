<?php

namespace App\Jobs;

use App\Models\ExternalImportBatch;
use App\Services\ExternalImport\CloudifyImport;
use App\Services\ExternalImport\ExternalImportRollback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Writes an analysed export into its clinic. Not retried: a failed run undoes what it wrote, and
 * the platform admin fixes the cause and imports again.
 */
class RunExternalImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(public int $batchId) {}

    public function handle(CloudifyImport $import, ExternalImportRollback $rollback): void
    {
        $batch = ExternalImportBatch::findOrFail($this->batchId);
        if ($batch->status !== 'queued') return;
        $batch->update(['status' => 'importing', 'started_at' => now(), 'error' => null, 'progress_percent' => 1, 'current_step' => 'starting']);

        try {
            $result = $import->commit($batch, fn (int $percent, string $step) => $batch->update(['progress_percent' => $percent, 'current_step' => $step]));
        } catch (Throwable $e) {
            report($e);
            $import->flush();
            $message = $e->getMessage();
            try {
                $rollback->undo($batch->fresh(), keepFiles: true);
                $message .= ' Everything this run had written was removed.';
            } catch (Throwable $undo) {
                report($undo);
                $message .= ' Some rows could not be removed automatically: press Undo import.';
            }
            $batch->update(['status' => 'failed', 'error' => mb_substr($message, 0, 2000), 'finished_at' => now(), 'rolled_back_at' => null]);

            return;
        }

        $batch->update(['status' => 'completed', 'result' => $result, 'progress_percent' => 100, 'current_step' => null, 'finished_at' => now()]);
        $rollback->deleteFiles($batch);
    }
}
