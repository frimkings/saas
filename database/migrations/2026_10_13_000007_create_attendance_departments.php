<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Departments within a branch (Nursing, Laboratory, Pharmacy…), for hospitals and other larger
     * subscribers. Each can have heads, who see and run attendance for their own people only, and
     * rules of its own; whatever it leaves empty follows the branch. A branch with no departments
     * works exactly as before.
     */
    public function up(): void
    {
        Schema::create('attendance_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // User ids of the people who head it.
            $table->json('heads')->nullable();
            // Shaped like attendance_settings.hours; null = the branch's working hours.
            $table->json('hours')->nullable();
            $table->unsignedSmallInteger('grace_minutes')->nullable();
            $table->unsignedSmallInteger('break_minutes')->nullable();
            $table->unsignedSmallInteger('overtime_after_minutes')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });

        Schema::table('attendance_staff', function (Blueprint $table) {
            $table->foreignId('attendance_department_id')->nullable()->after('department')->constrained('attendance_departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_staff', fn (Blueprint $table) => $table->dropConstrainedForeignId('attendance_department_id'));
        Schema::dropIfExists('attendance_departments');
    }
};
