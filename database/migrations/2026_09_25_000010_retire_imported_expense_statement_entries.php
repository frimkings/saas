<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The clinic income statement now reads the Expense Tracker directly. Lines copied in by
 * the old "import from Expense Tracker" step would count those expenses twice, so they are
 * switched off (kept, not deleted, so this can be undone).
 */
return new class extends Migration {
    private const IMPORTED = 'Imported from Expense Tracker for period %';

    public function up(): void
    {
        DB::table('income_statement_entries')
            ->where('business_line', 'clinic')->where('is_active', true)->whereNull('deleted_at')
            ->where('notes', 'like', self::IMPORTED)
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('income_statement_entries')
            ->where('business_line', 'clinic')->where('is_active', false)->whereNull('deleted_at')
            ->where('notes', 'like', self::IMPORTED)
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
