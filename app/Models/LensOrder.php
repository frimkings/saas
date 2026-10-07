<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\RecordsUnitCost;

class LensOrder extends Model
{
    use HasFactory, SoftDeletes, BelongsToBranch, RecordsUnitCost;

    protected function unitCostSources(): array
    {
        return [
            'frame_unit_cost' => ['frame_optical_product_id' => OpticalProduct::class],
            'lens_unit_cost' => ['lens_optical_product_id' => OpticalProduct::class],
        ];
    }

    protected $fillable = [
        'frame_model_number',
        'frame_product_id',
        'own_frame',
        'lens_product_id',
        'frame_optical_product_id',
        'lens_optical_product_id',
        'frame_unit_cost',
        'lens_unit_cost',
        'lens_supply_source',
        'stock_lens_index',
        'stock_lens_coating',
        'lens_blank_allocations',
        'frame_price',
        'lens_price',
        'lab_cost',
        'stock_reserved_at',
        'frame_stock_from_sale',
        'lens_stock_from_sale',
        'notes',
        'pickUpDate',
        'refraction_id',
        'patient_id',
        'optical_prescription_id',
        'sale_id',
        'prescription_snapshot',
        'glazing_fee',
        'discount_amount',
        'service_total',
        'work_type',
        'partner_clinic_name',
        'partner_clinic_id',
        'partner_billing_terms',
        'order_source',
        'customer_name',
        'customer_phone',
        'bill_to',
        'user_id',
        'order_id',
        'status',
        'paid_amount',
        'collected_at',
        'aftercare_sent_at',
        'cancelled_at',
        'renewal_date',
        'renewal_reminder_sent_at',
        'renewal_approval_status',
        'renewal_approved_by',
        'renewal_actioned_at',
        'remake_of_id',
        'remake_reason',
        'remake_charge',
        'warranty_expires_at',
        'cancellation_fee',
        'cancellation_reason',
        'refund_log_id',
        'ready_at',
        'ready_notified_at',
        'collection_reminders_sent',
        'last_collection_reminder_at',
        'lab_supplier_id',
        'sent_to_lab_at',
        'expected_back_at',
        'status_changed_at',
        'quote_valid_until',
    ];

    public const REMAKE_REASONS = [
        'lab_error' => 'Lab or glazing error',
        'defect' => 'Lens defect',
        'breakage' => 'Breakage or damage',
        'rx_change' => 'Prescription change',
        'non_tolerance' => 'Customer cannot adapt',
        'other' => 'Other',
    ];

    /** Ready to collect: the Optical Suite marks "Ready for Collection", the clinic Spectacles page "Ready". */
    public const READY = ['Ready for Collection', 'Ready'];

    /** At an outside or in-house lab, under either page's status names. */
    public const AT_LAB = ['Sent to Lab', 'In Lab', 'In Production'];

    /** The job's total as SQL; empty prices count as 0. */
    public const TOTAL_SQL = '(COALESCE(frame_price,0)+COALESCE(lens_price,0)+COALESCE(glazing_fee,0)+COALESCE(service_total,0)-COALESCE(discount_amount,0))';

    /**
     * Jobs with money still to pay on the order itself. Clinic spectacle orders are paid on
     * the consultation bill (App\Support\Optical\ClinicSpectacleBilling), so they are left out.
     */
    public function scopeBalanceDue($query)
    {
        return $query->whereNotIn('status', ['Quotation', 'Cancelled'])
            ->where(fn ($q) => $q->whereNotNull('sale_id')->orWhereNull('refraction_id'))
            ->whereRaw(self::TOTAL_SQL.' > COALESCE(paid_amount,0) + 0.004');
    }

    protected $casts = [
        'lab_cost'                 => 'decimal:2',
        'glazing_fee'              => 'decimal:2',
        'discount_amount'          => 'decimal:2',
        'service_total'             => 'decimal:2',
        'prescription_snapshot'    => 'array',
        'lens_blank_allocations'   => 'array',
        'stock_reserved_at'        => 'datetime',
        'own_frame'                => 'boolean',
        'frame_stock_from_sale'    => 'boolean',
        'lens_stock_from_sale'     => 'boolean',
        'collected_at'             => 'datetime',
        'aftercare_sent_at'        => 'datetime',
        'cancelled_at'             => 'datetime',
        'renewal_date'             => 'date',
        'renewal_reminder_sent_at' => 'datetime',
        'sent_to_lab_at' => 'datetime',
        'expected_back_at' => 'date',
        'status_changed_at' => 'datetime',
        'quote_valid_until' => 'date',
        'renewal_actioned_at'      => 'datetime',
        'warranty_expires_at'      => 'date',
        'cancellation_fee'         => 'decimal:2',
        'ready_at'                 => 'datetime',
        'ready_notified_at'        => 'datetime',
        'collection_reminders_sent' => 'integer',
        'last_collection_reminder_at' => 'datetime',
    ];

    /** Days the glasses have been waiting for collection. */
    public function daysAwaitingCollection(): int
    {
        $since = $this->ready_at ?? $this->updated_at;
        return $since ? (int) $since->copy()->startOfDay()->diffInDays(now()->startOfDay()) : 0;
    }

