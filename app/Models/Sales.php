<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToBranch;

class Sales extends Model
{
    use SoftDeletes, BelongsToBranch;


protected $fillable = [
    'business_line',
    'user_id',
    'patient_id',
    'patient_visit_id',
    'insurer_id',
    'customer_name',
    'customer_phone',
    'consultation_id',
    'transaction_id',
    'idempotency_key',
    'total_amount',
    'amount_paid',
    'insurer_amount',
    'payment_status',
    'bill_status',
    'bill_version',
    'expires_at',
    'finalized_at',
    'finalized_by',
    'profit',
    'discount_type',
    'discount_value',
    'discount_amount',
    'discount_approved_by',
    'is_refunded',
    'refunded_at',
    'refunded_by',
    'refund_reason',
];

protected $casts = [
    'is_refunded'     => 'boolean',
    'refunded_at'     => 'datetime',
    'total_amount'    => 'decimal:2',
    'amount_paid'     => 'decimal:2',
    'insurer_amount'  => 'decimal:2',
    'discount_value'  => 'decimal:2',
    'discount_amount' => 'decimal:2',
    'finalized_at'     => 'datetime',
    'expires_at'       => 'datetime',
    'bill_version'     => 'integer',
];

/**
 * What the patient still owes, in SQL. The insurer's share is not the patient's debt:
 * it is collected from the insurer (see insurer receivables).
 */
public const PATIENT_BALANCE_SQL = 'GREATEST(total_amount - insurer_amount - amount_paid, 0)';

public function getRemainingBalanceAttribute(): float
{
    return max(0, round((float) $this->total_amount - (float) $this->insurer_amount - (float) $this->amount_paid, 2));
}

/** The part of the bill the patient pays (the whole bill when no insurer is billed). */
public function getPatientShareAttribute(): float
{
    return max(0, round((float) $this->total_amount - (float) $this->insurer_amount, 2));
}

/** What the insurer still owes on a bill: its share less anything received on the claim, in SQL. */
public const INSURER_OWED_SQL = '(insurer_amount - COALESCE((SELECT ic.amount_received FROM insurance_claims ic WHERE ic.sale_id = sales.id AND ic.deleted_at IS NULL LIMIT 1), 0))';

/** Insured bills the insurer has neither paid nor rejected yet. */
public function scopeAwaitingInsurer($query)
{
    return $query->where('insurer_amount', '>', 0)
        ->where('is_refunded', false)
        ->whereDoesntHave('insuranceClaim', fn ($claim) => $claim->whereIn('status', ['paid', 'rejected']));
}

public function visit()
{
    return $this->belongsTo(PatientVisit::class, 'patient_visit_id');
}

public function insurer()
{
    return $this->belongsTo(Insurer::class);
}

public function insuranceClaim(): HasOne
{
    return $this->hasOne(InsuranceClaim::class, 'sale_id');
}

public function getCustomerDisplayNameAttribute(): string
{
    return $this->patient?->name ?: ($this->customer_name ?: 'Walk-in');
}

public function isFullyPaid(): bool
{
    return $this->payment_status === 'paid';
}

public function paymentTransactions()
{
    return $this->hasMany(\App\Models\PaymentTransaction::class, 'sale_id')->orderBy('created_at');
}

public function scopeClinicSales($query)
{
    return $query->where('business_line', 'clinic');
}

public function scopeOpticalSales($query)
{
    return $query->where('business_line', 'optical');
}

public function clearance()
{
    return $this->hasOne(CashierPatientClearance::class, 'sale_id');
}

public function isOpenVisitBill(): bool
{
    return $this->bill_status === 'open'
        && (!$this->expires_at || $this->expires_at->isFuture());
}

/**
 * A refund needs a closed bill. A fully paid open visit bill (e.g. today's consultation fee,
 * which stays open until the end of the day) is closed now, as the till does before a separate
 * sale; later items for the visit start a new bill. Returns false while a balance is still owed.
 */
public function finalizeForRefund(): bool
{
    if ($this->bill_status !== 'open') {
        return true;
    }
    if ($this->payment_status !== 'paid') {
        return false;
    }

    $old = $this->only(['bill_status', 'bill_version', 'finalized_at']);
    $this->update([
        'bill_status' => 'finalized',
        'finalized_at' => now(),
        'finalized_by' => auth()->id(),
        'bill_version' => (int) $this->bill_version + 1,
    ]);
    AuditTrail::record('visit_bill.finalized_for_refund', "Visit bill {$this->transaction_id} finalized for a refund request",
        $this, $old, $this->only(['bill_status', 'bill_version', 'finalized_at', 'finalized_by']), $this->patient_id, true);

    return true;
}

public function adjustments()
{
    return $this->hasMany(SaleAdjustment::class, 'sale_id');
}


    public function items()
    {
        return $this->hasMany(SaleItem::class,  'sale_id');
    }

   

public function patient()
{
    return $this->belongsTo(Patient::class);
}

public function consultation()
{
    return $this->belongsTo(Consultations::class, 'consultation_id');
}

public function user()
{
    return $this->belongsTo(User::class);
}


public function approvedBy()
{
    return $this->belongsTo(User::class, 'discount_approved_by');
}

public function refundedBy()
{
    return $this->belongsTo(User::class, 'refunded_by');
}

public function refundLogs()
{
    return $this->hasMany(\App\Models\RefundLog::class, 'sale_id')->orderBy('created_at', 'desc');
}

public function pendingRefundLog(): HasOne
{
    return $this->hasOne(RefundLog::class, 'sale_id')
                ->whereIn('status', [RefundLog::STATUS_PENDING, RefundLog::STATUS_APPROVED])
                ->latest();
}






}
