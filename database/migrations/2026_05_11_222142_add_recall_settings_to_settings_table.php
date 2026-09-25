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
            $table->boolean('recall_sms_enabled')->default(false)->after('birthday_sms_custom_months');
            $table->unsignedInteger('recall_months')->default(12)->after('recall_sms_enabled');
        });
    }

    public function down()
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['recall_sms_enabled', 'recall_months']);
        });
    }
};
