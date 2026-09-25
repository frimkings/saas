<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff IDs are numbered by each clinic: unique within a clinic, reusable across clinics.
 * (users.staff_id was platform-wide and is no longer written.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_user', function (Blueprint $table) {
            $table->unique(['clinic_id', 'staff_identifier'], 'clinic_user_clinic_staff_identifier_unique');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_user', function (Blueprint $table) {
            $table->dropUnique('clinic_user_clinic_staff_identifier_unique');
        });
    }
};
