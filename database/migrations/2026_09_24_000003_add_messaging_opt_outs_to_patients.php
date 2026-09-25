<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->boolean('sms_opt_out')->default(false);
            $table->boolean('whatsapp_opt_out')->default(false);
            $table->boolean('marketing_opt_out')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['sms_opt_out', 'whatsapp_opt_out', 'marketing_opt_out']);
        });
    }
};
