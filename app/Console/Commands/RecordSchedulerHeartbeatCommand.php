<?php

namespace App\Console\Commands;

use App\Models\SystemHealthStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RecordSchedulerHeartbeatCommand extends Command
{
    protected $signature = 'system:health-heartbeat';

    protected $description = 'Record a heartbeat proving the Laravel scheduler is being executed.';

    public function handle(): int
    {
        try { Cache::forever('platform.scheduler_last_seen', now()->toIso8601String()); } catch (\Throwable) {}
        try {
            SystemHealthStatus::record('scheduler', [
                'host' => gethostname() ?: null,
                'source' => 'schedule:run',
            ]);
        } catch (\Throwable) {
            // The platform cache heartbeat remains authoritative when strict
            // tenancy correctly prevents a clinic-less health row.
        }

        $this->info('Scheduler heartbeat recorded.');

        return self::SUCCESS;
    }
}
