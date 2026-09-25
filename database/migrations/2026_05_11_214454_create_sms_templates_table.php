<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->text('message');
            $table->json('placeholders');
            $table->timestamps();
        });

        $now = now();
        DB::table('sms_templates')->insert([
            [
                'key'          => 'appointment_booking',
                'label'        => 'Appointment Booking Confirmation',
                'message'      => 'Hello [NAME], your appointment at [CLINIC] is confirmed for [DATE] at [TIME] – [REASON].',
                'placeholders' => json_encode(['[NAME]', '[DATE]', '[TIME]', '[REASON]', '[CLINIC]']),
                'created_at'   => $now, 'updated_at' => $now,
            ],
            [
                'key'          => 'appointment_reminder',
                'label'        => 'Appointment Reminder',
                'message'      => 'Hello [NAME], this is a reminder of your appointment at [CLINIC] on [DATE] at [TIME] – [REASON]. Please be on time.',
                'placeholders' => json_encode(['[NAME]', '[DATE]', '[TIME]', '[REASON]', '[CLINIC]']),
                'created_at'   => $now, 'updated_at' => $now,
            ],
            [
                'key'          => 'spectacles_ready',
                'label'        => 'Spectacles Ready for Pickup',
                'message'      => 'Hello [NAME], your spectacles (Order [ORDER_ID]) are ready for collection at [CLINIC]. Please bring this message when you come in.',
                'placeholders' => json_encode(['[NAME]', '[ORDER_ID]', '[CLINIC]']),
                'created_at'   => $now, 'updated_at' => $now,
            ],
            [
                'key'          => 'spectacles_reminder',
                'label'        => 'Spectacles Pickup Reminder',
                'message'      => 'Hello [NAME], your spectacles (Order [ORDER_ID]) are still waiting for collection at [CLINIC]. Please come in at your earliest convenience.',
                'placeholders' => json_encode(['[NAME]', '[ORDER_ID]', '[CLINIC]']),
                'created_at'   => $now, 'updated_at' => $now,
            ],
            [
                'key'          => 'payment_receipt',
                'label'        => 'Payment Receipt',
                'message'      => 'Hello [NAME], payment of GHS [AMOUNT] received at [CLINIC]. Transaction: [TXN_ID]. Thank you!',
                'placeholders' => json_encode(['[NAME]', '[AMOUNT]', '[TXN_ID]', '[CLINIC]']),
                'created_at'   => $now, 'updated_at' => $now,
            ],
            [
                'key'          => 'birthday_wishes',
                'label'        => 'Birthday Wishes',
                'message'      => 'Happy Birthday [NAME]! Wishing you good health and clear vision. From all of us at [CLINIC].',
                'placeholders' => json_encode(['[NAME]', '[CLINIC]']),
                'created_at'   => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('sms_templates');
    }
};
