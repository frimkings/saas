<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A message from the platform to clinics. See App\Services\Platform\Announcements. */
class PlatformAnnouncement extends Model
{
    public const DRAFT = 'draft';
    public const SENDING = 'sending';
    public const SENT = 'sent';

    protected $fillable = [
        'subject', 'heading', 'body', 'button_label', 'button_url', 'audience',
        'send_email', 'show_banner', 'banner_until', 'status', 'created_by', 'sent_at',
    ];

    protected $casts = [
        'audience'     => 'array',
        'send_email'   => 'boolean',
        'show_banner'  => 'boolean',
        'banner_until' => 'date',
        'sent_at'      => 'datetime',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(PlatformAnnouncementRecipient::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }
}
