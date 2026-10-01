<?php

namespace App\Console\Commands;

use App\Services\Messaging\FollowUpSms;
use Illuminate\Console\Command;

/** Hourly, per branch (tenancy:run-scheduled). The rules live in FollowUpSms. */
class SendBalanceReminders extends Command
{
    protected $signature = 'sms:balance-reminders';
    protected $description = 'Text patients and customers what they still owe, on the clinic schedule (when switched on).';

    public function handle(FollowUpSms $followUps): int
    {
        ['sent' => $sent, 'skipped' => $skipped] = $followUps->balanceReminders();
        $this->info("balance reminder SMS sent: {$sent}, skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
