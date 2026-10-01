<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Balance reminders (counted per debt, so each clinic sale or optical order stops after the
 * clinic's maximum) and feedback requests (sent once per consultation or collection).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedTinyInteger('balance_reminders_sent')->default(0);
            $table->timestamp('balance_reminded_at')->nullable();
        });

        Schema::table('lens_orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('balance_reminders_sent')->default(0);
            $table->timestamp('balance_reminded_at')->nullable();
            $table->timestamp('feedback_requested_at')->nullable();
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->timestamp('feedback_requested_at')->nullable();
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('balance_reminder_first_days')->default(3);
            $table->unsignedSmallInteger('balance_reminder_every_days')->default(7);
            $table->unsignedTinyInteger('balance_reminder_max')->default(3);
            $table->string('review_link', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn(['balance_reminder_first_days', 'balance_reminder_every_days', 'balance_reminder_max', 'review_link']));
        Schema::table('consultations', fn (Blueprint $table) => $table->dropColumn('feedback_requested_at'));
        Schema::table('lens_orders', fn (Blueprint $table) => $table->dropColumn(['balance_reminders_sent', 'balance_reminded_at', 'feedback_requested_at']));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn(['balance_reminders_sent', 'balance_reminded_at']));
    }
};
