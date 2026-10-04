<?php

namespace App\Console\Commands;

use App\Services\Platform\Announcements;
use Illuminate\Console\Command;

/** Every minute offline, every half hour by day hosted (Kernel): send the next batch of queued announcement emails (Platform → Announcements). */
class SendPlatformAnnouncements extends Command
{
    protected $signature = 'platform:send-announcements';
    protected $description = 'Send queued platform announcement emails to clinic owners and Super Admins, a batch at a time.';

    public function handle(Announcements $announcements): int
    {
        // A short pause between emails keeps well under the email provider's rate limit.
        $sent = $announcements->deliver(Announcements::PER_RUN, 550);
        $this->info("announcement emails processed: {$sent}.");

        return self::SUCCESS;
    }
}
