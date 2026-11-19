<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOADTEST-20 (20 clinics, a year of data): the money screens (cash book, day close, optical
 * reports and profit & loss) filter payments by branch and date or method, and optical totals
 * filter sales by business line; the optical dashboard counts orders by date and status. None
 * of these had an index leading with the tenant. Measured 15-26% faster on those screens.
 */
return new class extends Migration
{
    private const INDEXES = [
        'payment_transactions_tenant_method_index' => ['payment_transactions', ['clinic_id', 'branch_id', 'payment_method']],
        'payment_transactions_tenant_created_at_index' => ['payment_transactions', ['clinic_id', 'branch_id', 'created_at']],
        'sales_tenant_line_created_index' => ['sales', ['clinic_id', 'branch_id', 'business_line', 'created_at']],
        'lens_orders_tenant_created_at_index' => ['lens_orders', ['clinic_id', 'branch_id', 'created_at']],
        'lens_orders_tenant_status_index' => ['lens_orders', ['clinic_id', 'branch_id', 'status']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $columns]) {
            if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => [$table]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }
};
