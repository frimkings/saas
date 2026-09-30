<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-wide catalogue of prepaid SMS credit bundles clinics can buy. */
class SmsBundle extends Model
{
    protected $fillable = ['name', 'credits', 'price', 'currency', 'is_active', 'sort_order'];

    protected $attributes = ['is_active' => true, 'currency' => 'GHS', 'sort_order' => 0];

    protected $casts = ['credits' => 'integer', 'price' => 'decimal:2', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    /** Allowed price per SMS credit; the platform can change it (Platform → SMS → Bundles). */
    public const MIN_PER_CREDIT = 0.12;
    public const MAX_PER_CREDIT = 0.20;

    /** Loaded once when there are no bundles: small packs at the top of the range, large ones at the bottom. */
    public const DEFAULTS = [
        ['name' => 'Starter',      'credits' => 500,    'price' => 100],   // 0.200 per SMS
        ['name' => 'Basic',        'credits' => 1000,   'price' => 180],   // 0.180
        ['name' => 'Standard',     'credits' => 2500,   'price' => 400],   // 0.160
        ['name' => 'Professional', 'credits' => 5000,   'price' => 700],   // 0.140
        ['name' => 'Enterprise',   'credits' => 10000,  'price' => 1200],  // 0.120
    ];

    /** A clinic may also type its own amount (GHS) and gets credits at the rate of the bundles it can afford. */
    public const MIN_TOP_UP = 50;
    public const MAX_TOP_UP = 1200;

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('credits');
    }

    /** @return array{0: float, 1: float} lowest and highest price per credit */
    public static function priceRange(): array
    {
        return [
            (float) PlatformSetting::get('sms_min_price_per_credit', (string) self::MIN_PER_CREDIT),
            (float) PlatformSetting::get('sms_max_price_per_credit', (string) self::MAX_PER_CREDIT),
        ];
    }

    /**
     * Rate tiers for a custom amount: each active bundle's price and credits, cheapest first.
     * The same list is handed to the browser so its preview matches quoteFor() exactly.
     *
     * @return list<array{id: int, name: string, price: float, credits: int}>
     */
    public static function tiers(): array
    {
        return self::active()->get()->sortBy('price')->values()
            ->map(fn (self $b) => ['id' => $b->id, 'name' => $b->name, 'price' => (float) $b->price, 'credits' => $b->credits])
            ->all();
    }

    /**
     * Credits a custom amount buys: the best rate among the bundles the amount can pay for
     * (the smallest bundle's rate below that), rounded down to whole credits.
     *
     * @return array{credits: int, rate: float, tier: array|null}|null null when no bundles are on sale
     */
    public static function quoteFor(float $amount, ?array $tiers = null): ?array
    {
        $tiers ??= self::tiers();
        if (!$tiers) return null;

        $tier = $tiers[0];
        foreach ($tiers as $t) {
            if ($t['price'] <= $amount + 0.00001 && $t['credits'] / $t['price'] > $tier['credits'] / $tier['price']) {
                $tier = $t;
            }
        }

        return [
            'credits' => (int) floor($amount * $tier['credits'] / $tier['price'] + 0.000001),
            'rate'    => $tier['price'] / $tier['credits'],
            'tier'    => $tier,
        ];
    }

    public function perCredit(): float
    {
        return (float) $this->price / max(1, $this->credits);
    }

    public function withinRange(): bool
    {
        [$min, $max] = self::priceRange();
        $per = round($this->perCredit(), 4);

        return $per >= $min && $per <= $max;
    }
}
