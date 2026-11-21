<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A spectacle order a clinic sends to a linked optical centre. It sits between the two clinics
 * (no tenant scope): the clinic reads its rows by sender_clinic_id, the optical centre by
 * optical_clinic_id. Holds only the wearer's name and phone and the prescription.
 */
class OpticalOrderRequest extends Model
{
    public const STATUSES = [
        'sent' => 'Waiting for the optical centre',
        'query' => 'Question from the optical centre',
        'quoted' => 'Quote waiting for your approval',
        'approved' => 'Quote approved, waiting to start',
        'accepted' => 'Accepted',
        'quote_declined' => 'Quote declined',
        'rejected' => 'Turned down',
        'cancelled' => 'Cancelled by the clinic',
    ];

    public const FRAME_CHOICES = [
        'patient_chooses' => 'Patient will choose a frame at the optical centre',
        'own_frame' => "Patient's own frame",
        'note' => 'Frame described in the notes',
    ];

    public const OPEN = ['sent', 'query'];

    /** Still going somewhere: the refraction can't be sent again while one of these exists. */
    public const ACTIVE = ['sent', 'query', 'quoted', 'approved', 'accepted'];

    public const KINDS = ['order' => 'Order', 'remake' => 'Remake'];

    protected $fillable = ['sender_clinic_id', 'sender_branch_id', 'sent_by', 'optical_clinic_id', 'optical_partner_clinic_id',
        'refraction_id', 'sender_reference', 'patient_name', 'patient_phone', 'rx', 'lens_type', 'lens_notes', 'frame_choice',
        'frame_notes', 'urgency', 'notes', 'status', 'query_message', 'query_answer', 'reject_reason', 'lens_order_id',
        'order_number', 'order_status', 'status_updated_at', 'handled_by', 'kind', 'remake_of_request_id', 'remake_reason',
        'source', 'sender_name', 'lens_choice'];

    protected $casts = ['rx' => 'array', 'lens_choice' => 'array', 'status_updated_at' => 'datetime', 'quote_lines' => 'array', 'quote_total' => 'decimal:2',
        'quote_valid_until' => 'date', 'quoted_at' => 'datetime', 'approved_at' => 'datetime', 'order_total' => 'decimal:2',
        'order_paid' => 'decimal:2', 'order_cancelled_at' => 'datetime', 'auto_approved' => 'boolean',
        'cancel_requested_at' => 'datetime', 'cancel_answered_at' => 'datetime', 'cancel_charged_at' => 'datetime'];

    public function scopeForOptical(Builder $query, int $clinicId): Builder
    {
        return $query->where('optical_clinic_id', $clinicId);
    }

    public function scopeForSender(Builder $query, int $clinicId): Builder
    {
        return $query->where('sender_clinic_id', $clinicId);
    }

    public function senderClinic()
    {
        return $this->belongsTo(Clinic::class, 'sender_clinic_id');
    }

    /** The optical centre's partner record this request came through. */
    public function partner()
    {
        return $this->belongsTo(OpticalPartnerClinic::class, 'optical_partner_clinic_id')->withoutGlobalScopes();
    }

    /** Sent from the ordering page by a partner that is not on Nexus (OpticalOrderPageService). */
    public function fromPage(): bool
    {
        return $this->source === 'page' && $this->sender_clinic_id === null;
    }

    /** Who sent it, as the optical centre sees it. */
    public function senderLabel(): string
    {
        if ($this->sender_clinic_id) return (string) ($this->senderClinic?->name ?? 'A linked clinic');

        return ($this->partner?->name ?? 'Partner clinic').' (ordering page'.($this->sender_name ? ', '.$this->sender_name : '').')';
    }

    public function opticalClinic()
    {
        return $this->belongsTo(Clinic::class, 'optical_clinic_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function remakeOf()
    {
        return $this->belongsTo(self::class, 'remake_of_request_id');
    }

    public function isRemake(): bool
    {
        return $this->kind === 'remake';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** What the clinic sees: the request's state, or the order's once accepted. */
    public function progressLabel(): string
    {
        if ($this->status === 'accepted' && $this->order_status) {
            return $this->order_status === 'Quotation' ? 'Being priced' : $this->order_status;
        }
        if ($this->status === 'quoted' && $this->quoteExpired()) return 'Quote expired';

        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function quoteExpired(): bool
    {
        return $this->status === 'quoted' && $this->quote_valid_until && $this->quote_valid_until->lt(today());
    }

    /** What is still owed on the job, as the optical centre's account shows it. */
    public function balance(): float
    {
        if ($this->status !== 'accepted' || $this->order_cancelled_at || $this->order_total === null) return 0.0;

        return round(max(0, (float) $this->order_total - (float) $this->order_paid), 2);
    }

    /**
     * The clinic may ask the optical centre to cancel a job it approved or that is under way, until the
     * glasses are ready (then it is a remake), and while no earlier ask is waiting for an answer.
     */
    public function canAskCancel(): bool
    {
        return in_array($this->status, ['approved', 'accepted'], true) && ! $this->order_cancelled_at && ! $this->cancelPending()
            && ! in_array($this->order_status, ['Ready for Collection', 'Ready', 'Collected'], true);
    }

    /** The clinic asked to cancel and the optical centre has not answered yet. */
    public function cancelPending(): bool
    {
        return $this->cancel_requested_at && ! $this->cancel_answered_at && ! $this->order_cancelled_at;
    }

    /** Ready or collected: the clinic may ask for a remake. */
    public function canAskRemake(): bool
    {
        return $this->status === 'accepted' && in_array($this->order_status, ['Ready for Collection', 'Ready', 'Collected'], true);
    }

    /** "R -1.25 / -0.50 x 180 Add +2.00" for one eye. */
    public function eyeLine(string $eye): string
    {
        $rx = (array) data_get($this->rx, $eye, []);
        $fmt = fn ($v) => $v === null || $v === '' ? null : (is_numeric($v) ? sprintf('%+.2f', (float) $v) : (string) $v);
        $parts = array_filter([
            $fmt($rx['sph'] ?? null) ?? 'Plano',
            ($c = $fmt($rx['cyl'] ?? null)) ? $c.(filled($rx['axis'] ?? null) ? ' x '.$rx['axis'] : '') : null,
            ($a = $fmt($rx['add'] ?? null)) ? 'Add '.$a : null,
        ]);

        return implode(' / ', $parts);
    }
}
