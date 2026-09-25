<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('sales', 'idempotency_key')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->uuid('idempotency_key')->nullable()->unique()->after('transaction_id');
            });
        }

        if (!Schema::hasColumn('payment_transactions', 'idempotency_key')) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->uuid('idempotency_key')->nullable()->unique()->after('sale_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_transactions', 'idempotency_key')) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->dropUnique(['idempotency_key']);
                $table->dropColumn('idempotency_key');
            });
        }

        if (Schema::hasColumn('sales', 'idempotency_key')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropUnique(['idempotency_key']);
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
