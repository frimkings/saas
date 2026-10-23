<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A quotation or pro-forma invoice for a business that is not a clinic on the platform yet. It is
 * not revenue: real invoices only start once the business is onboarded (see ProspectQuotes::convert).
 * lines = [['description' => string, 'amount' => float]], amounts for one billing period.
 */
class ProspectQuote extends Model
{
    public const TYPES = ['quotation' => 'Quotation', 'proforma' => 'Pro-forma invoice'];
    public const PREFIXES = ['quotation' => 'QUO', 'proforma' => 'PF'];
    public const STATUSES = ['draft' => 'Draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined'];

    protected $fillable = ['number', 'document_type', 'pricing_request_id', 'subscription_plan_id', 'clinic_id', 'product', 'business_name',
        'contact_name', 'phone', 'email', 'address', 'billing_interval', 'currency', 'lines', 'subtotal', 'discount', 'tax_rate', 'tax', 'total',
        'issued_on', 'valid_until', 'notes', 'terms', 'status', 'token', 'sent_at', 'decided_at', 'created_by'];

    protected $casts = ['lines' => 'array', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax_rate' => 'decimal:2', 'tax' => 'decimal:2',
        'total' => 'decimal:2', 'issued_on' => 'date', 'valid_until' => 'date', 'sent_at' => 'datetime', 'decided_at' => 'datetime'];

    public function pricingRequest()
    {
        return $this->belongsTo(PricingRequest::class);
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function clinic()
    {
        return $this->belongsTo(Clinic::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->document_type] ?? 'Quotation';
    }

    /** Past its date and still waiting for an answer. */
    public function expired(): bool
    {
        return in_array($this->status, ['draft', 'sent'], true) && $this->valid_until->isBefore(today());
    }

    public function statusLabel(): string
    {
        return $this->expired() ? 'Expired' : (self::STATUSES[$this->status] ?? $this->status);
    }

    /** The link the prospect opens from an SMS or email: the document as a PDF, no sign-in. */
    public function publicUrl(): string
    {
        return route('quote.public', $this->token);
    }
}
