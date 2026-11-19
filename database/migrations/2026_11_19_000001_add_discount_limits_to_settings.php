<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Largest discount each role may give at each till without approval, set by the clinic owner:
     * {"Cashier": {"clinical": 0, "optical": 10}, ...}. Empty = the defaults in DiscountLimits.
     */
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'discount_limits')) return;
        Schema::table('settings', fn (Blueprint $table) => $table->json('discount_limits')->nullable());
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'discount_limits')) {
            Schema::table('settings', fn (Blueprint $table) => $table->dropColumn('discount_limits'));
        }
    }
};
