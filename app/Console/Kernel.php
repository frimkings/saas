<?php

namespace App\Console;

use App\Services\LicenseService;
use App\Support\Feature;
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
        $schedule->command('system:health-heartbeat')->everyMinute()->withoutOverlapping();
        $schedule->command('platform:deployment-readiness')->dailyAt('07:45')->withoutOverlapping();
        $schedule->command('subscriptions:evaluate-lifecycle')->hourly()->withoutOverlapping();
        $schedule->command('subscriptions:process-billing')->dailyAt('00:15')->withoutOverlapping();
        $schedule->command('subscriptions:send-notifications')->dailyAt('08:15')->withoutOverlapping();
        $schedule->command('subscriptions:sync-collections')->dailyAt('08:30')->withoutOverlapping();
        $schedule->command('subscriptions:reconcile')->dailyAt('09:00')->withoutOverlapping();
        $schedule->command('subscriptions:process-offboarding')->hourly()->withoutOverlapping();

        if (config('tenancy.enabled')) {
            // Hosted multi-clinic database: hourly snapshots avoid running a
            // platform-wide dump every five minutes as tenant volume grows.
            $schedule->command('backup:run --only-db')->hourly()->withoutOverlapping();
            $schedule->command('backup:run')->dailyAt('01:30')->withoutOverlapping();
            $schedule->command('backup:copy-to-drives')->dailyAt('03:00')->withoutOverlapping();
            $schedule->command('backup:prune-custom')->dailyAt('03:30')->withoutOverlapping();
            $schedule->command('backup:monitor')->dailyAt('08:00')->withoutOverlapping();
        } elseif (LicenseService::has(Feature::SCHEDULED_BACKUPS)) {
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
            $schedule->command('tenancy:run-scheduled sms:appointment-reminders')->hourly()->withoutOverlapping();
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
        $schedule->command('logs:prune')->dailyAt('03:00')->withoutOverlapping();
        $schedule->command('tenancy:run-scheduled visit-bills:finalize-expired')->everyFiveMinutes()->withoutOverlapping();

        // Clean up abandoned carts (stale prescription items with no purchase)
        $schedule->command('tenancy:run-scheduled carts:cleanup-abandoned')->dailyAt('03:30')->withoutOverlapping();

        // Patient recall — daily, applies admin-configured inactivity threshold
        try {
            $schedule->command('tenancy:run-scheduled sms:recall-patients --clinic-only')->dailyAt('09:00')->withoutOverlapping();
        } catch (\Throwable) {}

        // Financial report delivery — schedule driven by admin settings (PRO only)
        try {
            if (config('tenancy.enabled') || LicenseService::has(Feature::REPORT_DELIVERY)) {
                $schedule->command('tenancy:run-scheduled report:retry-financial --clinic-only')->everyMinute()->withoutOverlapping();
                $schedule->command('tenancy:run-scheduled report:send-financial --clinic-only')->dailyAt('08:00')->withoutOverlapping();
            }
        } catch (\Throwable) {}
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
