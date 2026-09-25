<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('spectacle_renewal_enabled')->default(true)->after('recall_months');
            $table->unsignedTinyInteger('spectacle_renewal_reminder_days')->default(30)->after('spectacle_renewal_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['spectacle_renewal_enabled', 'spectacle_renewal_reminder_days']);
        });
    }
};
