<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email address (or, for a clinic with none, the clinic itself) an announcement goes to. */
class PlatformAnnouncementRecipient extends Model
{
    public const QUEUED = 'queued';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    protected $fillable = ['platform_announcement_id', 'clinic_id', 'user_id', 'role', 'email', 'status', 'error', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(PlatformAnnouncement::class, 'platform_announcement_id');
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
