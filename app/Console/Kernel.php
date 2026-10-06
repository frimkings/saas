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
        // Hosted: Laravel Cloud wakes the sleeping app (and so the database) for every task, so
        // frequent and hourly tasks only run during the day (HostedSchedule) and the night-time
        // dailies move to the morning. Offline installs keep their every-minute timings.
        $hosted = (bool) config('tenancy.enabled');
        $often = fn ($event) => $hosted ? $event->cron(HostedSchedule::daytime('*/30')) : $event->everyMinute();
        $hourly = fn ($event) => $hosted ? $event->cron(HostedSchedule::daytime('0')) : $event->hourly();
        $night = fn ($event, string $offlineAt, string $hostedAt) => $event->dailyAt($hosted ? $hostedAt : $offlineAt);

        $often($schedule->command('system:health-heartbeat'))->withoutOverlapping();
        $schedule->command('platform:deployment-readiness')->dailyAt('07:45')->withoutOverlapping();
        $hourly($schedule->command('subscriptions:evaluate-lifecycle'))->withoutOverlapping();
        $night($schedule->command('subscriptions:process-billing'), '00:15', '06:05')->withoutOverlapping();
        $schedule->command('subscriptions:send-notifications')->dailyAt('08:15')->withoutOverlapping();
        $schedule->command('subscriptions:sync-collections')->dailyAt('08:30')->withoutOverlapping();
        $schedule->command('subscriptions:reconcile')->dailyAt('09:00')->withoutOverlapping();
        $hourly($schedule->command('subscriptions:process-offboarding'))->withoutOverlapping();

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

            // Appointment reminders — fires every hour, catches appointments ~24h out
            $hourly($schedule->command('tenancy:run-scheduled sms:appointment-reminders'))->withoutOverlapping();

            // Automatic follow-ups, each off until the clinic switches it on (FollowUpSms). Hourly so
            // they catch up after the server sleeps; they only send 9:00–19:00 clinic time.
            $hourly($schedule->command('tenancy:run-scheduled sms:missed-appointment-followups'))->withoutOverlapping();
            $hourly($schedule->command('tenancy:run-scheduled sms:aftercare-followups'))->withoutOverlapping();
            $hourly($schedule->command('tenancy:run-scheduled sms:clinical-recalls --clinic-only'))->withoutOverlapping();
            $hourly($schedule->command('tenancy:run-scheduled sms:balance-reminders'))->withoutOverlapping();
            $hourly($schedule->command('tenancy:run-scheduled sms:feedback-requests'))->withoutOverlapping();
        }

        if (config('tenancy.enabled') || LicenseService::has(Feature::SPECTACLES_PRO)) {
            // Spectacle renewal reminders — daily, finds Collected orders whose renewal_date is X days away
            $schedule->command('tenancy:run-scheduled sms:spectacle-renewal-reminders')->dailyAt('09:00')->withoutOverlapping();
        }

        if (config('tenancy.enabled') || LicenseService::has(Feature::OPTICAL)) {
            // Uncollected glasses — daily pickup reminders per Optical Settings (days / max per order)
            $schedule->command('tenancy:run-scheduled sms:optical-collection-reminders')->dailyAt('10:00')->withoutOverlapping();
        }

        // Platform SMS account must cover every clinic's prepaid credits
        $schedule->command('sms:check-platform-balance')->dailyAt('07:00')->withoutOverlapping();

        // Prune unbounded log tables to keep working set small
        $night($schedule->command('logs:prune'), '03:00', '06:10')->withoutOverlapping();
        // Platform → Usage: each clinic's stored rows (skips itself when usage metering is off).
        $night($schedule->command('usage:snapshot-storage'), '03:45', '06:20')->withoutOverlapping();
        $hosted
            ? $schedule->command('tenancy:run-scheduled visit-bills:finalize-expired')->cron(HostedSchedule::daytime('*/30'))->withoutOverlapping()
            : $schedule->command('tenancy:run-scheduled visit-bills:finalize-expired')->everyFiveMinutes()->withoutOverlapping();

        // Clean up abandoned carts (stale prescription items with no purchase)
        $night($schedule->command('tenancy:run-scheduled carts:cleanup-abandoned'), '03:30', '06:15')->withoutOverlapping();

        // Patient recall — daily, applies admin-configured inactivity threshold
        try {
            $schedule->command('tenancy:run-scheduled sms:recall-patients --clinic-only')->dailyAt('09:00')->withoutOverlapping();
        } catch (\Throwable) {}

        // One receipt per visit: each visit's SMS at the clinic's closing time (default 5 PM), only
        // for clinics that switched it on. Hourly so it catches up after the server sleeps.
        $hourly($schedule->command('tenancy:run-scheduled sms:visit-receipts'))->withoutOverlapping();

        // Daily, weekly and monthly sales emails to each clinic owner, from 10 AM clinic time;
        // the platform ticks which ones each plan includes. Replaces the old report delivery.
        $hourly($schedule->command('owner:send-summaries'))->withoutOverlapping();
        // Platform → Announcements: queued emails to clinic owners and Super Admins, a batch a run.
        $often($schedule->command('platform:send-announcements'))->withoutOverlapping();
        // Clinic requests (plan changes, SMS orders, sender IDs) still waiting after a day.
        $schedule->command('platform:remind-requests')->dailyAt('08:00')->withoutOverlapping();
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
