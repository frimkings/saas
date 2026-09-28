<?php

namespace App\Console\Commands;

use App\Services\PlatformRequestAlerts;
use Illuminate\Console\Command;

/** Daily at 8:00: one email to the platform requests inbox listing clinic requests waiting over a day. */
class RemindPlatformRequestsCommand extends Command
{
    protected $signature = 'platform:remind-requests';
    protected $description = 'Email the platform requests inbox about clinic requests still waiting after a day.';

    public function handle(PlatformRequestAlerts $alerts): int
    {
        $status = $alerts->remindWaiting();
        $this->line($status ? "Reminder {$status}." : 'Nothing waiting.');

        return self::SUCCESS;
    }
}
