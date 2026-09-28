<?php

use App\Models\SmsBundle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Starting SMS bundles priced GHS 0.08 down to 0.04 per credit. Only loaded when the platform
 * has no bundles yet, so a catalogue already set up by hand is never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('sms_bundles')->exists()) return;

        foreach (SmsBundle::DEFAULTS as $i => $bundle) {
            DB::table('sms_bundles')->insert($bundle + [
                'currency' => 'GHS', 'is_active' => true, 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Bundles may have been bought or edited since; leave them.
    }
};
