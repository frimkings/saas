<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            // A remake is a new order that re-does the lenses of an earlier one.
            $table->foreignId('remake_of_id')->nullable()->constrained('lens_orders')->nullOnDelete();
            $table->string('remake_reason', 40)->nullable();
            $table->string('remake_charge', 10)->nullable();
            // Set when the glasses are collected, from the optical warranty setting.
            $table->date('warranty_expires_at')->nullable();
            // Amount kept when a paid order is refunded and cancelled.
            $table->decimal('cancellation_fee', 12, 2)->default(0);
            $table->foreignId('refund_log_id')->nullable()->constrained('refund_logs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_log_id');
            $table->dropConstrainedForeignId('remake_of_id');
            $table->dropColumn(['remake_reason', 'remake_charge', 'warranty_expires_at', 'cancellation_fee']);
        });
    }
};
