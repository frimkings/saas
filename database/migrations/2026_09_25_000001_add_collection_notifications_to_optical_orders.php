<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            // When the glasses became ready, and how the customer has been told.
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('ready_notified_at')->nullable();
            $table->unsignedTinyInteger('collection_reminders_sent')->default(0);
            $table->timestamp('last_collection_reminder_at')->nullable();
        });

        Schema::table('optical_settings', function (Blueprint $table) {
            $table->boolean('ready_sms_auto')->default(true);
            // 0 turns automatic pickup reminders off.
            $table->unsignedSmallInteger('collection_reminder_days')->default(7);
            $table->unsignedTinyInteger('collection_reminder_max')->default(3);
        });
    }

    public function down(): void
    {
        Schema::table('optical_settings', function (Blueprint $table) {
            $table->dropColumn(['ready_sms_auto', 'collection_reminder_days', 'collection_reminder_max']);
        });
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropColumn(['ready_at', 'ready_notified_at', 'collection_reminders_sent', 'last_collection_reminder_at']);
        });
    }
};
