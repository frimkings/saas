<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient vitals (23 Nov 2026): BP, blood sugar and IOP, taken at check-in by reception or a nurse
 * and seen (and editable) by the doctor in the consultation. One row per reading so they trend over
 * time, like the IOP/VA charts. Tied to the visit (clearance) when there is one.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('vitals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('patient_id')->index();
            $table->unsignedBigInteger('clearance_id')->nullable()->index();
            $table->unsignedBigInteger('recorded_by')->nullable();
            // Blood pressure (mmHg).
            $table->unsignedSmallInteger('bp_systolic')->nullable();
            $table->unsignedSmallInteger('bp_diastolic')->nullable();
            // Blood sugar: value in the chosen unit, random or fasting.
            $table->decimal('blood_sugar', 6, 2)->nullable();
            $table->string('blood_sugar_type', 10)->nullable();   // random | fasting
            $table->string('blood_sugar_unit', 10)->default('mg/dL'); // mg/dL | mmol/L
            // Intraocular pressure (mmHg), per eye — a technician often takes it before the doctor.
            $table->decimal('iop_od', 5, 1)->nullable();
            $table->decimal('iop_os', 5, 1)->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'recorded_at']);
            $table->index(['clinic_id', 'branch_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vitals');
    }
};
