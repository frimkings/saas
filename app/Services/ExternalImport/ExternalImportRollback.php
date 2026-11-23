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
        $unknown = array_diff($tables, [...self::ORDER, 'consultations.notes']);
        if ($unknown) throw new RuntimeException('This import created rows this undo does not know about ('.implode(', ', $unknown).').');
        $this->refuseIfLaterPartsBuildOnIt($batch);
        $this->refuseIfBalancesCollected($batch);

        $deleted = [];
        try {
            DB::transaction(function () use ($batch, $tables, &$deleted) {
                if (in_array('consultations.notes', $tables, true)) $deleted['spectacle order notes'] = $this->removeOrderNotes($batch);
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
     * A clinic copied in stages is imported in parts that link to each other (LISTS and ORDERS to the patients
     * and visits of the stages before), so parts are undone newest first.
     */
    private function refuseIfLaterPartsBuildOnIt(ExternalImportBatch $batch): void
    {
        $later = ExternalImportBatch::where('clinic_id', $batch->clinic_id)->where('source', $batch->source)->where('id', '>', $batch->id)
            ->where('status', 'completed')->orderByDesc('id')->first();
        if ($later) throw new RuntimeException('A later import for this clinic (#'.$later->id.', '.$later->created_at->format('d M Y H:i').') may link to the records this one brought in. Undo the imports newest first. Nothing was removed.');
    }

    /** Takes out the spectacle order notes an import added to visits it did not create (an earlier part's visits). */
    private function removeOrderNotes(ExternalImportBatch $batch): int
    {
        $removed = 0;
        DB::table('external_import_rows')->where('external_import_batch_id', $batch->id)->where('table_name', 'consultations.notes')
            ->orderBy('id')->chunkById(1000, function ($rows) use (&$removed) {
                foreach (DB::table('consultations')->whereIn('id', $rows->pluck('record_id'))->get(['id', 'notes']) as $visit) {
                    // Each note is one paragraph: "Spectacle order N in the previous software, date:" and its lines, no blank line inside.
                    $notes = preg_replace('/(?:\n\n)?Spectacle order [^\n]* in the previous software, [^\n]*:(?:\n(?!\n)[^\n]*)*/u', '', (string) $visit->notes, -1, $count);
                    if (! $count) continue;
                    DB::table('consultations')->where('id', $visit->id)->update(['notes' => trim($notes) === '' ? null : trim($notes)]);
                    $removed += $count;
                }
            });

        return $removed;
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
        if ($batch->stored_dir) \App\Support\ImportFiles::deleteDirectory($batch->stored_dir, $batch->files ?? []);
        $batch->update(['files' => collect($batch->files ?? [])->map(fn ($f) => array_diff_key($f, ['path' => 1]))->all(), 'stored_dir' => null]);
    }
}
