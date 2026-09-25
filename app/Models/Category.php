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
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

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
        return $this->products()->where('quantity', '>', 0)->where('quantity', '<=', 10)->count();
    }
}
