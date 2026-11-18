<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

class OpticalPartnerClinic extends Model
{
    use BelongsToClinic;

    public const NOTIFY_VIA = ['sms' => 'SMS (and WhatsApp buttons)', 'whatsapp' => 'WhatsApp only', 'none' => "Don't notify"];

    public const LINK_STATUSES = ['active' => 'Linked', 'paused' => 'Link paused', 'ended' => 'Unlinked'];

    protected $fillable = ['name', 'contact_person', 'phone', 'email', 'address', 'billing_terms', 'is_active', 'notification_phone', 'notify_via'];

    // linked_clinic_id, link_status and linked_at change only through OpticalLinkService; the agreed
    // terms (trade %, credit limit, auto-approve limit) only through OpticalLinkTermsService.
    protected $casts = ['is_active' => 'boolean', 'linked_at' => 'datetime', 'terms_updated_at' => 'datetime',
        'trade_lens_pct' => 'float', 'trade_frame_pct' => 'float', 'trade_service_pct' => 'float',
        'credit_limit' => 'float', 'auto_approve_limit' => 'float', 'link_paused_by_platform' => 'boolean',
        'page_pin_set_at' => 'datetime', 'page_locked_until' => 'datetime', 'page_last_used_at' => 'datetime'];

    // The ordering-page secret and PIN never leave the server in arrays or JSON.
    protected $hidden = ['page_token', 'page_pin_hash'];

    /** The ordering page for a partner not on Nexus is on (OpticalOrderPageService). */
    public function hasOrderPage(): bool
    {
        return $this->page_token !== null && $this->page_pin_hash !== null;
    }

    /** Kinds of price line the trade % applies to, with the column that holds each %. */
    public const TRADE_KINDS = ['lens' => 'trade_lens_pct', 'frame' => 'trade_frame_pct', 'service' => 'trade_service_pct'];

    /** @return array{lens: float, frame: float, service: float} trade % off, 0 where none is agreed */
    public function tradePercents(): array
    {
        return array_map(fn ($column) => (float) ($this->{$column} ?? 0), self::TRADE_KINDS);
    }

    public function hasTradeTerms(): bool
    {
        return array_sum($this->tradePercents()) > 0;
    }

    /** e.g. "lenses 10%, frames 15%" */
    public function tradeSummary(): string
    {
        $names = ['lens' => 'lenses', 'frame' => 'frames', 'service' => 'services'];
        $parts = [];
        foreach ($this->tradePercents() as $kind => $pct) {
            if ($pct > 0) $parts[] = $names[$kind].' '.rtrim(rtrim(number_format($pct, 2), '0'), '.').'%';
        }

        return $parts ? implode(', ', $parts) : 'none';
    }

    public function linkedClinic()
    {
        return $this->belongsTo(Clinic::class, 'linked_clinic_id');
    }

    /** Linked to a clinic on the platform and open for new orders. */
    public function isLinked(): bool
    {
        return $this->linked_clinic_id !== null && $this->link_status === 'active';
    }

    /** Linked to a clinic, whether active or paused. */
    public function hasLink(): bool
    {
        return $this->linked_clinic_id !== null && in_array($this->link_status, ['active', 'paused'], true);
    }

    /** Number that receives job messages: the notification phone, else the main phone. */
    public function messagingPhone(): ?string
    {
        return $this->notification_phone ?: $this->phone;
    }

    public function orders()
    {
        return $this->hasMany(LensOrder::class, 'partner_clinic_id');
    }
}
