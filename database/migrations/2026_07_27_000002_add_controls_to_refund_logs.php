<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('refund_logs', function (Blueprint $table) {
            $table->string('refund_number', 40)->nullable()->unique()->after('sale_id');
            $table->string('request_type', 20)->default('refund')->after('refund_number');
            $table->string('reason_code', 50)->nullable()->after('request_type');
            $table->decimal('refunded_amount', 12, 2)->nullable()->after('reason');
            $table->json('original_payment_references')->nullable()->after('refunded_amount');
            $table->json('stock_restoration')->nullable()->after('original_payment_references');
        });
    }

    public function down(): void
    {
        Schema::table('refund_logs', function (Blueprint $table) {
            $table->dropUnique(['refund_number']);
            $table->dropColumn([
                'refund_number',
                'request_type',
                'reason_code',
                'refunded_amount',
                'original_payment_references',
                'stock_restoration',
            ]);
        });
    }
};
