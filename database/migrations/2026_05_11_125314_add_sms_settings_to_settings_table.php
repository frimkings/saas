<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('sms_api_url', 500)->nullable()->after('va_notation');
            $table->text('sms_api_key')->nullable()->after('sms_api_url');
            $table->string('sms_sender_id', 50)->nullable()->after('sms_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['sms_api_url', 'sms_api_key', 'sms_sender_id']);
        });
    }
};
