<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "Extra users": a clinic can add user logins on top of its plan's included users without
     * upgrading. One user per pack, GH₵30 a month each; the platform can change the price on the
     * add-on price list.
     */
    public function up(): void
    {
        if (DB::table('platform_addons')->where('feature', 'extra_user')->exists()) return;

        DB::table('platform_addons')->insert([
            'feature' => 'extra_user', 'monthly_price' => 30, 'currency' => 'GHS', 'is_offered' => true, 'pack_size' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('platform_addons')->where('feature', 'extra_user')->delete();
    }
};
