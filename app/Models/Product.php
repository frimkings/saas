<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\ProductFrequency;
use App\Models\Concerns\BelongsToClinic;
class Product extends Model
{
    use HasFactory, SoftDeletes, BelongsToClinic;

    protected $fillable = [
        'user_id',
        'name',
        'category_id',
        'optical_category_id',
        'batch_number',
        'quantity',
        'cost_price',
        'selling_price',
        'manufacture_date',
        'expiry_date',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'manufacture_date' => 'date',
        'expiry_date' => 'date',
    ];

    /**
     * Get the user that created the product.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the category that the product belongs to.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function isDrugCategory(): bool
    {
        $category = $this->category;

        if (!$category) {
            return false;
        }

        $type = strtolower((string) ($category->type ?? ''));
        $name = strtolower((string) ($category->name ?? ''));

        return $type === 'drug' || in_array($name, ['drug', 'drugs', 'medication', 'medications'], true);
    }

    /**
     * Get the sale items for this product.
     */
    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Get the cart items for this product.
     */
    public function cartItems()
    {
        return $this->hasMany(Cart::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(Stock::class);
    }

    public function opticalCategory()
    {
        return $this->belongsTo(OpticalCategory::class);
    }

    public function branchInventory()
    {
        return $this->hasMany(BranchInventoryItem::class);
    }

    public function inventoryLots()
    {
        return $this->hasMany(InventoryLot::class);
    }

    /**
     * Check if product is in stock.
     */
    public function isInStock()
    {
        return $this->quantity > 0;
    }

    /**
     * Check if product is low on stock.
     */
    public function isLowStock($threshold = 10)
    {
        return $this->quantity > 0 && $this->quantity <= $threshold;
    }

    /**
     * Check if product is expired.
     */
    public function isExpired()
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    /**
     * Get profit margin for the product.
     */
    public function getProfitMargin()
    {
        if ($this->cost_price == 0) {
            return 0;
        }
        return (($this->selling_price - $this->cost_price) / $this->cost_price) * 100;
    }

    /**
     * Scope to filter products by category.
     */
    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Scope to get only in-stock products.
     */
    public function scopeInStock($query)
    {
        return $query->where('quantity', '>', 0);
    }

    /**
     * Scope to get low stock products.
     */
    public function scopeLowStock($query, $threshold = 10)
    {
        return $query->where('quantity', '>', 0)->where('quantity', '<=', $threshold);
    }

    /**
     * Scope to get out of stock products.
     */
    public function scopeOutOfStock($query)
    {
        return $query->where('quantity', 0);
    }

    /**
     * Scope to get expired products.
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expiry_date')->where('expiry_date', '<', now());
    }

  
}

