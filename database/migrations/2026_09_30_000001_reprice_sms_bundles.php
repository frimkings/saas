<?php

use App\Models\SmsBundle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Moves the starting SMS bundles from 0.08–0.04 per SMS to 0.20–0.12. Only bundles and range
 * settings still exactly as first seeded are changed; anything edited by hand is left alone.
 * Invoices already issued keep their own price and credits.
 */
return new class extends Migration
{
    private const OLD_BUNDLES = [
        ['name' => 'Starter',  'credits' => 500,   'price' => 40],
        ['name' => 'Basic',    'credits' => 1000,  'price' => 70],
        ['name' => 'Standard', 'credits' => 2500,  'price' => 150],
        ['name' => 'Plus',     'credits' => 5000,  'price' => 250],
        ['name' => 'Pro',      'credits' => 10000, 'price' => 400],
    ];

    private const OLD_RANGE = ['sms_min_price_per_credit' => 0.04, 'sms_max_price_per_credit' => 0.08];
    private const NEW_RANGE = ['sms_min_price_per_credit' => SmsBundle::MIN_PER_CREDIT, 'sms_max_price_per_credit' => SmsBundle::MAX_PER_CREDIT];

    public function up(): void
    {
        foreach (self::OLD_BUNDLES as $i => $old) {
            $new = SmsBundle::DEFAULTS[$i];
            DB::table('sms_bundles')->where($old)->update(['name' => $new['name'], 'price' => $new['price'], 'updated_at' => now()]);
        }

        $this->swapRange(self::OLD_RANGE, self::NEW_RANGE);
    }

    public function down(): void
    {
        foreach (self::OLD_BUNDLES as $i => $old) {
            DB::table('sms_bundles')->where(SmsBundle::DEFAULTS[$i])->update(['name' => $old['name'], 'price' => $old['price'], 'updated_at' => now()]);
        }

        $this->swapRange(self::NEW_RANGE, self::OLD_RANGE);
    }

    private function swapRange(array $from, array $to): void
    {
        foreach ($from as $key => $value) {
            $row = DB::table('platform_settings')->where('key', $key)->first();
            if ($row && abs((float) $row->value - $value) < 0.00001) {
                DB::table('platform_settings')->where('key', $key)->update(['value' => (string) $to[$key], 'updated_at' => now()]);
            }
        }
        Cache::forget('platform_settings');
    }
};
