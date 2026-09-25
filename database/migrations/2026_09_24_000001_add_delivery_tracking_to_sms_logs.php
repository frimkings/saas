<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The archive table mirrors sms_logs column-for-column (logs:prune copies whole rows).
        foreach (['sms_logs', 'sms_logs_archive'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('status', 20)->default('sent')->after('success');
                $table->unsignedSmallInteger('segments')->default(1)->after('status');
                $table->string('sender_id', 20)->nullable()->after('segments');
                $table->string('provider_message_id')->nullable()->after('sender_id');
                $table->unsignedTinyInteger('attempts')->default(0)->after('provider_message_id');
                $table->timestamp('sent_at')->nullable()->after('attempts');
            });

            DB::table($table)->where('success', false)->update(['status' => 'failed']);
        }

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->index(['clinic_id', 'channel', 'status', 'created_at'], 'sms_logs_quota_index');
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', fn (Blueprint $table) => $table->dropIndex('sms_logs_quota_index'));

        foreach (['sms_logs', 'sms_logs_archive'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['status', 'segments', 'sender_id', 'provider_message_id', 'attempts', 'sent_at']);
            });
        }
    }
};
