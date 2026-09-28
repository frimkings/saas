<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OpticalProduct extends Model
{
    use BelongsToClinic, SoftDeletes;

    protected $fillable = [
        'optical_category_id', 'sku', 'name', 'brand', 'specifications',
        'cost_price', 'selling_price', 'is_active', 'lens_specs', 'lens_key',
    ];

    protected $casts = [
        'lens_specs' => 'array',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(OpticalCategory::class, 'optical_category_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(OpticalProductStock::class);
    }

    /**
     * Progressive and bifocal stock lenses are sold only as a right + left pair through a
     * lens order. Single vision lenses can be sold one at a time (half a pair).
     */
    public function isPairOnlyLens(): bool
    {
        return \App\Support\LensDesign::isEyeSpecific(data_get($this->lens_specs, 'design'));
    }

    /** Products that may be sold on their own, e.g. at the optical POS. */
    public function scopeSoldIndividually($query)
    {
        return $query->where(fn ($q) => $q->whereNull('lens_specs')
            ->orWhereNotIn('lens_specs->design', \App\Support\LensDesign::EYE_SPECIFIC));
    }
}
