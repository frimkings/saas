<?php

namespace App\Console\Commands;

use App\Services\Messaging\FollowUpSms;
use Illuminate\Console\Command;

/** Hourly, per clinic (tenancy:run-scheduled). The rules live in FollowUpSms. */
class SendMissedAppointmentFollowUps extends Command
{
    protected $signature = 'sms:missed-appointment-followups';
    protected $description = 'Text patients who missed an appointment yesterday, inviting them to rebook (when the clinic has it switched on).';

    public function handle(FollowUpSms $followUps): int
    {
        ['sent' => $sent, 'skipped' => $skipped] = $followUps->missedAppointments();
        $this->info("missed-appointment follow-up SMS sent: {$sent}, skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
