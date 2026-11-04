<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A change to a saved refraction after its author's first 24 hours, or by someone other than its author:
 * who, when, why, and the values before and after (only the fields that changed). The refraction keeps
 * its author; this is its history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refraction_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('refraction_id')->constrained('refractions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->json('old_values');
            $table->json('new_values');
            $table->timestamps();
            $table->index(['clinic_id', 'refraction_id', 'created_at'], 'refraction_corrections_refraction_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refraction_corrections');
    }
};
