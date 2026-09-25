<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Last ledger movement when the count started: later movements are "moved during the count".
        Schema::table('optical_stock_counts', function (Blueprint $table) {
            $table->unsignedBigInteger('ledger_position')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('optical_stock_counts', function (Blueprint $table) {
            $table->dropColumn('ledger_position');
        });
    }
};
