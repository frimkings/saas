<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $branchTables = [
        'sales', 'sale_items', 'payment_transactions', 'sale_adjustments',
        'refund_logs', 'discount_approval_requests', 'clearance_revoke_logs',
        'expenses', 'income_statement_entries', 'income_statement_period_locks',
        'insurance_claims', 'quotations', 'quotation_items', 'carts', 'orders',
    ];

    private array $clinicTables = ['expense_categories', 'income_statement_templates'];

    public function up(): void
    {
        $clinicId = DB::connection()->pretending()
            ? 1
            : (DB::table('clinics')->where('status', 'active')->orderBy('id')->value('id')
                ?? DB::table('clinics')->orderBy('id')->value('id'));
        $branchId = DB::connection()->pretending()
            ? 1
            : DB::table('branches')->where('clinic_id', $clinicId)->orderByDesc('is_default')->orderBy('id')->value('id');

        if (! $clinicId || ! $branchId) {
            throw new \RuntimeException('A clinic and default branch are required before financial workflows can be backfilled.');
        }

        foreach ($this->branchTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('clinic_id')->nullable()->after('id')->constrained('clinics')->restrictOnDelete();
                $table->foreignId('branch_id')->nullable()->after('clinic_id')->constrained('branches')->restrictOnDelete();
            });
            DB::table($tableName)->whereNull('clinic_id')->update(['clinic_id' => $clinicId, 'branch_id' => $branchId]);
            Schema::table($tableName, fn (Blueprint $table) => $table->index(['clinic_id', 'branch_id']));
        }

        foreach ($this->clinicTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('clinic_id')->nullable()->after('id')->constrained('clinics')->restrictOnDelete();
            });
            DB::table($tableName)->whereNull('clinic_id')->update(['clinic_id' => $clinicId]);
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_transaction_id_unique');
            $table->unique(['clinic_id', 'transaction_id']);
            $table->dropUnique('sales_idempotency_key_unique');
            $table->unique(['clinic_id', 'idempotency_key']);
        });
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropUnique('payment_transactions_idempotency_key_unique');
            $table->unique(['clinic_id', 'idempotency_key']);
        });
        Schema::table('refund_logs', function (Blueprint $table) {
            $table->dropUnique('refund_logs_refund_number_unique');
            $table->unique(['clinic_id', 'refund_number']);
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropUnique('quotations_quotation_number_unique');
            $table->unique(['clinic_id', 'quotation_number']);
        });
        Schema::table('income_statement_period_locks', function (Blueprint $table) {
            $table->dropUnique('income_statement_period_locks_from_date_to_date_unique');
            $table->unique(['clinic_id', 'branch_id', 'from_date', 'to_date'], 'income_locks_tenant_period_unique');
        });
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropUnique('expense_categories_name_unique');
            $table->unique(['clinic_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropUnique(['clinic_id', 'name']);
            $table->unique('name');
        });
        Schema::table('income_statement_period_locks', function (Blueprint $table) {
            $table->dropUnique('income_locks_tenant_period_unique');
            $table->unique(['from_date', 'to_date']);
        });
        foreach ([
            ['quotations', 'quotation_number'],
            ['refund_logs', 'refund_number'],
            ['payment_transactions', 'idempotency_key'],
        ] as [$tableName, $column]) {
            Schema::table($tableName, function (Blueprint $table) use ($column) {
                $table->dropUnique(['clinic_id', $column]);
                $table->unique($column);
            });
        }
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['clinic_id', 'idempotency_key']);
            $table->unique('idempotency_key');
            $table->dropUnique(['clinic_id', 'transaction_id']);
            $table->unique('transaction_id');
        });

        foreach (array_reverse($this->clinicTables) as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropConstrainedForeignId('clinic_id'));
        }
        foreach (array_reverse($this->branchTables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['clinic_id', 'branch_id']);
                $table->dropConstrainedForeignId('branch_id');
                $table->dropConstrainedForeignId('clinic_id');
            });
        }
    }
};
