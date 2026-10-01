<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToBranch;

class Cart extends Model
{
    use HasFactory, BelongsToBranch;

    /**
     * The table associated with the model.
     */
    protected $table = 'carts';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'patient_id',
        'dispensed_by',
        'consultation_id',
        'product_id',
        'quantity',
        'price',
        'total',
        'status',
        'is_dispensed',
        'dispensed_at',
        'purchased',
        'frequency',
        'duration_value',
        'duration_unit',
        'eye',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'quantity' => 'integer',
        'duration_value' => 'integer',
        'price' => 'decimal:2',
        'total' => 'decimal:2',
        'is_dispensed' => 'boolean',
        'purchased' => 'boolean',
        'dispensed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for arrays.
     */
    protected $hidden = [];

    /**
     * Default values for attributes
     */
    protected $attributes = [
        'status' => 'pending',
        'is_dispensed' => false,
        'purchased' => false,
        'consultation_id' => null,
        'quantity' => 1,
    ];

    /* ==========================================
     * RELATIONSHIPS
     * ========================================== */

    /**
     * Get the patient that owns the cart item
     */
    public function patient()
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * Get the consultation this cart belongs to
     */
    public function consultation()
    {
        return $this->belongsTo(Consultations::class, 'consultation_id');
    }

    /**
     * Get the product associated with the cart item
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Get the user who dispensed this item
     */
    public function dispensedBy()
    {
        return $this->belongsTo(User::class, 'dispensed_by');
    }

    /* ==========================================
     * QUERY SCOPES
     * ========================================== */

    /**
     * Scope to get only active/pending cart items
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to get only pending (not purchased) cart items
     */
    public function scopePending($query)
    {
        return $query->where('purchased', false)
                     ->where('status', 'pending');
    }

    /**
     * Scope to get purchased/completed cart items
     */
    public function scopePurchased($query)
    {
        return $query->where('purchased', true);
    }

    /**
     * Scope to get completed cart items
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope to get cart items for a specific patient
     */
    public function scopeForPatient($query, $patientId)
    {
        return $query->where('patient_id', $patientId);
    }

    /**
     * Scope to get cart items for a specific user/cashier
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('dispensed_by', $userId);
    }

    /**
     * Scope to get cart items for a specific consultation
     */
    public function scopeForConsultation($query, $consultationId)
    {
        return $query->where('consultation_id', $consultationId);
    }

    /**
     * Scope to get floating cart items (not linked to consultation)
     * These are items added directly from POS without a consultation
     */
    public function scopeFloating($query)
    {
        return $query->where('consultation_id', 0);
    }

    /**
     * Scope to get dispensed items
     */
    public function scopeDispensed($query)
    {
        return $query->where('is_dispensed', true);
    }

    /**
     * Scope to get undispensed items
     */
    public function scopeUndispensed($query)
    {
        return $query->where('is_dispensed', false);
    }

    /**
     * Scope to get cart items created today
     */
    public function scopeToday($query)
    {
        return $query->whereDateIndexed('created_at', today());
    }

    /**
     * Scope to get cart items from a specific date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /* ==========================================
     * HELPER METHODS
     * ========================================== */

    /**
     * Calculate and update the total
     */
    public function calculateTotal()
    {
        $this->total = $this->quantity * $this->price;
        return $this->total;
    }

    /**
     * Mark cart item as purchased
     */
    public function markAsPurchased()
    {
        $this->purchased = true;
        $this->status = 'completed';
        $this->save();
        
        return $this;
    }

    /**
     * Mark cart item as dispensed
     */
    public function markAsDispensed($userId = null)
    {
        $this->is_dispensed = true;
        $this->dispensed_at = now();
        
        if ($userId) {
            $this->dispensed_by = $userId;
        }
        
        $this->save();
        
        return $this;
    }

    /**
     * Update quantity and recalculate total
     */
    public function updateQuantity($quantity)
    {
        $this->quantity = max(1, (int) $quantity);
        $this->calculateTotal();
        $this->save();
        
        return $this;
    }

    /**
     * Check if cart item is active/pending
     */
    public function isActive()
    {
        return $this->status === 'pending' && !$this->purchased;
    }

    /**
     * Check if cart item is purchased
     */
    public function isPurchased()
    {
        return $this->purchased === true;
    }

    /**
     * Check if cart item is dispensed
     */
    public function isDispensed()
    {
        return $this->is_dispensed === true;
    }

    /**
     * Check if cart item is floating (not linked to consultation)
     */
    public function isFloating()
    {
        return $this->consultation_id == 0;
    }

    /**
     * Get formatted price
     */
    public function getFormattedPriceAttribute()
    {
        return currency() . number_format($this->price, 2);
    }

    /**
     * Get formatted total
     */
    public function getFormattedTotalAttribute()
    {
        return currency() . number_format($this->total, 2);
    }

    /**
     * Get cart item age in days
     */
    public function getAgeInDaysAttribute()
    {
        return $this->created_at->diffInDays(now());
    }

