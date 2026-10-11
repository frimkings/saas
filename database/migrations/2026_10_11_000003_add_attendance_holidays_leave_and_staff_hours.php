<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Days nobody is expected in (holidays), days one person is excused (leave), and hours of their own for staff who don't work the branch's. */
    public function up(): void
    {
        Schema::table('attendance_staff', function (Blueprint $table) {
            // Same shape as attendance_settings.hours; null = the branch's working hours.
            $table->json('hours')->nullable()->after('starts_on');
        });

        Schema::create('attendance_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name', 100);
            $table->timestamps();
            $table->unique(['branch_id', 'date']);
        });

        Schema::create('attendance_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_staff_id')->constrained('attendance_staff')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('type', 20);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['attendance_staff_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_leaves');
        Schema::dropIfExists('attendance_holidays');
        Schema::table('attendance_staff', fn (Blueprint $table) => $table->dropColumn('hours'));
    }
};
