<?php

namespace App\Models;

use App\Models\Concerns\AuditsAttendance;
use App\Models\Concerns\BelongsToBranch;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** An attendance terminal connected to a branch, sending punches as they happen. */
class AttendanceDevice extends Model
{
    use AuditsAttendance, BelongsToBranch;

    /** Quiet for longer than this and the terminal is shown as not connected. */
    public const ONLINE_WITHIN_HOURS = 24;

    /** Nothing this long after opening on a working day and the terminal is taken to be cut off. */
    public const SILENT_AFTER_MINUTES = 120;

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

    /**
     * Terminals that have gone silent: connected before, but nothing received since the branch
     * opened on its latest working day (today counts once the morning is well under way). Punches
     * are the only sign of life, so a day nobody punched looks the same as a terminal cut off.
     * $local is the time now in the clinic's timezone.
     */
    public static function silent(CarbonInterface $local): Collection
    {
        $settings = AttendanceSetting::current();
        $opened = null;
        for ($back = 0; $back < 7 && ! $opened; $back++) {
            $day = $local->copy()->startOfDay()->subDays($back);
            $hours = $settings->hoursOn($day->isoWeekday());
            if (! $hours || AttendanceHoliday::query()->whereDate('date', $day->toDateString())->exists()) continue;
            $opening = $day->copy()->setTimeFromTimeString($hours['start']);
            if ($local->gte($opening->copy()->addMinutes(self::SILENT_AFTER_MINUTES))) $opened = $opening;
        }
        if (! $opened) return new Collection();

        // With everyone followed on leave that day, silence is what to expect.
        $date = $opened->toDateString();
        $expected = AttendanceStaff::query()->where('is_tracked', true)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $date))
            ->whereNotIn('id', AttendanceLeave::query()->approved()->whereNull('from_time')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->select('attendance_staff_id'))
            ->exists();
        if (! $expected) return new Collection();

        return static::query()->where('is_active', true)->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $opened->copy()->setTimezone(config('app.timezone')))->orderBy('id')->get();
    }

    /** What the terminal reports by itself is not anyone's change. */
    protected array $auditIgnore = ['token', 'last_seen_at', 'first_event_at', 'last_event_at', 'punches_received'];

    protected function auditNoun(): string
    {
        return 'terminal';
    }
}
