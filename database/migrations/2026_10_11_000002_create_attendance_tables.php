<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff attendance from a branch's terminal (App\Services\Attendance): the people enrolled on
     * it, each time they punched, the report files those came from and the branch's working hours.
     * Times are the branch's own clock time, as the terminal shows them.
     */
    public function up(): void
    {
        Schema::create('attendance_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            // The Employee ID on the terminal, kept as text ("001").
            $table->string('employee_no', 32);
            $table->string('name');
            $table->string('department')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Off for people enrolled on the terminal whose attendance is not followed (the owner, say).
            $table->boolean('is_tracked')->default(true);
            // Days before this are not counted as absences (someone who joined part-way through).
            $table->date('starts_on')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'employee_no']);
        });

        Schema::create('attendance_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('file_name');
            $table->date('period_start');
            $table->date('period_end');
            // When the terminal made the report: days after it are not yet known, not absences.
            $table->dateTime('exported_at');
            $table->unsignedInteger('staff_count')->default(0);
            $table->unsignedInteger('new_punches')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'period_start', 'period_end']);
        });

        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_staff_id')->constrained('attendance_staff')->cascadeOnDelete();
            $table->dateTime('punched_at');
            $table->foreignId('attendance_import_id')->nullable()->constrained()->nullOnDelete();
            // Uploading the same month again adds only what is new.
            $table->unique(['attendance_staff_id', 'punched_at']);
            $table->index(['branch_id', 'punched_at']);
        });

        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            // Working hours by weekday (1 = Monday … 7 = Sunday): {"1": {"start": "08:00", "end": "17:00"}}; a day left out is a day off.
            $table->json('hours');
            $table->unsignedSmallInteger('grace_minutes')->default(15);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_settings');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_imports');
        Schema::dropIfExists('attendance_staff');
    }
};
