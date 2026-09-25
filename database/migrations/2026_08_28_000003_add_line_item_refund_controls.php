<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedInteger('refunded_quantity')->default(0)->after('dispensed_quantity');
        });
        Schema::table('refund_logs', function (Blueprint $table) {
            $table->json('sale_item_ids')->nullable()->after('sale_id');
        });
    }

    public function down(): void
    {
        Schema::table('refund_logs', fn (Blueprint $table) => $table->dropColumn('sale_item_ids'));
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn('refunded_quantity'));
    }
};
