<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses from the clinic's previous software are history, like its payments: they go to
 * legacy_expenses and stay out of the Expense Tracker, the income statement and the dashboard.
 * Imports that already wrote to expenses are moved across (kept, not deleted), and the import's
 * undo list is pointed at the new rows. Expense categories an import created and nothing else
 * uses go with them.
 */
return new class extends Migration
{
    private const MARKER = "\nImported from Previous Software.";

    public function up(): void
    {
        Schema::create('legacy_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('external_import_batch_id')->nullable()->constrained('external_import_batches')->nullOnDelete();
            $table->string('source', 30);
            $table->date('expense_date');
            $table->string('category', 250)->nullable();
            $table->string('description', 250);
            $table->decimal('amount', 12, 2);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('recorded_by', 150)->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'expense_date']);
        });

        $this->moveImportedExpenses();
    }

    /** Moves expenses that imports wrote to the Expense Tracker into legacy_expenses (separate so tests can run it). */
    public function moveImportedExpenses(): void
    {
        if (! Schema::hasTable('external_import_rows')) return;

        DB::transaction(function () {
            // Each pass relabels the rows it moved, so the next pass simply takes the next 500 still listed as expenses.
            while (($rows = DB::table('external_import_rows as r')
                ->join('external_import_batches as b', 'b.id', '=', 'r.external_import_batch_id')
                ->where('r.table_name', 'expenses')
                ->select('r.id as row_id', 'r.record_id', 'b.id as batch_id', 'b.source')
                ->orderBy('r.id')->limit(500)->get())->isNotEmpty()) {
                $expenses = DB::table('expenses as e')
                    ->leftJoin('expense_categories as c', 'c.id', '=', 'e.expense_category_id')
                    ->leftJoin('users as u', 'u.id', '=', 'e.recorded_by')
                    ->whereIn('e.id', $rows->pluck('record_id'))
                    ->select('e.*', 'c.name as category_name', 'u.name as recorder_name')
                    ->get()->keyBy('id');

                foreach ($rows as $row) {
                    $expense = $expenses[$row->record_id] ?? null;
                    // Already gone from expenses: nothing to keep, and undo has nothing to remove.
                    if (! $expense) { DB::table('external_import_rows')->where('id', $row->row_id)->delete(); continue; }
                    $notes = trim(str_replace(self::MARKER, '', (string) $expense->notes));
                    $id = DB::table('legacy_expenses')->insertGetId([
                        'clinic_id' => $expense->clinic_id, 'branch_id' => $expense->branch_id, 'external_import_batch_id' => $row->batch_id,
                        'source' => $row->source, 'expense_date' => $expense->expense_date, 'category' => $expense->category_name,
                        'description' => $expense->description, 'amount' => $expense->amount, 'reference' => $expense->reference,
                        'notes' => $notes === '' ? null : $notes, 'recorded_by' => $expense->recorder_name,
                        'created_at' => $expense->created_at, 'updated_at' => now(),
                    ]);
                    DB::table('external_import_rows')->where('id', $row->row_id)->update(['table_name' => 'legacy_expenses', 'record_id' => $id]);
                    DB::table('expenses')->where('id', $expense->id)->delete();
                }
            }

            // Categories an import added that no expense or repeating expense uses any more.
            $created = DB::table('external_import_rows')->where('table_name', 'expense_categories')->pluck('record_id', 'id');
            foreach ($created as $rowId => $categoryId) {
                $used = DB::table('expenses')->where('expense_category_id', $categoryId)->exists()
                    || (Schema::hasTable('recurring_expenses') && DB::table('recurring_expenses')->where('expense_category_id', $categoryId)->exists());
                if ($used) continue;
                DB::table('expense_categories')->where('id', $categoryId)->delete();
                DB::table('external_import_rows')->where('id', $rowId)->delete();
            }
        });
    }

    /** Puts imported expenses back in the Expense Tracker (under their category names) before dropping the table. */
    public function down(): void
    {
        if (Schema::hasTable('external_import_rows')) {
            DB::transaction(function () {
                $categories = [];
                DB::table('legacy_expenses')->orderBy('id')->chunk(500, function ($rows) use (&$categories) {
                    foreach ($rows as $row) {
                        $categoryId = null;
                        if ($row->category) {
                            $key = $row->clinic_id.'|'.mb_strtolower(trim($row->category));
                            $categoryId = $categories[$key] ??= DB::table('expense_categories')->where('clinic_id', $row->clinic_id)->where('name', $row->category)->value('id')
                                ?? $this->restoreCategory($row);
                        }
                        $id = DB::table('expenses')->insertGetId([
                            'clinic_id' => $row->clinic_id, 'branch_id' => $row->branch_id, 'expense_category_id' => $categoryId, 'expense_date' => $row->expense_date,
                            'description' => $row->description, 'amount' => $row->amount, 'reference' => $row->reference,
                            'notes' => trim(($row->notes ?? '').self::MARKER),
                            'recorded_by' => DB::table('clinic_user')->where('clinic_id', $row->clinic_id)->value('user_id'),
                            'created_at' => $row->created_at, 'updated_at' => now(),
                        ]);
                        DB::table('external_import_rows')->where('table_name', 'legacy_expenses')->where('record_id', $row->id)->update(['table_name' => 'expenses', 'record_id' => $id]);
                    }
                });
            });
        }

        Schema::dropIfExists('legacy_expenses');
    }

    private function restoreCategory(object $row): int
    {
        $id = (int) DB::table('expense_categories')->insertGetId([
            'clinic_id' => $row->clinic_id, 'name' => $row->category, 'section' => 'operating_expense', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($row->external_import_batch_id) {
            DB::table('external_import_rows')->insert(['external_import_batch_id' => $row->external_import_batch_id, 'table_name' => 'expense_categories', 'record_id' => $id]);
        }

        return $id;
    }
};
