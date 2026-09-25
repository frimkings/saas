<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock that moved (sales, glazing, receipts) between the start of a count and the moment an
 * item's quantity was entered. The count is compared with what the system expected at that
 * moment, so a sale made during the count is not taken off twice.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('optical_stock_count_lines', fn (Blueprint $table) => $table->integer('moved_before_count')->default(0)->after('counted_quantity'));
    }

    public function down(): void
    {
        Schema::table('optical_stock_count_lines', fn (Blueprint $table) => $table->dropColumn('moved_before_count'));
    }
};
