<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('doctor_id')->nullable()->after('patient_id')->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('duration_minutes')->default(30)->after('scheduled_at');
            $table->timestamp('arrived_at')->nullable()->after('missed_at');
            $table->timestamp('doctor_started_at')->nullable()->after('arrived_at');
            $table->timestamp('completed_at')->nullable()->after('doctor_started_at');
            $table->index(['doctor_id', 'scheduled_at']);
            $table->index(['patient_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['doctor_id']);
            $table->dropIndex(['doctor_id', 'scheduled_at']);
            $table->dropIndex(['patient_id', 'scheduled_at']);
            $table->dropColumn(['doctor_id', 'duration_minutes', 'arrived_at', 'doctor_started_at', 'completed_at']);
        });
    }
};
