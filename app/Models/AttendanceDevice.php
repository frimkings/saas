<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** An attendance terminal connected to a branch, sending punches as they happen. */
class AttendanceDevice extends Model
{
    use BelongsToBranch;

    /** Quiet for longer than this and the terminal is shown as not connected. */
    public const ONLINE_WITHIN_HOURS = 24;

    protected $fillable = ['name', 'is_active'];

    protected $hidden = ['token'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'first_event_at' => 'datetime',
        'last_event_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $device) => $device->token ??= Str::random(48));
    }

    /** The path the terminal posts to; the full address is this on the app's own host. */
    public function pushPath(): string
    {
        return route('attendance.push', ['token' => $this->token], false);
    }

    public function isOnline(): bool
    {
        return $this->is_active && $this->last_seen_at?->gt(now()->subHours(self::ONLINE_WITHIN_HOURS));
    }
}
