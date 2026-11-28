<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Emails to staff become the owner's own switch, apart from the owner's clock-in emails: each person
 * told of their clock-in and clock-out, and (new) reminded to clock out before their shift ends.
 * Until now staff were emailed only while the owner's clock-in emails were on as well, so a branch
 * keeps exactly what it had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->boolean('clock_out_reminder')->default(false);
        });
        DB::table('attendance_settings')->where('punch_emails', false)->update(['punch_email_staff' => false]);
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn('clock_out_reminder');
        });
    }
};
