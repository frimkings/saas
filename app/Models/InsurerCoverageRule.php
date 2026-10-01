<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an insurer pays for a category of services/products, or for one product
 * (a product rule overrides its category's rule).
 */
class InsurerCoverageRule extends Model
{
    use BelongsToClinic;

    public const TYPES = [
        'percent'  => 'Percentage',
        'fixed'    => 'Fixed amount',
        'excluded' => 'Not covered',
    ];

    protected $fillable = [
        'insurer_id', 'category_id', 'product_id',
        'coverage_type', 'coverage_value', 'tariff_price',
    ];

    protected $casts = [
        'coverage_value' => 'decimal:2',
        'tariff_price'   => 'decimal:2',
    ];

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function describe(): string
    {
        return match ($this->coverage_type) {
            'percent'  => rtrim(rtrim(number_format((float) $this->coverage_value, 2), '0'), '.') . '%',
            'fixed'    => currency() . ' ' . number_format((float) $this->coverage_value, 2) . ' per item',
            default    => 'Not covered',
        };
    }
}
