<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clock-in emails: the owner ticks a box and is told when staff clock in or out (each punch, or one
 * summary in the evening), optionally only when someone is late or leaves early, with extra
 * addresses if they wish; staff can be sent their own times too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->boolean('punch_emails')->default(false);
            $table->date('punch_emails_since')->nullable();
            $table->string('punch_email_mode', 10)->default('each');
            $table->string('punch_email_when', 12)->default('all');
            $table->json('punch_email_to')->nullable();
            $table->boolean('punch_email_staff')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn(['punch_emails', 'punch_emails_since', 'punch_email_mode', 'punch_email_when', 'punch_email_to', 'punch_email_staff']);
        });
    }
};
