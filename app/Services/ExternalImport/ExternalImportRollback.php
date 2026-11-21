<?php

namespace App\Services\ExternalImport;

use App\Models\ExternalImportBatch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Undoes an import by deleting exactly the rows it listed in external_import_rows, children first.
 * All or nothing: if the clinic has since built on an imported record (a new visit for an imported
 * patient, a sale of an imported product), the foreign key stops the delete and nothing is removed.
 */
class ExternalImportRollback
{
    /** Children before parents. */
    private const ORDER = ['sales', 'patient_legacy_payments', 'legacy_expenses', 'patient_legacy_medications', 'consultation_diagnosis', 'refractions', 'consultations', 'cashier_patient_clearances',
        'appointments', 'expenses', 'expense_categories', 'products', 'categories', 'diagnoses', 'patients'];

    /** $keepFiles leaves the uploaded spreadsheets in place so a failed run can be tried again. */
    public function undo(ExternalImportBatch $batch, bool $keepFiles = false): array
    {
        $tables = DB::table('external_import_rows')->where('external_import_batch_id', $batch->id)->distinct()->pluck('table_name')->all();
        $unknown = array_diff($tables, self::ORDER);
        if ($unknown) throw new RuntimeException('This import created rows this undo does not know about ('.implode(', ', $unknown).').');
        $this->refuseIfBalancesCollected($batch);

        $deleted = [];
        try {
            DB::transaction(function () use ($batch, $tables, &$deleted) {
                foreach (array_intersect(self::ORDER, $tables) as $table) {
                    DB::table('external_import_rows')->where('external_import_batch_id', $batch->id)->where('table_name', $table)
                        ->orderBy('id')->chunkById(1000, function ($rows) use ($table, &$deleted) {
                            $deleted[$table] = ($deleted[$table] ?? 0) + DB::table($table)->whereIn('id', $rows->pluck('record_id'))->delete();
                        });
                }
                DB::table('external_import_rows')->where('external_import_batch_id', $batch->id)->delete();
            });
        } catch (QueryException $e) {
            if (substr((string) $e->getCode(), 0, 2) !== '23') throw $e;
            throw new RuntimeException('The clinic has already used some imported records (for example a new visit for an imported patient, or a sale of an imported product), so the import can no longer be undone. Nothing was removed.');
        }

        if (! $keepFiles) $this->deleteFiles($batch);
        $batch->update(['status' => 'rolled_back', 'rolled_back_at' => now(), 'progress_percent' => 0, 'current_step' => null]);

        return $deleted;
    }

    /**
     * Imported balances are sales, and a sale's payments are deleted with it (cascade), so the foreign keys would
     * not stop an undo once money was collected on one. Refuse here instead, before anything is removed.
     */
    private function refuseIfBalancesCollected(ExternalImportBatch $batch): void
    {
        $sales = DB::table('external_import_rows')->where('external_import_batch_id', $batch->id)->where('table_name', 'sales')->pluck('record_id');
        if ($sales->isEmpty()) return;
        $collected = DB::table('sales')->whereIn('id', $sales)->where('amount_paid', '>', 0)->exists()
            || DB::table('payment_transactions')->whereIn('sale_id', $sales)->exists();
        if ($collected) throw new RuntimeException('Money has already been collected on a balance this import brought in, so the import can no longer be undone. Nothing was removed.');
    }

    /** The uploaded spreadsheets hold patient data; they are kept only until the import is done or undone. */
    public function deleteFiles(ExternalImportBatch $batch): void
    {
        if ($batch->stored_dir) Storage::disk('local')->deleteDirectory($batch->stored_dir);
        $batch->update(['files' => collect($batch->files ?? [])->map(fn ($f) => array_diff_key($f, ['path' => 1]))->all(), 'stored_dir' => null]);
    }
}
