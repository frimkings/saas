<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named shifts (morning, afternoon, night…), a weekly pattern of them per person, and a
     * dated roster that overrides the pattern for single days. People without either keep the
     * branch's working hours, or their own.
     */
    public function up(): void
    {
        Schema::create('attendance_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // 'HH:MM' on the branch's clock; an end at or before the start is the next morning.
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            // Minutes out between punches before it is flagged; null = the branch's allowance.
            $table->unsignedSmallInteger('break_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });

        Schema::table('attendance_staff', function (Blueprint $table) {
            // ISO weekday => shift id, or 0 for a day off; a weekday left out follows the usual hours.
            $table->json('shift_week')->nullable()->after('hours');
        });

        Schema::create('attendance_roster', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_staff_id')->constrained('attendance_staff')->cascadeOnDelete();
            $table->date('date');
            // null = a day off on that date.
            $table->foreignId('attendance_shift_id')->nullable()->constrained('attendance_shifts')->cascadeOnDelete();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['attendance_staff_id', 'date']);
            $table->index(['branch_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_roster');
        Schema::table('attendance_staff', fn (Blueprint $table) => $table->dropColumn('shift_week'));
        Schema::dropIfExists('attendance_shifts');
    }
};
