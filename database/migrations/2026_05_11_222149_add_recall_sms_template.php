<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::table('sms_templates')->insertOrIgnore([
            'key'          => 'patient_recall',
            'label'        => 'Patient Recall',
            'message'      => 'Hello [NAME], it\'s been a while since your last visit to [CLINIC]. Your eyes deserve regular care — book your next check-up today. Call us anytime!',
            'placeholders' => json_encode(['[NAME]', '[CLINIC]']),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('sms_templates')->insertOrIgnore([
            'key'          => 'appointment_auto_reminder',
            'label'        => 'Appointment Auto-Reminder',
            'message'      => 'Hello [NAME], this is a reminder of your appointment at [CLINIC] tomorrow, [DATE] at [TIME]. Please call us if you need to reschedule.',
            'placeholders' => json_encode(['[NAME]', '[CLINIC]', '[DATE]', '[TIME]']),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function down()
    {
        DB::table('sms_templates')->whereIn('key', ['patient_recall', 'appointment_auto_reminder'])->delete();
    }
};
