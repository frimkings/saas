<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->decimal('lab_cost', 12, 2)->default(0)->after('lens_price');
            $table->timestamp('stock_reserved_at')->nullable()->after('lab_cost');
            $table->timestamp('cancelled_at')->nullable()->after('collected_at');
        });
    }
    public function down(): void
    {
        Schema::table('lens_orders', fn (Blueprint $table) => $table->dropColumn(['lab_cost', 'stock_reserved_at', 'cancelled_at']));
    }
};
