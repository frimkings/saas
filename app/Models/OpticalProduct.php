<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OpticalProduct extends Model
{
    use BelongsToClinic, SoftDeletes;

    /**
     * The category every product belongs to (the `type` column). The three are fixed because
     * the app treats them differently: frames and lenses go on spectacle orders, and all
     * three sell at the till. A lens is further classified by lens type, form, treatment and
     * manufacturer; a frame by brand, model, colour and size.
     */
    public const TYPES = ['frame' => 'Frames', 'lens' => 'Lenses', 'accessory' => 'Accessories'];

    protected $fillable = [
        'type', 'sku', 'name', 'brand', 'specifications',
        'cost_price', 'selling_price', 'is_active', 'lens_specs', 'lens_key', 'details',
    ];

    protected $casts = [
        'lens_specs' => 'array',
        // A lens added as a product (no powers): design, form, coating, range. A frame: brand, model, colour, size.
        'details' => 'array',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Stock lenses (with powers) are always lenses; anything else made without a type is an accessory.
        static::saving(function (self $product): void {
            if ($product->lens_specs !== null) $product->type = 'lens';
            $product->type ??= 'accessory';
        });
    }

    public function categoryLabel(): string
    {
        return self::TYPES[$this->type] ?? self::TYPES['accessory'];
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(OpticalProductStock::class);
    }

    public function isFrame(): bool
    {
        return $this->type === 'frame';
    }

    public function isLens(): bool
    {
        return $this->type === 'lens';
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
