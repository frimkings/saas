<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('sms_templates')->where('key', 'spectacle_renewal')->exists()) {
            DB::table('sms_templates')->insert([
                'key'          => 'spectacle_renewal',
                'label'        => 'Spectacle Renewal Reminder',
                'message'      => 'Dear [NAME], your spectacles are due for renewal on [DATE]. Please visit [CLINIC] for your annual eye review.',
                'placeholders' => json_encode(['[NAME]', '[DATE]', '[CLINIC]']),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('sms_templates')->where('key', 'spectacle_renewal')->delete();
    }
};
