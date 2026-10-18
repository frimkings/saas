<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** A request to move a payment recorded under the wrong method to the right one; the owner decides. */
class PaymentCorrection extends Model
{
    use BelongsToBranch;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'payment_transaction_id', 'sale_id', 'from_method', 'to_method', 'amount', 'reason', 'status',
        'requested_by', 'decided_by', 'decided_at', 'rejection_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Support\ApprovalCounts::forget());
        static::updating(function (self $correction): void {
            if ($correction->getOriginal('status') !== self::PENDING) {
                throw new \LogicException('A decided payment correction cannot be changed.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Payment corrections cannot be deleted.'));
    }

    public static function pendingCount(): int
    {
        return static::where('status', self::PENDING)->count();
    }

    public function payment()
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sales::class, 'sale_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
