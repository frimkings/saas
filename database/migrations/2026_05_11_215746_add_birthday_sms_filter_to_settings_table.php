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
    public function up()
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('birthday_sms_filter')->default('all')->after('sms_enabled');
            $table->unsignedInteger('birthday_sms_custom_months')->nullable()->after('birthday_sms_filter');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['birthday_sms_filter', 'birthday_sms_custom_months']);
        });
    }
};
