<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selling price per pair for a stock lens range (range, design, index, coating, diameter),
 * with optional exceptions for higher powers. One lens sells at half the pair price.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('optical_lens_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->char('range_key', 64);
            $table->json('specs');
            $table->decimal('pair_price', 12, 2);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['clinic_id', 'range_key']);
        });

        Schema::create('optical_lens_price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('optical_lens_price_id')->constrained('optical_lens_prices')->cascadeOnDelete();
            // The rule applies when |SPH| and/or |CYL or ADD| reach these values.
            $table->decimal('min_sphere', 5, 2)->nullable();
            $table->decimal('min_power', 5, 2)->nullable();
            $table->decimal('pair_price', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optical_lens_price_rules');
        Schema::dropIfExists('optical_lens_prices');
    }
};
