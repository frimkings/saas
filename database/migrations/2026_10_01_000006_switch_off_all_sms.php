<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Every SMS starts switched off (DefaultSmsTemplates::onByDefault). On this release the
 * platform also switches off every message clinics had on, so nothing is sent until each
 * clinic chooses its messages again on the SMS Templates screen. Which ones were on is kept
 * in platform_settings, so a rollback switches exactly those back on.
 */
return new class extends Migration
{
    private const SNAPSHOT_KEY = 'sms_switches_on_before_2026_10_01';

    public function up(): void
    {
        $wereOn = DB::table('sms_templates')->where('is_enabled', true)->pluck('id')->all();

        DB::table('platform_settings')->updateOrInsert(['key' => self::SNAPSHOT_KEY],
            ['value' => json_encode($wereOn), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sms_templates')->where('is_enabled', true)->update(['is_enabled' => false, 'updated_at' => now()]);
        Cache::forget('platform_settings');
    }

    public function down(): void
    {
        $wereOn = json_decode((string) DB::table('platform_settings')->where('key', self::SNAPSHOT_KEY)->value('value'), true) ?: [];
        foreach (array_chunk($wereOn, 500) as $ids) {
            DB::table('sms_templates')->whereIn('id', $ids)->update(['is_enabled' => true, 'updated_at' => now()]);
        }
        DB::table('platform_settings')->where('key', self::SNAPSHOT_KEY)->delete();
        Cache::forget('platform_settings');
    }
};
