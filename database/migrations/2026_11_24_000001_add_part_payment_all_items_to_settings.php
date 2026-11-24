<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner's choice (2026-11-24): the clinic till may take part payment on drugs and services too,
     * not only on frames and lenses. Off by default, so drugs never leave on credit unless the owner says so.
     */
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'part_payment_all_items')) return;
        Schema::table('settings', fn (Blueprint $table) => $table->boolean('part_payment_all_items')->default(false));
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'part_payment_all_items')) {
            Schema::table('settings', fn (Blueprint $table) => $table->dropColumn('part_payment_all_items'));
        }
    }
};
