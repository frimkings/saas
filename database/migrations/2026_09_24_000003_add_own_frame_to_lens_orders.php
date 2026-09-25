<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The patient supplied the frame: no frame product, price or stock.
        // Its description is kept in frame_model_number.
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->boolean('own_frame')->default(false)->after('frame_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('lens_orders', fn (Blueprint $table) => $table->dropColumn('own_frame'));
    }
};
