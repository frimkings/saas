<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->timestamp('collected_at')->nullable()->after('pickUpDate');
            $table->date('renewal_date')->nullable()->after('collected_at');
            $table->timestamp('renewal_reminder_sent_at')->nullable()->after('renewal_date');
        });
    }

    public function down(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropColumn(['collected_at', 'renewal_date', 'renewal_reminder_sent_at']);
        });
    }
};
