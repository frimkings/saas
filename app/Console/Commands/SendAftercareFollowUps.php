<?php

namespace App\Console\Commands;

use App\Services\Messaging\FollowUpSms;
use Illuminate\Console\Command;

/** Hourly, per clinic (tenancy:run-scheduled). The rules live in FollowUpSms. */
class SendAftercareFollowUps extends Command
{
    protected $signature = 'sms:aftercare-followups';
    protected $description = 'Text customers a few days after they collect their glasses (when the clinic has it switched on).';

    public function handle(FollowUpSms $followUps): int
    {
        ['sent' => $sent, 'skipped' => $skipped] = $followUps->aftercare();
        $this->info("aftercare SMS sent: {$sent}, skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
