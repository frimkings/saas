<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** A clinic that refers patients for diagnostic scans and earns commission on them. */
class ScanPartnerClinic extends Model
{
    use BelongsToClinic;

    public const NOTIFY_VIA = ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'Email', 'none' => "Don't send"];

    public const PAYOUT_METHODS = ['cash' => 'Cash', 'mobile_money' => 'Mobile money', 'bank' => 'Bank transfer'];

    protected $fillable = ['code', 'name', 'contact_person', 'phone', 'email', 'address', 'notification_phone', 'notify_via',
        'payout_method', 'payout_account', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'payout_account' => 'encrypted'];

    protected $hidden = ['payout_account'];

    public function doctors()
    {
        return $this->hasMany(ScanPartnerDoctor::class);
    }

    public function rates()
    {
        return $this->hasMany(ScanPartnerRate::class);
    }

    /** Number that receives results: the notification phone, else the main phone. */
    public function messagingPhone(): ?string
    {
        return $this->notification_phone ?: $this->phone;
    }

    /** The next free code for this clinic: RC-001, RC-002… */
    public static function nextCode(): string
    {
        $highest = static::query()->where('code', 'like', 'RC-%')->pluck('code')
            ->map(fn ($code) => (int) substr($code, 3))->max() ?? 0;

        return 'RC-'.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }
}
