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

    // linked_clinic_id, link_status and linked_at change only through OpticalLinkService.
    protected $casts = ['is_active' => 'boolean', 'linked_at' => 'datetime'];

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
