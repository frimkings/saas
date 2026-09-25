<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (!Schema::hasColumn('appointments', 'recall_category')) {
                $table->string('recall_category')->nullable()->after('title');
            }

            if (!Schema::hasColumn('appointments', 'reminder_channel')) {
                $table->string('reminder_channel')->default('whatsapp')->after('notes');
            }

            if (!Schema::hasColumn('appointments', 'reminder_status')) {
                $table->string('reminder_status')->default('not_sent')->after('reminder_channel');
            }

            if (!Schema::hasColumn('appointments', 'reminder_sent_at')) {
                $table->timestamp('reminder_sent_at')->nullable()->after('reminder_status');
            }

            if (!Schema::hasColumn('appointments', 'missed_at')) {
                $table->timestamp('missed_at')->nullable()->after('reminder_sent_at');
            }
        });
    }

    public function down()
    {
        Schema::table('appointments', function (Blueprint $table) {
            $columns = [
                'recall_category',
                'reminder_channel',
                'reminder_status',
                'reminder_sent_at',
                'missed_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('appointments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
