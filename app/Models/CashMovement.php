<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/**
 * Money moved in or out of a till or account by hand, as opposed to payments, refunds and
 * expenses, which the cash book reads from their own records. Amounts are signed (in > 0).
 * Never edited or deleted: a mistake is cancelled with a reason and stays on the book.
 */
class CashMovement extends Model
{
    use BelongsToBranch;

    public const OPENING = 'opening';
    public const BANKED = 'banked';
    public const ADDED = 'added';
    public const VARIANCE = 'variance';
    // A payment to a supplier against a purchase order invoice (money out).
    public const SUPPLIER = 'supplier_paid';

    public const LABELS = [
        self::OPENING  => 'Opening balance',
        self::BANKED   => 'Banked / handed over',
        self::ADDED    => 'Money added',
        self::VARIANCE => 'Count over / short',
        self::SUPPLIER => 'Paid to supplier',
    ];

    protected $fillable = [
        'type', 'payment_method', 'amount', 'moved_on', 'reference', 'notes', 'recorded_by',
        'day_close_id', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'moved_on' => 'date',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $movement): void {
            // Only cancelling is allowed, once.
            $changed = array_keys($movement->getDirty());
            if ($movement->getOriginal('cancelled_at') !== null || array_diff($changed, ['cancelled_at', 'cancelled_by', 'cancel_reason', 'updated_at'])) {
                throw new \LogicException('Cash movements cannot be changed; cancel and record again.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Cash movements cannot be deleted.'));
    }

    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getLabelAttribute(): string
    {
        return self::LABELS[$this->type] ?? ucfirst($this->type);
    }
}
