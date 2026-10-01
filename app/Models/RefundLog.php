<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToBranch;

class RefundLog extends Model
{
    use SoftDeletes, BelongsToBranch;

    const STATUS_PENDING   = 'pending';
    const STATUS_APPROVED  = 'approved';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_PROCESSED = 'processed';

    const TYPE_REFUND = 'refund';
    const TYPE_VOID = 'void';

    const REASON_CODES = [
        'customer_return' => 'Customer return',
        'wrong_item' => 'Wrong item supplied',
        'defective_item' => 'Defective or damaged item',
        'duplicate_charge' => 'Duplicate charge',
        'payment_error' => 'Payment or cashier error',
        'service_cancelled' => 'Service cancelled',
        'other' => 'Other',
    ];

    protected $fillable = [
        'sale_id',
        'sale_item_ids',
        'refund_number',
        'request_type',
        'reason_code',
        'status',
        'initiated_by',
        'approved_by',
        'processed_by',
        'rejected_by',
        'reason',
        'refunded_amount',
        'original_payment_references',
        'stock_restoration',
        'rejection_reason',
        'initiated_at',
        'approved_at',
        'processed_at',
        'rejected_at',
    ];

    protected $casts = [
        'initiated_at' => 'datetime',
        'approved_at'  => 'datetime',
        'processed_at' => 'datetime',
        'rejected_at'  => 'datetime',
        'refunded_amount' => 'decimal:2',
        'original_payment_references' => 'array',
        'stock_restoration' => 'array',
        'sale_item_ids' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Support\ApprovalCounts::forget());
        static::deleted(fn () => \App\Support\ApprovalCounts::forget());

        static::updating(function (self $refund): void {
            if ($refund->getOriginal('status') === self::STATUS_PROCESSED) {
                throw new \LogicException('Processed refund records are immutable.');
            }
        });

        static::deleting(function (self $refund): void {
            if ($refund->status === self::STATUS_PROCESSED) {
                throw new \LogicException('Processed refund records cannot be deleted.');
            }
        });
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeProcessed($query)
    {
        return $query->where('status', self::STATUS_PROCESSED);
    }

    // ── Computed attributes ────────────────────────────────────────────────────

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING   => 'Pending',
            self::STATUS_APPROVED  => 'Approved',
            self::STATUS_REJECTED  => 'Rejected',
            self::STATUS_PROCESSED => 'Processed',
            default                => ucfirst($this->status),
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PROCESSED => 'success',
            self::STATUS_APPROVED  => 'primary',
            self::STATUS_REJECTED  => 'danger',
            default                => 'warning',
        };
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function pendingCount(): int
    {
        return static::where('status', self::STATUS_PENDING)->count();
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sales::class, 'sale_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
