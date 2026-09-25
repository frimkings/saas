<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_logs', function (Blueprint $table) {
            $table->softDeletes()->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('refund_logs', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
