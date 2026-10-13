<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform → Clinic Usage, more of what each clinic costs: scheduled work run for it (SMS
 * reminders, receipts…), its uploaded files, and how many staff used the system each day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_usage_daily', function (Blueprint $table) {
            $table->unsignedInteger('job_runs')->default(0)->after('db_queries');
            $table->unsignedBigInteger('job_ms')->default(0)->after('job_runs');
            $table->unsignedBigInteger('job_db_ms')->default(0)->after('job_ms');
            $table->unsignedInteger('active_staff')->default(0)->after('job_db_ms');
            $table->unsignedBigInteger('file_bytes')->nullable()->after('stored_rows');
        });

        // One row per staff member per clinic per day they opened a page; keeps active_staff exact.
        Schema::create('clinic_usage_users', function (Blueprint $table) {
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['clinic_id', 'date', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_usage_users');
        Schema::table('clinic_usage_daily', function (Blueprint $table) {
            $table->dropColumn(['job_runs', 'job_ms', 'job_db_ms', 'active_staff', 'file_bytes']);
        });
    }
};
