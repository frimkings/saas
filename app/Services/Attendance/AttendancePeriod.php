<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

/** The run of days an attendance screen or export covers, from the two dates someone picked. */
class AttendancePeriod
{
    /** The longest run the summary and exports will work out. */
    public const MAX_DAYS = 366;

    /**
     * [first day, last day] from two 'Y-m-d' strings. Anything missing or unreadable falls back to
     * this month to date; dates the wrong way round are swapped; a run too long is cut at MAX_DAYS.
     */
    public static function resolve(?string $from, ?string $to, CarbonImmutable $today): array
    {
        $first = self::date($from) ?? $today->startOfMonth();
        $last = self::date($to) ?? ($first->gt($today) ? $first : $today->startOfDay());
        if ($last->lt($first)) {
            [$first, $last] = [$last, $first];
        }
        if ($first->diffInDays($last) >= self::MAX_DAYS) {
            $last = $first->addDays(self::MAX_DAYS - 1);
        }

        return [$first, $last];
    }

    /** A whole 'Y-m' month, for links made before the page took two dates. */
    public static function month(?string $month): ?array
    {
        if (! is_string($month) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return null;
        }
        $first = CarbonImmutable::createFromFormat('!Y-m', $month);

        return [$first, $first->endOfMonth()->startOfDay()];
    }

    /** 'October 2026' for a whole month, 'Mon 12 Oct 2026' for a day, otherwise '1 Oct – 12 Oct 2026'. */
    public static function label(CarbonImmutable $first, CarbonImmutable $last): string
    {
        if ($first->isSameDay($last)) {
            return $first->format('D j M Y');
        }
        if ($first->day === 1 && $last->isSameDay($first->endOfMonth())) {
            return $first->format('F Y');
        }

        return $first->format($first->year === $last->year ? 'j M' : 'j M Y').' – '.$last->format('j M Y');
    }

    public static function days(CarbonImmutable $first, CarbonImmutable $last): int
    {
        return (int) $first->diffInDays($last) + 1;
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
