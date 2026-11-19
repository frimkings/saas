<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** A request to move a partner job to the right partner clinic; a manager decides. */
class OpticalPartnerChange extends Model
{
    use BelongsToBranch;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'lens_order_id', 'from_partner_clinic_id', 'from_partner_name', 'to_partner_clinic_id', 'reason', 'status',
        'requested_by', 'decided_by', 'decided_at', 'rejection_reason',
    ];

    protected $casts = ['decided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saved(fn () => app(\App\Services\Reminders\AttentionItems::class)->forgetCounts());
        static::updating(function (self $change): void {
            if ($change->getOriginal('status') !== self::PENDING) {
                throw new \LogicException('A decided partner change cannot be changed.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Partner changes cannot be deleted.'));
    }

    public function order()
    {
        return $this->belongsTo(LensOrder::class, 'lens_order_id');
    }

    public function toPartner()
    {
        return $this->belongsTo(OpticalPartnerClinic::class, 'to_partner_clinic_id');
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
