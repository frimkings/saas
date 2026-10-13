<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimum cover: how many of a department's people must be on each shift, so the roster can
     * show where a ward is short before the week starts and the live board when it is short now.
     */
    public function up(): void
    {
        Schema::create('attendance_cover', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_department_id')->constrained('attendance_departments')->cascadeOnDelete();
            $table->foreignId('attendance_shift_id')->constrained('attendance_shifts')->cascadeOnDelete();
            $table->unsignedSmallInteger('minimum');
            // On Saturdays and Sundays, when it differs; null = the same as other days.
            $table->unsignedSmallInteger('weekend_minimum')->nullable();
            $table->timestamps();
            $table->unique(['attendance_department_id', 'attendance_shift_id'], 'attendance_cover_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_cover');
    }
};
