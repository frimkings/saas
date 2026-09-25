<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // login_logs: user_id + login_at composite for fast user history lookups
        $this->addIfMissing('login_logs', 'login_logs_user_login_at_index', function (Blueprint $t) {
            $t->index(['user_id', 'login_at'], 'login_logs_user_login_at_index');
        });

        // audit_trails: user_id for user-centric lookups; standalone created_at for pruning
        $this->addIfMissing('audit_trails', 'audit_trails_user_id_index', function (Blueprint $t) {
            $t->index('user_id', 'audit_trails_user_id_index');
        });
        $this->addIfMissing('audit_trails', 'audit_trails_created_at_index', function (Blueprint $t) {
            $t->index('created_at', 'audit_trails_created_at_index');
        });

        // payment_transactions: created_at for date-range reports; payment_method for groupBy;
        // composite for the daily cash summary query (WHERE created_at BETWEEN ... GROUP BY payment_method)
        $this->addIfMissing('payment_transactions', 'pt_created_at_index', function (Blueprint $t) {
            $t->index('created_at', 'pt_created_at_index');
        });
        $this->addIfMissing('payment_transactions', 'pt_payment_method_index', function (Blueprint $t) {
            $t->index('payment_method', 'pt_payment_method_index');
        });
        $this->addIfMissing('payment_transactions', 'pt_created_method_index', function (Blueprint $t) {
            $t->index(['created_at', 'payment_method'], 'pt_created_method_index');
        });

        // sale_items: composite for income statement and reports JOINs
        $this->addIfMissing('sale_items', 'sale_items_sale_product_index', function (Blueprint $t) {
            $t->index(['sale_id', 'product_id'], 'sale_items_sale_product_index');
        });

        // sales: payment_status + created_at for outstanding balances and filtered reports
        $this->addIfMissing('sales', 'sales_payment_status_created_index', function (Blueprint $t) {
            $t->index(['payment_status', 'created_at'], 'sales_payment_status_created_index');
        });

        // income_statement_entries: composite for is_active + entry_date filtering
        $this->addIfMissing('income_statement_entries', 'ise_active_date_index', function (Blueprint $t) {
            $t->index(['is_active', 'entry_date'], 'ise_active_date_index');
        });

        // expenses: composite for date-range + category filtering
        $this->addIfMissing('expenses', 'expenses_date_category_index', function (Blueprint $t) {
            $t->index(['expense_date', 'expense_category_id'], 'expenses_date_category_index');
        });

        // refund_logs: composite for pending-refunds dashboard query
        $this->addIfMissing('refund_logs', 'refund_logs_status_created_index', function (Blueprint $t) {
            $t->index(['status', 'created_at'], 'refund_logs_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('login_logs', fn (Blueprint $t) => $t->dropIndex('login_logs_user_login_at_index'));
        Schema::table('audit_trails', fn (Blueprint $t) => $t->dropIndex('audit_trails_user_id_index'));
        Schema::table('audit_trails', fn (Blueprint $t) => $t->dropIndex('audit_trails_created_at_index'));
        Schema::table('payment_transactions', fn (Blueprint $t) => $t->dropIndex('pt_created_at_index'));
        Schema::table('payment_transactions', fn (Blueprint $t) => $t->dropIndex('pt_payment_method_index'));
        Schema::table('payment_transactions', fn (Blueprint $t) => $t->dropIndex('pt_created_method_index'));
        Schema::table('sale_items', fn (Blueprint $t) => $t->dropIndex('sale_items_sale_product_index'));
        Schema::table('sales', fn (Blueprint $t) => $t->dropIndex('sales_payment_status_created_index'));
        Schema::table('income_statement_entries', fn (Blueprint $t) => $t->dropIndex('ise_active_date_index'));
        Schema::table('expenses', fn (Blueprint $t) => $t->dropIndex('expenses_date_category_index'));
        Schema::table('refund_logs', fn (Blueprint $t) => $t->dropIndex('refund_logs_status_created_index'));
    }

    private function addIfMissing(string $table, string $indexName, callable $callback): void
    {
        $exists = collect(Schema::getIndexes($table))
            ->pluck('name')
            ->contains($indexName);

        if (!$exists) {
            Schema::table($table, $callback);
        }
    }
};
