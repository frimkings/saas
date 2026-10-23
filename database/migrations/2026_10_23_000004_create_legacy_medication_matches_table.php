<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the clinic's products a drug name from its previous software stands for ("OPTILEX" → "Optilex eye drops"),
 * set once by the clinic so "Add again" in the Prescription tab works for every patient prescribed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_medication_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('name_key', 250);
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['clinic_id', 'name_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_medication_matches');
    }
};