    /** Supplier order lines buying lenses for this job. */
    public function purchaseOrderLines()
    {
        return $this->hasMany(OpticalPurchaseOrderLine::class, 'lens_order_id');
    }

    public function remakeOf()
    {
        return $this->belongsTo(self::class, 'remake_of_id');
    }

    public function remakes()
    {
        return $this->hasMany(self::class, 'remake_of_id');
    }

    public function refundLog()
    {
        return $this->belongsTo(RefundLog::class, 'refund_log_id');
    }

    /** Work sent in by a partner clinic: its messages go to the partner, not the wearer. */
    public function isPartnerJob(): bool
    {
        return $this->order_source === 'partner' && $this->partner_clinic_id !== null;
    }

    /** The partner's own job reference, from the order docket. */
    public function partnerReference(): string
    {
        return (string) data_get(json_decode((string) $this->notes, true), 'reference', '');
    }

    public function isUnderWarranty(): bool
    {
        return $this->warranty_expires_at !== null && $this->warranty_expires_at->endOfDay()->isFuture();
    }

    /**
     * Who to remind about renewal: a registered patient, a clinic refraction's
     * patient, or the walk-in/partner customer recorded on the order.
     *
     * @return array{name: string, phone: ?string, patient_id: ?int}|null
     */
    public function renewalRecipient(): ?array
    {
        // A partner clinic's patients are theirs to recall, not ours.
        if ($this->isPartnerJob()) return null;
        $patient = $this->customer;
        if ($patient) return ['name' => $patient->name, 'phone' => $patient->contact ?: $this->customer_phone, 'patient_id' => $patient->id];
        if (! $this->customer_name && ! $this->customer_phone) return null;
        return ['name' => $this->customer_name ?: 'Customer', 'phone' => $this->customer_phone, 'patient_id' => null];
    }

    public function renewalApprovedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'renewal_approved_by');
    }

    public function refraction()
    {
        return $this->belongsTo(Refractions::class, 'refraction_id');
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function opticalPrescription()
    {
        return $this->belongsTo(OpticalPrescription::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sales::class, 'sale_id');
    }

    public function serviceLines()
    {
        return $this->hasMany(OpticalOrderServiceLine::class, 'lens_order_id');
    }

    public function lensLines()
    {
        return $this->hasMany(OpticalOrderLensLine::class, 'lens_order_id')->orderBy('eye');
    }

    public function partnerClinic()
    {
        return $this->belongsTo(OpticalPartnerClinic::class, 'partner_clinic_id');
    }

    public function getDisplayCustomerNameAttribute(): string
    {
        return $this->customer?->name ?: ($this->customer_name ?: ($this->partner_clinic_name ?: 'Walk-in'));
    }

    public function getDisplayCustomerPhoneAttribute(): ?string
    {
        return $this->customer?->contact ?: $this->customer_phone;
    }

    /** Job details (frame source, lens details, lab instructions) kept as JSON in notes; plain notes on older orders. */
    public function docketDetails(): array
    {
        $details = json_decode($this->notes ?? '', true);
        return is_array($details) ? $details : ['notes' => $this->notes];
    }

    public function getCustomerAttribute(): ?Patient
    {
        return $this->patient ?: $this->refraction?->consultation?->patient;
    }

    public function getTotalAttribute(): float
    {
        return max(0, (float) $this->frame_price + (float) $this->lens_price
            + (float) $this->glazing_fee + (float) $this->service_total - (float) $this->discount_amount);
    }

    /** The outside lab (a supplier) the job was sent to, if any. */
    public function labSupplier()
    {
        return $this->belongsTo(Supplier::class, 'lab_supplier_id');
    }

    public function isQuoteExpired(): bool
    {
        return $this->status === 'Quotation' && $this->quote_valid_until && $this->quote_valid_until->lt(today());
    }

    public function frameProduct()
    {
        return $this->belongsTo(Product::class, 'frame_product_id');
    }

    public function lensProduct()
    {
        return $this->belongsTo(Product::class, 'lens_product_id');
    }

    public function frameOpticalProduct()
    {
        return $this->belongsTo(OpticalProduct::class, 'frame_optical_product_id')->withTrashed();
    }

    public function lensOpticalProduct()
    {
        return $this->belongsTo(OpticalProduct::class, 'lens_optical_product_id')->withTrashed();
    }

    /** The staff member who created the order. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Status changes, oldest first, with who made each one. */
    public function events()
    {
        return $this->hasMany(LensOrderEvent::class)->orderBy('id');
    }

    protected static function booted(): void
    {
        // Every screen and service changes status through the model, so record each change here.
        static::updated(function (LensOrder $order): void {
            if (! $order->wasChanged('status')) return;
            $order->events()->create([
                'clinic_id' => $order->clinic_id, 'branch_id' => $order->branch_id, 'user_id' => auth()->id(),
                'from_status' => $order->getOriginal('status'), 'to_status' => (string) $order->status,
                'note' => $order->status === 'Cancelled' && $order->cancellation_reason ? mb_substr($order->cancellation_reason, 0, 500) : null,
            ]);
        });
    }
}
