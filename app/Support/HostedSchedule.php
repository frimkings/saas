<?php

namespace App\Support;

use Cron\CronExpression;
use Illuminate\Support\Carbon;

/**
 * Hosted (Laravel Cloud) task timing. Cloud bills the app and database for every minute they are
 * awake, and wakes them at each task's cron time (read from schedule:list at deploy). So routine
 * tasks run only in the clinic day (default 07:00–18:59), and on quiet days (default Sunday, when
 * clinics hardly work) they run once, at the last day hour, to catch up. Nothing runs overnight.
 * A ->between() filter would not help: Cloud would still wake the app every minute to check it.
 */
class HostedSchedule
{
    /** Routine tasks on working days: every N minutes ('*' + '/30' style) or at minute 0 hourly. */
    public static function daytime(string $minutes): string
    {
        [$from, $to] = self::hours();

        return "{$minutes} {$from}-{$to} * * ".self::workingDays();
    }

    /** The one catch-up run on quiet days, or null when every day is a working day. */
    public static function quietDayRun(): ?string
    {
        $quiet = self::quietDays();
        if ($quiet === []) {
            return null;
        }

        return '0 '.self::hours()[1].' * * '.implode(',', $quiet);
    }

    /** A morning task (the jobs that used to run at night) at the first day hour, "HH:MM". */
    public static function morning(int $minute): string
    {
        return sprintf('%02d:%02d', self::hours()[0], $minute);
    }

    /** @return array{int, int} first and last daytime hour, in the app timezone */
    public static function hours(): array
    {
        [$from, $to] = array_map('intval', explode('-', (string) config('tenancy.schedule_day_hours', '7-18')) + [1 => 18]);

        return [max(0, min(23, $from)), max(0, min(23, $to))];
    }

    /** @return int[] days of the week (0 = Sunday) when routine tasks run only once */
    public static function quietDays(): array
    {
        $days = array_filter(array_map('trim', explode(',', (string) config('tenancy.schedule_quiet_days', '0'))), 'is_numeric');
        $days = array_values(array_unique(array_map(fn ($day) => ((int) $day) % 7, $days)));
        sort($days);

        return count($days) >= 7 ? [] : $days;
    }

    private static function workingDays(): string
    {
        $quiet = self::quietDays();

        return $quiet === [] ? '*' : implode(',', array_values(array_diff(range(0, 6), $quiet)));
    }

    /**
     * Hours since an hourly routine task last ran before $now (1 on a working-day hour; longer
     * after the night or a quiet day), so a task can cover what fell in the gap. 1 offline.
     */
    public static function hoursSincePreviousRun(?Carbon $now = null): float
    {
        if (! config('tenancy.enabled')) {
            return 1;
        }

        $now = ($now ?? Carbon::now())->copy();
        $previous = collect([self::daytime('0'), self::quietDayRun()])->filter()
            ->map(fn ($cron) => Carbon::instance((new CronExpression($cron))->getPreviousRunDate($now, 0, false, $now->getTimezone()->getName())))
            ->max();

        return max(1, $previous->diffInMinutes($now, true) / 60);
    }

    /** Minutes the scheduler heartbeat may be silent before it counts as stopped. */
    public static function heartbeatToleranceMinutes(?Carbon $now = null): int
    {
        if (! config('tenancy.enabled')) {
            return 5; // offline: Windows Task Scheduler runs every minute
        }

        // Quiet on purpose overnight and on quiet days: allow back to the last scheduled run.
        $now = ($now ?? Carbon::now())->copy();
        $last = collect([self::daytime('0'), self::quietDayRun()])->filter()
            ->map(fn ($cron) => Carbon::instance((new CronExpression($cron))->getPreviousRunDate($now, 0, true, $now->getTimezone()->getName())))
            ->max();

        return max(70, (int) ceil($last->diffInMinutes($now, true)) + 10);
    }
}
