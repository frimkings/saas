<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Pickup reminders go out on listed days after the glasses were ready, e.g. [3, 10, 30].
        Schema::table('optical_settings', function (Blueprint $table) {
            $table->json('collection_reminder_schedule')->nullable();
        });
        foreach (DB::table('optical_settings')->get() as $row) {
            $days = (int) $row->collection_reminder_days;
            $max = (int) $row->collection_reminder_max;
            $schedule = $days > 0 && $max > 0 ? array_map(fn ($step) => $days * $step, range(1, min($max, 5))) : [];
            DB::table('optical_settings')->where('id', $row->id)->update(['collection_reminder_schedule' => json_encode($schedule)]);
        }
        Schema::table('optical_settings', function (Blueprint $table) {
            $table->dropColumn(['collection_reminder_days', 'collection_reminder_max']);
        });

        // Jobs from a partner clinic are reported to the partner, never to its patient.
        Schema::table('optical_partner_clinics', function (Blueprint $table) {
            $table->string('notification_phone', 50)->nullable();
            $table->string('notify_via', 10)->default('sms');
        });
    }

    public function down(): void
    {
        Schema::table('optical_partner_clinics', function (Blueprint $table) {
            $table->dropColumn(['notification_phone', 'notify_via']);
        });
        Schema::table('optical_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('collection_reminder_days')->default(7);
            $table->unsignedTinyInteger('collection_reminder_max')->default(3);
        });
        foreach (DB::table('optical_settings')->get() as $row) {
            $schedule = json_decode((string) $row->collection_reminder_schedule, true) ?: [];
            DB::table('optical_settings')->where('id', $row->id)->update([
                'collection_reminder_days' => $schedule[0] ?? 0, 'collection_reminder_max' => count($schedule),
            ]);
        }
        Schema::table('optical_settings', function (Blueprint $table) {
            $table->dropColumn('collection_reminder_schedule');
        });
    }
};
