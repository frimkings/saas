<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

class OpticalPartnerClinic extends Model
{
    use BelongsToClinic;

    public const NOTIFY_VIA = ['sms' => 'SMS (and WhatsApp buttons)', 'whatsapp' => 'WhatsApp only', 'none' => "Don't notify"];

    protected $fillable = ['name', 'contact_person', 'phone', 'email', 'address', 'billing_terms', 'is_active', 'notification_phone', 'notify_via'];

    protected $casts = ['is_active' => 'boolean'];

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
