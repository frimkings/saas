<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Overtime: time on site past the end of the working day (or on a day off) once it reaches
     * the branch's threshold. Where the branch asks for approval, it counts only when a manager
     * approves it, for the minutes they approve.
     */
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('overtime_after_minutes')->default(30)->after('break_minutes');
            $table->boolean('overtime_needs_approval')->default(true)->after('overtime_after_minutes');
        });

        Schema::create('attendance_overtime', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_staff_id')->constrained('attendance_staff')->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 12); // approved | declined
            // The minutes approved; never more than were worked when it was decided.
            $table->unsignedSmallInteger('minutes')->default(0);
            $table->string('note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['attendance_staff_id', 'date']);
            $table->index(['branch_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_overtime');
        Schema::table('attendance_settings', fn (Blueprint $table) => $table->dropColumn(['overtime_after_minutes', 'overtime_needs_approval']));
    }
};
