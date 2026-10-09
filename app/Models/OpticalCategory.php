<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OpticalCategory extends Model
{
    use BelongsToClinic, SoftDeletes;

    /** What a category holds. Lens categories may also name a lens type (a design). */
    public const TYPES = ['frame' => 'Frames', 'lens' => 'Lenses', 'accessory' => 'Accessories'];

    protected $fillable = ['code', 'name', 'type', 'lens_type', 'default_markup', 'is_active', 'description'];

    protected $casts = ['default_markup' => 'decimal:2', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        // A category made without a type (CSV import, older code) takes the one its name suggests.
        static::creating(function (self $category): void {
            if ($category->type) return;
            [$category->type, $guessed] = static::guessType($category->name.' '.$category->code);
            $category->lens_type ??= $guessed;
        });
    }

    /** The type a category's name suggests: [type, lens type or null]. */
    public static function guessType(string $label): array
    {
        $label = strtolower($label);
        if (preg_match('/frame|eyewear|sunglass/', $label)) return ['frame', null];
        if (preg_match('/progressive|\bpal\b/', $label)) return ['lens', 'Progressive'];
        if (str_contains($label, 'bifocal')) return ['lens', 'Bifocal'];
        if (preg_match('/single[ -]?vision|\bsv[ -]/', $label)) return ['lens', 'Single Vision'];
        return ['accessory', null];
    }

    public function products(): HasMany
    {
        return $this->hasMany(OpticalProduct::class);
    }

    public function legacyProducts(): HasMany
    {
        return $this->hasMany(Product::class, 'optical_category_id');
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
     * The older grouping some screens still read: frames, a lens type, plain lenses or
     * other. It now comes from the category's type, not its name.
     */
    public function getGroupAttribute(): string
    {
        if ($this->isFrame()) return 'frames';
        if (! $this->isLens()) return 'other';
        return ['Single Vision' => 'single_vision', 'Progressive' => 'progressive', 'Bifocal' => 'bifocal'][$this->lens_type] ?? 'lenses';
    }

    /** Selling price suggested from a cost by this category's markup, or null without one. */
    public function suggestedPrice(float $cost): ?float
    {
        return $this->default_markup === null || $cost <= 0 ? null : round($cost * (1 + (float) $this->default_markup / 100), 2);
    }

    /**
     * Where received stock lenses of a lens type are filed: the clinic's own lens category
     * for that type, else a general lens category, else one made for it.
     */
    public static function forStockLenses(string $design): self
    {
        return static::stockLensCategory($design)
            ?? tap(static::withTrashed()->firstOrNew(['code' => 'stock-'.strtolower(str_replace(' ', '-', $design))]), function (self $category) use ($design) {
                if ($category->trashed()) $category->restore();
                $category->fill(['name' => $category->name ?: $design.' Lenses', 'type' => 'lens', 'lens_type' => $design, 'is_active' => true, 'default_markup' => $category->default_markup])->save();
            });
    }

    /** The existing category stock lenses of this type are filed in, without making one. */
    public static function stockLensCategory(string $design): ?self
    {
        $lens = fn () => static::where('type', 'lens')->orderByDesc('is_active')->orderBy('id');
        return $lens()->where('lens_type', $design)->first() ?? $lens()->whereNull('lens_type')->first();
    }
}
