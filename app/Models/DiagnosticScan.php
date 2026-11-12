<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** A scan the clinic sells (OCT, VFT, fundus photo…), with its price and the referrer's commission. */
class DiagnosticScan extends Model
{
    use BelongsToClinic;

    public const COMMISSION_TYPES = ['percent' => 'Percentage of the price', 'fixed' => 'Fixed amount per scan', 'none' => 'No commission'];

    protected $fillable = ['name', 'code', 'price', 'per_eye', 'commission_type', 'commission_value', 'turnaround', 'is_active'];

    protected $casts = ['price' => 'decimal:2', 'commission_value' => 'decimal:2', 'per_eye' => 'boolean', 'is_active' => 'boolean'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function partnerRates()
    {
        return $this->hasMany(ScanPartnerRate::class);
    }

    /** Commission on one scan at this price: a percentage of it, a fixed amount, or nothing. */
    public static function commissionOn(float $amount, string $type, float $value): float
    {
        return round(match ($type) {
            'percent' => $amount * $value / 100,
            'fixed' => $value,
            default => 0.0,
        }, 2);
    }

    /** "10%", "GHS 20.00" or "None". */
    public static function describeCommission(string $type, float $value): string
    {
        return match ($type) {
            'percent' => rtrim(rtrim(number_format($value, 2), '0'), '.').'%',
            'fixed' => currency().' '.number_format($value, 2),
            default => 'None',
        };
    }

    public function commissionLabel(): string
    {
        return self::describeCommission($this->commission_type, (float) $this->commission_value);
    }
}
