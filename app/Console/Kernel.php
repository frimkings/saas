<?php

namespace App\Console;

use App\Services\LicenseService;
use App\Support\Feature;
use App\Support\HostedSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Hosted: Laravel Cloud wakes the sleeping app (and so the database) for every task and bills
        // the time awake, so frequent and hourly tasks only run in the clinic day on working days,
        // once at the end of quiet days (Sunday), and the night-time dailies move to the first day
        // hour (HostedSchedule). Offline installs keep their every-minute timings.
        $hosted = (bool) config('tenancy.enabled');
        $routine = function (string $command, string $minutes, callable $offline) use ($schedule, $hosted) {
            if (! $hosted) {
                return $offline($schedule->command($command));
            }
            if ($quiet = HostedSchedule::quietDayRun()) {
                $schedule->command($command)->cron($quiet)->withoutOverlapping();
            }

            return $schedule->command($command)->cron(HostedSchedule::daytime($minutes));
        };
        $often = fn (string $command, ?callable $offline = null) => $routine($command, '0', $offline ?? fn ($event) => $event->everyMinute());
        $hourly = fn (string $command) => $routine($command, '0', fn ($event) => $event->hourly());
        $night = fn ($event, string $offlineAt, int $hostedMinute) => $event->dailyAt($hosted ? HostedSchedule::morning($hostedMinute) : $offlineAt);

        $often('system:health-heartbeat')->withoutOverlapping();
        $schedule->command('platform:deployment-readiness')->dailyAt('07:45')->withoutOverlapping();
        $hourly('subscriptions:evaluate-lifecycle')->withoutOverlapping();
        $night($schedule->command('subscriptions:process-billing'), '00:15', 5)->withoutOverlapping();
        $schedule->command('subscriptions:send-notifications')->dailyAt('08:15')->withoutOverlapping();
        $schedule->command('subscriptions:sync-collections')->dailyAt('08:30')->withoutOverlapping();
        $schedule->command('subscriptions:reconcile')->dailyAt('09:00')->withoutOverlapping();
        $hourly('subscriptions:process-offboarding')->withoutOverlapping();

        // Hosted installs (tenancy on) schedule no app backups: their server disk is temporary
        // (wiped on every deploy, not shared between servers), so archives written there would
        // be lost and there are no USB drives to copy to. The database provider's own backups
        // (Laravel Cloud → Database → Backups) cover recovery instead.
        if (! config('tenancy.enabled') && LicenseService::has(Feature::SCHEDULED_BACKUPS)) {
            // A single-clinic offline installation has a small local database
            // and benefits from short recovery-point snapshots.
            $schedule->command('backup:run --only-db')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('backup:run')->dailyAt('11:30')->withoutOverlapping();
            $schedule->command('backup:run')->dailyAt('16:30')->withoutOverlapping();
            $schedule->command('backup:copy-to-drives')->dailyAt('12:00')->withoutOverlapping();
            $schedule->command('backup:copy-to-drives')->dailyAt('17:00')->withoutOverlapping();
            $schedule->command('backup:prune-custom')->hourly()->withoutOverlapping();
            $schedule->command('backup:monitor')->dailyAt('08:00')->withoutOverlapping();
        }

        if (config('tenancy.enabled') || LicenseService::has(Feature::SMS_CAMPAIGNS)) {
            // Birthday SMS — sends to patients whose DOB month/day matches today
            $schedule->command('tenancy:run-scheduled sms:birthday-wishes --clinic-only')->dailyAt('08:00')->withoutOverlapping();

            // Appointment reminders — hourly, catches appointments ~24h out (and what fell in a quiet gap)
            $hourly('tenancy:run-scheduled sms:appointment-reminders')->withoutOverlapping();

            // Automatic follow-ups, each off until the clinic switches it on (FollowUpSms). Hourly so
            // they catch up after the server sleeps; they only send 9:00–19:00 clinic time.
            $hourly('tenancy:run-scheduled sms:missed-appointment-followups')->withoutOverlapping();
            $hourly('tenancy:run-scheduled sms:aftercare-followups')->withoutOverlapping();
            $hourly('tenancy:run-scheduled sms:clinical-recalls --clinic-only')->withoutOverlapping();
            $hourly('tenancy:run-scheduled sms:balance-reminders')->withoutOverlapping();
            $hourly('tenancy:run-scheduled sms:feedback-requests')->withoutOverlapping();
        }

        if (config('tenancy.enabled') || LicenseService::has(Feature::SPECTACLES_PRO)) {
            // Spectacle renewal reminders — daily, finds Collected orders whose renewal_date is X days away
            $schedule->command('tenancy:run-scheduled sms:spectacle-renewal-reminders')->dailyAt('09:00')->withoutOverlapping();
        }

        if (config('tenancy.enabled') || LicenseService::has(Feature::OPTICAL)) {
            // Uncollected glasses — daily pickup reminders per Optical Settings (days / max per order)
            $schedule->command('tenancy:run-scheduled sms:optical-collection-reminders')->dailyAt('10:00')->withoutOverlapping();
        }

        // Hosted SMS go through the queue; any still waiting long after (queue down) are sent directly.
        if ($hosted) {
            $often('tenancy:run-scheduled sms:send-stuck')->withoutOverlapping();
        }

        // Platform SMS account must cover every clinic's prepaid credits
        $schedule->command('sms:check-platform-balance')->dailyAt('07:00')->withoutOverlapping();

        // Prune unbounded log tables to keep working set small
        $night($schedule->command('logs:prune'), '03:00', 10)->withoutOverlapping();
        // Platform → Usage: each clinic's stored rows (skips itself when usage metering is off).
        $night($schedule->command('usage:snapshot-storage'), '03:45', 20)->withoutOverlapping();
        $often('tenancy:run-scheduled visit-bills:finalize-expired', fn ($event) => $event->everyFiveMinutes())->withoutOverlapping();

        // Clean up abandoned carts (stale prescription items with no purchase)
        $night($schedule->command('tenancy:run-scheduled carts:cleanup-abandoned'), '03:30', 15)->withoutOverlapping();

        // Patient recall — daily, applies admin-configured inactivity threshold
        try {
            $schedule->command('tenancy:run-scheduled sms:recall-patients --clinic-only')->dailyAt('09:00')->withoutOverlapping();
        } catch (\Throwable) {}

        // One receipt per visit: each visit's SMS at the clinic's closing time (default 5 PM), only
        // for clinics that switched it on. Hourly so it catches up after the server sleeps.
        $hourly('tenancy:run-scheduled sms:visit-receipts')->withoutOverlapping();

        // Daily, weekly and monthly sales emails to each clinic owner, from 10 AM clinic time;
        // the platform ticks which ones each plan includes. Replaces the old report delivery.
        $hourly('owner:send-summaries')->withoutOverlapping();
        // Platform → Announcements: queued emails to clinic owners and Super Admins, a batch a run.
        $often('platform:send-announcements')->withoutOverlapping();
        // Clinic requests (plan changes, SMS orders, sender IDs) still waiting after a day.
        $schedule->command('platform:remind-requests')->dailyAt('08:00')->withoutOverlapping();

        // Hosted: with more than one server (autoscaling) each runs the scheduler, so every task takes
        // a lock in the shared cache first and runs once, not once per server (no duplicate SMS or billing).
        if ($hosted) {
            foreach ($schedule->events() as $event) $event->onOneServer();
        }
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
