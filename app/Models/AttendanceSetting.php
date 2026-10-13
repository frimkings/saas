<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** A branch's working hours by weekday and how late still counts as on time. */
class AttendanceSetting extends Model
{
    use BelongsToBranch;

    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    protected $fillable = ['hours', 'grace_minutes', 'break_minutes', 'overtime_after_minutes', 'overtime_needs_approval', 'min_rest_hours', 'max_nights_in_row',
        'overtime_multiplier', 'deduct_lateness', 'deduct_absence',
        'phone_clock_in', 'phone_clock_in_since', 'clock_in_latitude', 'clock_in_longitude', 'clock_in_radius'];

    // Until a branch saves its own: overtime from half an hour past the end, approved by a manager
    // and paid at time and a half; absences come off a salary, lateness does not.
    protected $attributes = ['overtime_after_minutes' => 30, 'overtime_needs_approval' => true, 'overtime_multiplier' => 1.5, 'deduct_lateness' => false, 'deduct_absence' => true,
        'phone_clock_in' => false, 'clock_in_radius' => 150];

    protected $casts = [
        'hours' => 'array',
        'grace_minutes' => 'integer',
        'break_minutes' => 'integer',
        'overtime_after_minutes' => 'integer',
        'overtime_needs_approval' => 'boolean',
        'min_rest_hours' => 'integer',
        'max_nights_in_row' => 'integer',
        'overtime_multiplier' => 'float',
        'deduct_lateness' => 'boolean',
        'deduct_absence' => 'boolean',
        'phone_clock_in' => 'boolean',
        'phone_clock_in_since' => 'date',
        'clock_in_latitude' => 'float',
        'clock_in_longitude' => 'float',
        'clock_in_radius' => 'integer',
    ];

    /** The branch's settings; Monday to Friday, 8 to 5, 15 minutes' grace until someone sets them. */
    public static function current(): self
    {
        return static::query()->first() ?? new self([
            'hours' => array_fill_keys([1, 2, 3, 4, 5], ['start' => '08:00', 'end' => '17:00']),
            'grace_minutes' => 15,
            'break_minutes' => 60,
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
