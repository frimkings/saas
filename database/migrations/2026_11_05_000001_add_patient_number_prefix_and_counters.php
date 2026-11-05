<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short patient numbers: clinic initials, year and month, then that month's count (VS-2611-042).
 * Existing patients keep the numbers they have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->string('patient_prefix', 3)->nullable()->after('slug');
        });

        // One row per clinic per month; locked while a number is handed out, so two
        // receptionists registering at once never get the same one.
        Schema::create('patient_number_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id')->default(0); // 0: installs without clinics
            $table->char('period', 4); // YYMM
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['clinic_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_number_counters');
        Schema::table('clinics', function (Blueprint $table) {
            $table->dropColumn('patient_prefix');
        });
    }
};
