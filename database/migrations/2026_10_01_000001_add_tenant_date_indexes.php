<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date filters always run inside one clinic and branch ("today's sales for this branch").
 * The existing date indexes don't start with the tenant columns, so MySQL either walks the
 * whole branch or every clinic's rows for the date. These lead with the tenant, then the date.
 */
return new class extends Migration
{
    private const INDEXES = [
        'sales' => ['clinic_id', 'branch_id', 'created_at'],
        'sale_items' => ['clinic_id', 'branch_id', 'created_at'],
        'consultations' => ['clinic_id', 'branch_id', 'created_at'],
        'appointments' => ['clinic_id', 'branch_id', 'scheduled_at'],
        'expenses' => ['clinic_id', 'branch_id', 'expense_date'],
        'cashier_patient_clearances' => ['clinic_id', 'branch_id', 'clearance_date'],
        'patients' => ['clinic_id', 'created_at'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            $name = $this->name($table, $columns);
            if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            $name = $this->name($table, $columns);
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }

    private function name(string $table, array $columns): string
    {
        return $table.'_tenant_'.end($columns).'_index';
    }
};
