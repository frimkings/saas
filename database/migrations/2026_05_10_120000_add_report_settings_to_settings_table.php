<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('report_enabled')->default(false)->after('backup_extra_paths');
            $table->string('report_frequency', 10)->default('daily')->after('report_enabled');
            $table->tinyInteger('report_day')->default(1)->after('report_frequency'); // 0=Sun … 6=Sat
            $table->string('report_time', 5)->default('08:00')->after('report_day');
            $table->json('report_recipients')->nullable()->after('report_time');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['report_enabled', 'report_frequency', 'report_day', 'report_time', 'report_recipients']);
        });
    }
};
