<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('optical_product_stocks', function (Blueprint $table) {
            $table->unsignedInteger('lens_reorder_pairs')->nullable();
            $table->unsignedInteger('lens_target_pairs')->nullable();
        });
    }
    public function down(): void {
        Schema::table('optical_product_stocks', fn (Blueprint $table) => $table->dropColumn(['lens_reorder_pairs', 'lens_target_pairs']));
    }
};
