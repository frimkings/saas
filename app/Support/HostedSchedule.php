<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Hosted (Laravel Cloud) task timing. Cloud wakes a sleeping app at each task's cron time,
 * read from schedule:list at deploy, so routine tasks use cron hours limited to the clinic
 * day: nothing runs overnight and the app and database can sleep. A ->between() filter
 * would not help, because Cloud would still wake the app every minute to check it.
 */
class HostedSchedule
{
    /** Routine tasks during the day: every N minutes ('*' + '/30' style) or at minute 0 hourly. */
    public static function daytime(string $minutes): string
    {
        [$from, $to] = self::hours();

        return "{$minutes} {$from}-{$to} * * *";
    }

    /** @return array{int, int} first and last daytime hour, in the app timezone */
    public static function hours(): array
    {
        [$from, $to] = array_map('intval', explode('-', (string) config('tenancy.schedule_day_hours', '6-20')) + [1 => 20]);

        return [max(0, min(23, $from)), max(0, min(23, $to))];
    }

    /** Minutes the scheduler heartbeat may be silent before it counts as stopped. */
    public static function heartbeatToleranceMinutes(?Carbon $now = null): int
    {
        if (! config('tenancy.enabled')) {
            return 5; // offline: Windows Task Scheduler runs every minute
        }

        $now = ($now ?? Carbon::now())->copy();
        [$from, $to] = self::hours();
        if ($now->hour >= $from && $now->hour <= $to) {
            return 40; // the heartbeat runs every 30 minutes during the day
        }

        // Overnight it is quiet on purpose: allow back to the last daytime run.
        $lastRun = $now->copy()->setTime($to, 30);
        if ($lastRun->gt($now)) {
            $lastRun->subDay();
        }

        return (int) ceil($lastRun->diffInMinutes($now, true)) + 10;
    }
}
