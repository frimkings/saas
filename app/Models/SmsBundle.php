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
    public const MIN_PER_CREDIT = 0.04;
    public const MAX_PER_CREDIT = 0.08;

    /** Loaded once when there are no bundles: small packs at the top of the range, large ones at the bottom. */
    public const DEFAULTS = [
        ['name' => 'Starter',  'credits' => 500,    'price' => 40],    // 0.080 per SMS
        ['name' => 'Basic',    'credits' => 1000,   'price' => 70],    // 0.070
        ['name' => 'Standard', 'credits' => 2500,   'price' => 150],   // 0.060
        ['name' => 'Plus',     'credits' => 5000,   'price' => 250],   // 0.050
        ['name' => 'Pro',      'credits' => 10000,  'price' => 400],   // 0.040
    ];

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
