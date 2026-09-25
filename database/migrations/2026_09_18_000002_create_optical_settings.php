<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('optical_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->unique()->constrained('clinics')->cascadeOnDelete();
            $table->unsignedTinyInteger('min_deposit_percentage')->default(0);
            $table->unsignedTinyInteger('warranty_months')->default(6);
            $table->text('optical_disclaimer')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('optical_settings'); }
};
