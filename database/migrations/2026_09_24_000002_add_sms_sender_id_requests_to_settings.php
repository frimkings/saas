<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('sms_sender_id_requested', 11)->nullable()->after('sms_sender_id');
            $table->string('sms_sender_id_status', 20)->default('none')->after('sms_sender_id_requested');
            $table->string('sms_sender_id_note')->nullable()->after('sms_sender_id_status');
            $table->timestamp('sms_sender_id_requested_at')->nullable()->after('sms_sender_id_note');
        });

        // Hosted clinics now send through the platform account, where only platform-approved
        // sender IDs may be used. Existing ones were registered on the clinic's own gateway
        // account, so they become pending requests instead of being used as-is.
        DB::table('settings')
            ->whereIn('clinic_id', DB::table('clinics')->where('deployment_mode', 'hosted')->select('id'))
            ->whereNotNull('sms_sender_id')->where('sms_sender_id', '!=', '')
            ->update([
                'sms_sender_id_requested'    => DB::raw('UPPER(LEFT(sms_sender_id, 11))'),
                'sms_sender_id_status'       => 'pending',
                'sms_sender_id_requested_at' => now(),
                'sms_sender_id'              => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['sms_sender_id_requested', 'sms_sender_id_status', 'sms_sender_id_note', 'sms_sender_id_requested_at']);
        });
    }
};
