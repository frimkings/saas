<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('optical_products', function (Blueprint $table) {
            $table->json('lens_specs')->nullable();
            $table->string('lens_key', 64)->nullable();
            $table->unique(['clinic_id', 'lens_key']);
        });
        Schema::table('optical_lens_blanks', function (Blueprint $table) {
            $table->foreignId('optical_product_id')->nullable()->constrained('optical_products')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('optical_products', function (Blueprint $table) {
            $table->dropUnique(['clinic_id', 'lens_key']);
            $table->dropColumn(['lens_specs', 'lens_key']);
        });
    }
};
