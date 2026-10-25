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
        'made_to_order',
        'cost_price',
        'selling_price',
        'manufacture_date',
        'expiry_date',
    ];

    /** Most a made-to-order product can be sold at once: it is limited by the lab, not by stock. */
    public const MADE_TO_ORDER_LIMIT = 10000;

    protected $casts = [
        'quantity' => 'integer',
        'made_to_order' => 'boolean',
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
     * Check if product is in stock. Made-to-order products are always available.
     */
    public function isInStock()
    {
        return $this->made_to_order || $this->quantity > 0;
    }

    /**
     * Check if product is low on stock. Made-to-order products are never low.
     */
    public function isLowStock($threshold = null)
    {
        return ! $this->made_to_order && $this->quantity > 0 && $this->quantity <= ($threshold ?? $this->reorderLevel());
    }

    /** Whether $quantity can be sold now: made-to-order products are never limited by stock. */
    public function canSupply(int $quantity = 1): bool
    {
        return $this->made_to_order || $this->quantity >= $quantity;
    }

    /** The most that can be sold at once. */
    public function maxSupply(): int
    {
        return $this->made_to_order ? self::MADE_TO_ORDER_LIMIT : max(0, (int) $this->quantity);
    }

    /** Short stock wording for pickers: "Made to order" or "12 in stock". */
    public function stockLabel(): string
    {
        return $this->made_to_order ? 'Made to order' : $this->quantity.' in stock';
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
    /**
     * Name or batch number contains $term. On MySQL the search reads products_search_index,
     * which holds every searched column, so non-matching products are skipped in the index.
     */
    public function scopeSearchNameOrBatch($query, ?string $term)
    {
        $like = '%'.$term.'%';
        $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('batch_number', 'like', $like));

        if ($term !== null && $term !== '' && in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->forceIndex('products_search_index');
        }

        return $query;
    }

    public function scopeInStock($query)
    {
        return $query->where(fn ($q) => $q->where('made_to_order', true)->orWhere('quantity', '>', 0));
    }

    /** Products whose stock is counted (not made to order). */
    public function scopeStocked($query)
    {
        return $query->where('made_to_order', false);
    }

    /** Products whose category is switched on: an inactive category's products are not sold or prescribed. */
    public function scopeInActiveCategory($query)
    {
        return $query->where(fn ($q) => $q->whereNull('category_id')
            ->orWhereHas('category', fn ($c) => $c->where('is_active', true)));
    }

    public function inActiveCategory(): bool
    {
        return !$this->category || (bool) ($this->category->is_active ?? true);
    }

    public const DEFAULT_REORDER_LEVEL = 10;

    /**
     * Products at or below their reorder level (the branch's, else 10) but not out: a fixed
     * $threshold overrides it.
     */
    public function scopeLowStock($query, $threshold = null)
    {
        return $this->belowReorderLevel($query->stocked()->where('quantity', '>', 0), $threshold);
    }

    /** Low or out of stock: everything that needs reordering. */
    public function scopeNeedsReorder($query, $threshold = null)
    {
        return $this->belowReorderLevel($query->stocked(), $threshold);
    }

    private function belowReorderLevel($query, $threshold)
    {
        if ($threshold !== null) return $query->where('quantity', '<=', $threshold);
        $branchId = app(\App\Support\Tenancy\TenantContext::class)->branchId();
        $level = 'select bi.reorder_level from branch_inventory_items bi where bi.product_id = products.id'
            . ($branchId ? ' and bi.branch_id = ' . (int) $branchId : '') . ' order by bi.reorder_level desc limit 1';

        return $query->whereRaw("products.quantity <= COALESCE(({$level}), ?)", [self::DEFAULT_REORDER_LEVEL]);
    }

    /** This branch's reorder level for the product (10 until someone sets one). */
    public function reorderLevel(): int
    {
        $branchId = app(\App\Support\Tenancy\TenantContext::class)->branchId();

        return (int) (BranchInventoryItem::query()->where('product_id', $this->id)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->max('reorder_level') ?? self::DEFAULT_REORDER_LEVEL);
    }

    /**
     * Scope to get out of stock products.
     */
    public function scopeOutOfStock($query)
    {
        return $query->stocked()->where('quantity', 0);
    }

    /**
     * Scope to get expired products.
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expiry_date')->where('expiry_date', '<', now());
    }

  
}

