<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToClinic;

class Category extends Model
{
    use HasFactory, SoftDeletes, BelongsToClinic;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'description',
        'is_active',
        'part_payment',
        'part_payment_min_percent',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'part_payment' => 'boolean',
        'part_payment_min_percent' => 'integer',
    ];

    /** Frames and lenses may be part-paid unless the owner says otherwise (the till's rule before 2026-11-24). */
    protected static function booted(): void
    {
        static::creating(function (Category $category) {
            if ($category->getAttribute('part_payment') === null && self::isFrameOrLens($category->type, $category->name)) {
                $category->part_payment = true;
            }
        });
    }

    public static function isFrameOrLens(?string $type, ?string $name): bool
    {
        $name = strtolower((string) $name);

        return in_array($type, ['frame', 'lens'], true) || str_contains($name, 'frame') || str_contains($name, 'lens');
    }

    /** Share of an item's price the patient may leave owing (0 when part payment is off). */
    public function holdableShare(): float
    {
        return $this->part_payment ? max(0, 100 - min(100, (int) $this->part_payment_min_percent)) / 100 : 0.0;
    }

    /**
     * Get the products in this category.
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the count of products in this category.
     */
    public function getProductCountAttribute()
    {
        return $this->products()->count();
    }

    /**
     * Get the count of in-stock products in this category.
     */
    public function getInStockCountAttribute()
    {
        return $this->products()->where('quantity', '>', 0)->count();
    }

    public function getLowStockCountAttribute()
    {
        return $this->products()->lowStock()->count();
    }
}
