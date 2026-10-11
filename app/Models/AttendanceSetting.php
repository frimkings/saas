<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** A branch's working hours by weekday and how late still counts as on time. */
class AttendanceSetting extends Model
{
    use BelongsToBranch;

    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    protected $fillable = ['hours', 'grace_minutes'];

    protected $casts = [
        'hours' => 'array',
        'grace_minutes' => 'integer',
    ];

    /** The branch's settings; Monday to Friday, 8 to 5, 15 minutes' grace until someone sets them. */
    public static function current(): self
    {
        return static::query()->first() ?? new self([
            'hours' => array_fill_keys([1, 2, 3, 4, 5], ['start' => '08:00', 'end' => '17:00']),
            'grace_minutes' => 15,
        ]);
    }

    /** ['start' => 'HH:MM', 'end' => 'HH:MM'] for an ISO weekday, or null on a day off. */
    public function hoursOn(int $weekday): ?array
    {
        return self::dayHours($this->hours ?? [], $weekday);
    }

    /** The same, from a stored hours list (the branch's or one person's). */
    public static function dayHours(array $hours, int $weekday): ?array
    {
        return $hours[$weekday] ?? $hours[(string) $weekday] ?? null;
    }
}
