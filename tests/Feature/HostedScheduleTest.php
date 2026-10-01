<?php

namespace Tests\Feature;

use App\Console\Kernel;
use App\Services\DeploymentReadinessService;
use App\Services\LicenseService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
