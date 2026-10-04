<?php

namespace Tests\Feature;

use App\Console\Kernel;
use App\Services\DeploymentReadinessService;
use App\Services\LicenseService;
use App\Support\HostedSchedule;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** Hosted installs schedule no app backups (their disk is temporary); offline installs keep them all. */
class HostedScheduleTest extends TestCase
{
    use RefreshDatabase, GivesClinicsAccess;

    private const BACKUP_TASKS = ['backup:run', 'backup:copy-to-drives', 'backup:prune-custom', 'backup:monitor'];

    /** The artisan commands the scheduler would run, as "command args". */
    private function scheduledCommands(): array
    {
        $schedule = new Schedule();
        (fn () => $this->schedule($schedule))->call($this->app->make(Kernel::class));

        return collect($schedule->events())
            ->map(fn ($event) => trim(preg_replace('/^.*artisan[\'"]?\s+/', '', $event->command)))
            ->all();
    }

    /** command => cron expression */
    private function scheduledTimings(): array
    {
        $schedule = new Schedule();
        (fn () => $this->schedule($schedule))->call($this->app->make(Kernel::class));

        return collect($schedule->events())
            ->mapWithKeys(fn ($event) => [trim(preg_replace('/^.*artisan[\'"]?\s+/', '', $event->command)) => $event->expression])
            ->all();
    }

    private function backupTasks(array $commands): array
    {
        return array_values(array_filter($commands, fn ($command) => str_starts_with($command, 'backup:')));
    }

    public function test_hosted_installs_schedule_no_backup_tasks(): void
    {
        config(['tenancy.enabled' => true]);
        $commands = $this->scheduledCommands();

        $this->assertSame([], $this->backupTasks($commands));
        // The rest of the hosted schedule is untouched.
        $this->assertContains('subscriptions:process-billing', $commands);
        $this->assertContains('owner:send-summaries', $commands);
        $this->assertContains('system:health-heartbeat', $commands);
    }

    public function test_offline_installs_keep_their_backup_schedule(): void
    {
        config(['tenancy.enabled' => false]);
        $this->startOfflineTrial();
        LicenseService::clearCache();

        $backups = $this->backupTasks($this->scheduledCommands());

        $this->assertContains('backup:run --only-db', $backups);
        foreach (self::BACKUP_TASKS as $task) {
            $this->assertNotEmpty(array_filter($backups, fn ($command) => str_starts_with($command, $task)), "$task is still scheduled offline");
        }
    }

    public function test_hosted_readiness_does_not_demand_app_backups(): void
    {
        config(['tenancy.enabled' => true]);

        $check = app(DeploymentReadinessService::class)->run(false)['checks']['backup'];

        $this->assertTrue($check['passed']);
        $this->assertStringContainsString('database provider', $check['detail']);
    }

    public function test_hosted_schedule_wakes_nothing_overnight_so_the_app_and_database_can_sleep(): void
    {
        config(['tenancy.enabled' => true, 'tenancy.schedule_day_hours' => '6-20']);
        $timings = $this->scheduledTimings();

        // Laravel Cloud wakes the app at each task's cron time: none may fall from 21:00 to 05:59.
        $minute = Carbon::parse('2026-10-04 21:00');
        $end = Carbon::parse('2026-10-05 06:00');
        for (; $minute->lt($end); $minute->addMinute()) {
            foreach ($timings as $command => $cron) {
                $this->assertFalse((new CronExpression($cron))->isDue($minute), "$command wakes the app at {$minute->format('H:i')}");
            }
        }

        // Daytime work still runs: the heartbeat every half hour, SMS hourly, billing in the morning.
        $this->assertSame('*/30 6-20 * * *', $timings['system:health-heartbeat']);
        $this->assertSame('0 6-20 * * *', $timings['tenancy:run-scheduled sms:appointment-reminders']);
        $this->assertSame('5 6 * * *', $timings['subscriptions:process-billing']);
    }

    public function test_offline_installs_keep_their_every_minute_timings(): void
    {
        config(['tenancy.enabled' => false]);
        $this->startOfflineTrial();
        LicenseService::clearCache();
        $timings = $this->scheduledTimings();

        $this->assertSame('* * * * *', $timings['system:health-heartbeat']);
        $this->assertSame('* * * * *', $timings['platform:send-announcements']);
        $this->assertSame('*/5 * * * *', $timings['tenancy:run-scheduled visit-bills:finalize-expired']);
        $this->assertSame('15 0 * * *', $timings['subscriptions:process-billing']);
        $this->assertSame('0 3 * * *', $timings['logs:prune']);
    }

    public function test_hosted_scheduler_heartbeat_may_be_quiet_overnight(): void
    {
        config(['tenancy.enabled' => true, 'tenancy.schedule_day_hours' => '6-20']);

        $this->assertSame(40, HostedSchedule::heartbeatToleranceMinutes(Carbon::parse('2026-10-04 12:00')));
        // At 05:00 the last run was 20:30 the evening before.
        $this->assertGreaterThan(510, HostedSchedule::heartbeatToleranceMinutes(Carbon::parse('2026-10-04 05:00')));

        config(['tenancy.enabled' => false]);
        $this->assertSame(5, HostedSchedule::heartbeatToleranceMinutes(Carbon::parse('2026-10-04 05:00')));
    }
}
