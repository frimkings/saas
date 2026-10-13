<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rostering a ward: saved rotation patterns to fill the roster from, a note on a roster day
     * (who it was swapped with), and the branch's rest rules the roster warns about.
     */
    public function up(): void
    {
        Schema::create('attendance_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // One entry per day of the cycle: a shift id, or 0 for a day off.
            $table->json('days');
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });

        Schema::table('attendance_roster', function (Blueprint $table) {
            $table->string('note')->nullable()->after('attendance_shift_id');
        });

        Schema::table('attendance_settings', function (Blueprint $table) {
            // Null = not checked.
            $table->unsignedTinyInteger('min_rest_hours')->nullable()->after('overtime_needs_approval');
            $table->unsignedTinyInteger('max_nights_in_row')->nullable()->after('min_rest_hours');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', fn (Blueprint $table) => $table->dropColumn(['min_rest_hours', 'max_nights_in_row']));
        Schema::table('attendance_roster', fn (Blueprint $table) => $table->dropColumn('note'));
        Schema::dropIfExists('attendance_patterns');
    }
};
