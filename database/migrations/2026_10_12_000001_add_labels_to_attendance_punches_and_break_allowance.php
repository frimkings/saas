<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the terminal said about a punch, kept for the record: its own check-in/check-out label
     * (the app works in and out from the order of punches, not from this) and how the person was
     * recognised. And how long staff may be out between punches before the break is flagged.
     */
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->string('terminal_status', 20)->nullable()->after('punched_at');
            $table->string('method', 20)->nullable()->after('terminal_status');
        });

        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('break_minutes')->default(60)->after('grace_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', fn (Blueprint $table) => $table->dropColumn('break_minutes'));
        Schema::table('attendance_punches', fn (Blueprint $table) => $table->dropColumn(['terminal_status', 'method']));
    }
};
