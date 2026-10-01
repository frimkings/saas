<?php

namespace App\Console\Commands;

use App\Services\Messaging\FollowUpSms;
use Illuminate\Console\Command;

/** Hourly, per clinic (tenancy:run-scheduled). The rules live in FollowUpSms. */
class SendClinicalRecalls extends Command
{
    protected $signature = 'sms:clinical-recalls';
    protected $description = 'Text patients whose doctor-set next eye exam is coming up (when the clinic has it switched on).';

    public function handle(FollowUpSms $followUps): int
    {
        ['sent' => $sent, 'skipped' => $skipped] = $followUps->clinicalRecalls();
        $this->info("clinical recall SMS sent: {$sent}, skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
