<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('business_line', 16)->default('clinic')->index();
        });

        // Historical optical orders are authoritative even when their sale has no product lines.
        DB::table('sales')->whereIn('id', DB::table('lens_orders')->whereNotNull('sale_id')->select('sale_id'))
            ->update(['business_line' => 'optical']);
        DB::table('sales')->where('transaction_id', 'like', 'OPOS-%')
            ->orWhere('transaction_id', 'like', 'OPT-%')
            ->update(['business_line' => 'optical']);
        DB::table('sales')->whereIn('id', DB::table('sale_items')->whereNotNull('optical_product_id')->select('sale_id'))
            ->update(['business_line' => 'optical']);
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('business_line'));
    }
};
