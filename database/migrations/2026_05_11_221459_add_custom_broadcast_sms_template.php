<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::table('sms_templates')->insertOrIgnore([
            'key'          => 'custom_broadcast',
            'label'        => 'Custom Broadcast',
            'message'      => 'Dear [NAME], [CLINIC] wishes you a joyful [OCCASION]! Thank you for trusting us with your eye care.',
            'placeholders' => json_encode(['[NAME]', '[CLINIC]', '[OCCASION]']),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function down()
    {
        DB::table('sms_templates')->where('key', 'custom_broadcast')->delete();
    }
};
