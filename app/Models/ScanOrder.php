<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Scans ordered for one patient: who referred them, the scans, the sale that charged for them,
 * and where the work is up to. Payment lives on the sale (paid, part paid); the status here is
 * the work: registered → scanned → reported → sent, or cancelled before checkout.
 */
class ScanOrder extends Model
{
    use BelongsToBranch, SoftDeletes;

    public const SOURCES = ['partner' => 'Partner clinic', 'internal' => 'Our doctor', 'self' => 'Self-referred'];

    public const STATUSES = ['registered' => 'Registered', 'scanned' => 'Scanned', 'reported' => 'Reported', 'sent' => 'Results sent', 'cancelled' => 'Cancelled'];

    protected $fillable = ['order_number', 'patient_id', 'source', 'scan_partner_clinic_id', 'scan_partner_doctor_id', 'referrer_note',
        'ordered_by', 'consultation_id', 'sale_id', 'status', 'total_amount', 'share_consent', 'consent_by', 'consent_at', 'checked_out_at',
        'scanned_at', 'scanned_by', 'reported_at', 'reported_by', 'results_sent_at', 'cancelled_at', 'cancel_reason', 'notes', 'created_by'];

    protected $casts = [
        'total_amount' => 'decimal:2', 'share_consent' => 'boolean', 'consent_at' => 'datetime', 'checked_out_at' => 'datetime',
        'scanned_at' => 'datetime', 'reported_at' => 'datetime', 'results_sent_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function partner()
    {
        return $this->belongsTo(ScanPartnerClinic::class, 'scan_partner_clinic_id');
    }

    public function partnerDoctor()
    {
        return $this->belongsTo(ScanPartnerDoctor::class, 'scan_partner_doctor_id');
    }

    public function orderedBy()
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function sale()
    {
        return $this->belongsTo(Sales::class, 'sale_id');
    }

    public function items()
    {
        return $this->hasMany(ScanOrderItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCheckedOut(): bool
    {
        return $this->sale_id !== null;
    }

    /** Paid in full, part paid, or not yet billed. */
    public function paymentState(): string
    {
        if (! $this->sale_id) return 'unbilled';
        $status = $this->sale?->payment_status;

        return $status === 'paid' ? 'paid' : ($status === 'partial' ? 'partial' : 'unpaid');
    }

    /** "Hilltop Eye Clinic · Dr Mensah", "Dr Owusu (ours)" or "Self-referred". */
    public function referrerLabel(): string
    {
        return match ($this->source) {
            'partner' => trim(($this->partner?->name ?? 'Partner clinic').($this->partnerDoctor ? ' · '.$this->partnerDoctor->name : '')),
            'internal' => ($this->orderedBy?->name ?? 'Our doctor').' (ours)',
            default => 'Self-referred',
        };
    }

    /** The next order number for this clinic and year: SCN-2026-00001. */
    public static function nextNumber(): string
    {
        $prefix = 'SCN-'.now()->format('Y').'-';
        $last = static::withoutGlobalScope('branch')->withTrashed()->where('order_number', 'like', $prefix.'%')
            ->orderByDesc('order_number')->value('order_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
    }
}
