<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visual acuity is now taken three ways per eye: unaided (the existing vaOD6m / vaOS6m),
 * with a pinhole, and with the patient's own glasses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            if (! Schema::hasColumn('consultations', 'vaODph')) {
                $table->string('vaODph', 40)->nullable()->after('vaOS6m');
                $table->string('vaOSph', 40)->nullable()->after('vaODph');
                $table->string('vaODcc', 40)->nullable()->after('vaOSph');
                $table->string('vaOScc', 40)->nullable()->after('vaODcc');
            }
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn(['vaODph', 'vaOSph', 'vaODcc', 'vaOScc']);
        });
    }
};
