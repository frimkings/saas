<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class ClearanceRevokeLog extends Model
{
    use BelongsToBranch;

    protected static function booted(): void
    {
        // The sidebar's pending-approvals badge is cached; refresh it on any change.
        static::saved(fn () => \App\Support\ApprovalCounts::forget());
        static::deleted(fn () => \App\Support\ApprovalCounts::forget());
    }
    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'clearance_id',
        'status',
        'requested_by',
        'approved_by',
        'rejected_by',
        'reason',
        'rejection_reason',
        'requested_at',
        'approved_at',
        'rejected_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'approved_at'  => 'datetime',
        'rejected_at'  => 'datetime',
    ];

    public function clearance()
    {
        return $this->belongsTo(CashierPatientClearance::class, 'clearance_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public static function pendingCount(): int
    {
        return static::where('status', self::STATUS_PENDING)->count();
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING  => 'Pending',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            default               => ucfirst($this->status),
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING  => 'warning',
            self::STATUS_APPROVED => 'success',
            self::STATUS_REJECTED => 'danger',
            default               => 'secondary',
        };
    }
}