    /* ==========================================
     * BOOT METHOD
     * ========================================== */

    /**
     * Boot method to auto-calculate total when saving
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-calculate total when quantity or price changes
        static::saving(function ($cart) {
            if ($cart->isDirty(['quantity', 'price'])) {
                $cart->total = $cart->quantity * $cart->price;
            }
        });

        // Optionally, log when cart items are created
        static::created(function ($cart) {
            \Log::info('Cart item created', [
                'cart_id' => $cart->id,
                'patient_id' => $cart->patient_id,
                'product_id' => $cart->product_id,
                'quantity' => $cart->quantity,
            ]);
        });
    }

    /* ==========================================
     * STATIC HELPER METHODS
     * ========================================== */

    /**
     * Get cart summary for a patient
     * 
     * @param int $patientId
     * @param int|null $consultationId
     * @return array
     */
    public static function getSummary($patientId, $consultationId = null)
    {
        $query = static::active()->forPatient($patientId);
        
        if ($consultationId) {
            $query->forConsultation($consultationId);
        } else {
            $query->floating(); // Only floating items (POS items)
        }

        $items = $query->with('product')->get();
        
        return [
            'items' => $items,
            'total' => $items->sum('total'),
            'count' => $items->count(),
            'dispensed_count' => $items->where('is_dispensed', true)->count(),
            'undispensed_count' => $items->where('is_dispensed', false)->count(),
            'total_quantity' => $items->sum('quantity'),
        ];
    }

    /**
     * Get pending cart items for a patient and user
     * This is used by the POS system to load saved carts
     * 
     * @param int $patientId
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getPendingCart($patientId, $userId)
    {
        return static::pending()
            ->forPatient($patientId)
            ->forUser($userId)
            ->floating() // Only POS items (not consultation items)
            ->with('product')
            ->get();
    }

    /**
     * Clear pending cart for a patient and user
     * 
     * @param int $patientId
     * @param int $userId
     * @return int Number of items deleted
     */
    public static function clearPendingCart($patientId, $userId)
    {
        return static::pending()
            ->forPatient($patientId)
            ->forUser($userId)
            ->floating()
            ->delete();
    }

    /**
     * Get abandoned carts (older than X days)
     * 
     * @param int $days Default is 7 days
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAbandonedCarts($days = 7)
    {
        return static::pending()
            ->where('created_at', '<', now()->subDays($days))
            ->with(['patient', 'product'])
            ->get();
    }

    /**
     * Clean up abandoned carts
     * 
     * @param int $days Default is 30 days
     * @return int Number of items deleted
     */
    public static function cleanupAbandonedCarts($days = 30)
    {
        return static::pending()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    /**
     * Get total cart value for a patient
     * 
     * @param int $patientId
     * @param int|null $userId
     * @return float
     */
    public static function getTotalValue($patientId, $userId = null)
    {
        $query = static::pending()->forPatient($patientId);
        
        if ($userId) {
            $query->forUser($userId);
        }
        
        return (float) $query->sum('total');
    }

    /**
     * Duplicate cart items to another patient or consultation
     * 
     * @param int $fromPatientId
     * @param int $toPatientId
     * @param int|null $consultationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function duplicateCart($fromPatientId, $toPatientId, $consultationId = null)
    {
        $items = static::pending()
            ->forPatient($fromPatientId)
            ->get();
        
        $duplicated = collect();
        
        foreach ($items as $item) {
            $duplicate = $item->replicate();
            $duplicate->patient_id = $toPatientId;
            
            if ($consultationId) {
                $duplicate->consultation_id = $consultationId;
            }
            
            $duplicate->save();
            $duplicated->push($duplicate);
        }
        
        return $duplicated;
    }

    /* ==========================================
     * CART VALIDATION
     * ========================================== */

    /**
     * Validate cart against current product stock
     * Returns array of items with insufficient stock
     * 
     * @return array
     */
    public static function validateStock($patientId, $userId)
    {
        $cartItems = static::getPendingCart($patientId, $userId);
        $invalidItems = [];
        
        foreach ($cartItems as $item) {
            if (!$item->product || $item->product->quantity < $item->quantity) {
                $invalidItems[] = [
                    'cart_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product ? $item->product->name : 'Unknown',
                    'requested_quantity' => $item->quantity,
                    'available_quantity' => $item->product ? $item->product->quantity : 0,
                ];
            }
        }
        
        return $invalidItems;
    }

    /**
     * Remove items with insufficient stock from cart
     * 
     * @param int $patientId
     * @param int $userId
     * @return int Number of items removed
     */
    public static function removeInvalidItems($patientId, $userId)
    {
        $cartItems = static::getPendingCart($patientId, $userId);
        $removed = 0;
        
        foreach ($cartItems as $item) {
            if (!$item->product || $item->product->quantity < $item->quantity) {
                $item->delete();
                $removed++;
            }
        }
        
        return $removed;
    }
}
