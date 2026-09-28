<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A higher pair price for powers at or beyond the given SPH and/or CYL/ADD. */
class OpticalLensPriceRule extends Model
{
    protected $fillable = ['min_sphere', 'min_power', 'pair_price'];

    protected $casts = ['min_sphere' => 'decimal:2', 'min_power' => 'decimal:2', 'pair_price' => 'decimal:2'];

    public function price(): BelongsTo
    {
        return $this->belongsTo(OpticalLensPrice::class, 'optical_lens_price_id');
    }

    public function matches(float $sphere, float $power): bool
    {
        if ($this->min_sphere === null && $this->min_power === null) return false;
        return ($this->min_sphere === null || abs($sphere) >= (float) $this->min_sphere)
            && ($this->min_power === null || abs($power) >= (float) $this->min_power);
    }
}
