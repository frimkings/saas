<?php

namespace App\Console\Commands;

use App\Services\Messaging\FollowUpSms;
use Illuminate\Console\Command;

/** Hourly, per branch (tenancy:run-scheduled). The rules live in FollowUpSms. */
class SendFeedbackRequests extends Command
{
    protected $signature = 'sms:feedback-requests';
    protected $description = 'Ask patients for feedback the day after a visit or collection (when switched on).';

    public function handle(FollowUpSms $followUps): int
    {
        ['sent' => $sent, 'skipped' => $skipped] = $followUps->feedbackRequests();
        $this->info("feedback request SMS sent: {$sent}, skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
