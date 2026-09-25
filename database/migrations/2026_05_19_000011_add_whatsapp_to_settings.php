<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('whatsapp_enabled')->default(false)->after('sms_enabled');
            $table->string('whatsapp_phone_number_id', 100)->nullable()->after('whatsapp_enabled');
            $table->text('whatsapp_access_token')->nullable()->after('whatsapp_phone_number_id');
            $table->string('whatsapp_appt_template', 100)->nullable()->default('appointment_reminder')->after('whatsapp_access_token');
            $table->string('whatsapp_appt_template_lang', 20)->nullable()->default('en')->after('whatsapp_appt_template');
            $table->string('whatsapp_birthday_template', 100)->nullable()->after('whatsapp_appt_template_lang');
            $table->string('whatsapp_recall_template', 100)->nullable()->after('whatsapp_birthday_template');
            $table->string('whatsapp_renewal_template', 100)->nullable()->after('whatsapp_recall_template');
            $table->string('whatsapp_bulk_channel', 20)->nullable()->default('sms')->after('whatsapp_renewal_template');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_enabled',
                'whatsapp_phone_number_id',
                'whatsapp_access_token',
                'whatsapp_appt_template',
                'whatsapp_appt_template_lang',
                'whatsapp_birthday_template',
                'whatsapp_recall_template',
                'whatsapp_renewal_template',
                'whatsapp_bulk_channel',
            ]);
        });
    }
};
