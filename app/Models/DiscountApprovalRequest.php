<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToBranch;

class DiscountApprovalRequest extends Model
{
    use HasFactory, BelongsToBranch;

    protected static function booted(): void
    {
        // The sidebar's pending-approvals badge is cached; refresh it on any change.
        static::saved(fn () => \App\Support\ApprovalCounts::forget());
        static::deleted(fn () => \App\Support\ApprovalCounts::forget());
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_USED = 'used';

    /**
     * A direct purchase (no patient) has no saved cart to check: its items live only on the
     * cashier's till. Its request counts as open for this long, then as stale.
     */
    public const DIRECT_PURCHASE_OPEN_HOURS = 12;

    public function isDirectPurchaseStillOpen(): bool
    {
        return ! $this->patient_id && $this->created_at && $this->created_at->gt(now()->subHours(self::DIRECT_PURCHASE_OPEN_HOURS));
    }

    protected $fillable = [
        'cashier_id',
        'patient_id',
        'discount_type',
        'discount_value',
        'discount_amount',
        'gross_amount',
        'final_amount',
        'cart_snapshot',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'notes',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'cart_snapshot' => 'array',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
