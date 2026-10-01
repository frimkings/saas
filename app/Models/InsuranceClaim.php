<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToBranch;

class InsuranceClaim extends Model
{
    use SoftDeletes, BelongsToBranch;

    protected $fillable = [
        'patient_id', 'insurer_id', 'sale_id',
        'member_id', 'member_name', 'policy_number',
        'claim_amount', 'approved_amount', 'amount_received',
        'shortfall_amount', 'shortfall_action',
        'status',
        'submission_date', 'approval_date', 'payment_date',
        'rejection_reason', 'notes',
        'pre_auth_status', 'pre_auth_code', 'pre_auth_amount',
        'pre_auth_date', 'pre_auth_expiry_date', 'pre_auth_notes',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'claim_amount'        => 'decimal:2',
        'approved_amount'     => 'decimal:2',
        'amount_received'     => 'decimal:2',
        'shortfall_amount'    => 'decimal:2',
        'pre_auth_amount'     => 'decimal:2',
        'submission_date'     => 'date',
        'approval_date'       => 'date',
        'payment_date'        => 'date',
        'pre_auth_date'       => 'date',
        'pre_auth_expiry_date'=> 'date',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sales::class, 'sale_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeByStatus(Builder $q, string $status): Builder
    {
        return $q->where('status', $status);
    }

    public function scopeDateRange(Builder $q, ?string $from, ?string $to): Builder
    {
        return $q->when($from, fn ($q) => $q->whereDateIndexed('created_at', '>=', $from))
                 ->when($to,   fn ($q) => $q->whereDateIndexed('created_at', '<=', $to));
    }

    public function scopeForPatient(Builder $q, int $patientId): Builder
    {
        return $q->where('patient_id', $patientId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function statusBadgeClass(): string
    {
        return match($this->status) {
            'draft'              => 'badge-secondary',
            'submitted'          => 'badge-primary',
            'approved'           => 'badge-success',
            'partially_approved' => 'badge-warning',
            'rejected'           => 'badge-danger',
            'paid'               => 'badge-dark',
            default              => 'badge-light',
        };
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            'draft'              => 'Draft',
            'submitted'          => 'Submitted',
            'approved'           => 'Approved',
            'partially_approved' => 'Part. Approved',
            'rejected'           => 'Rejected',
            'paid'               => 'Paid',
            default              => ucfirst($this->status),
        };
    }

    /** Statuses an insurer payment can be recorded against. */
    public const PAYABLE_STATUSES = ['submitted', 'approved', 'partially_approved'];

    /** What the insurer is expected to pay: the approved amount once known, else the claim. */
    public function expectedAmount(): float
    {
        return round((float) ($this->approved_amount ?? $this->claim_amount), 2);
    }

    /** What the insurer still owes on this claim. */
    public function outstandingAmount(): float
    {
        if (in_array($this->status, self::PAYABLE_STATUSES, true)) {
            return max(0, round($this->expectedAmount() - (float) $this->amount_received, 2));
        }
        return 0.0;
    }

    public function allocations()
    {
        return $this->hasMany(InsurerPaymentAllocation::class);
    }

    public function preAuthBadgeClass(): string
    {
        return match($this->pre_auth_status) {
            'pending'      => 'badge-warning',
            'approved'     => 'badge-success',
            'rejected'     => 'badge-danger',
            default        => 'badge-light text-muted',
        };
    }

    public function preAuthLabel(): string
    {
        return match($this->pre_auth_status) {
            'pending'      => 'Pre-Auth Pending',
            'approved'     => 'Pre-Auth OK',
            'rejected'     => 'Pre-Auth Rejected',
            default        => 'No Pre-Auth',
        };
    }

    public function getPreAuthExpiredAttribute(): bool
    {
        return $this->pre_auth_expiry_date
            && $this->pre_auth_status === 'approved'
            && $this->pre_auth_expiry_date->isPast();
    }
}
