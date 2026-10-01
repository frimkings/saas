<?php

namespace App\Console\Commands;

use App\Services\Visits\VisitReceiptSms;
use Illuminate\Console\Command;

/** Hourly, per branch (tenancy:run-scheduled). The rules live in VisitReceiptSms. */
class SendVisitReceipts extends Command
{
    protected $signature = 'sms:visit-receipts';
    protected $description = 'At closing time, text each patient one receipt (or part-payment note) per visit, for clinics with one receipt per visit on.';

    public function handle(VisitReceiptSms $sms): int
    {
        ['sent' => $sent, 'skipped' => $skipped] = $sms->sendDue();
        $this->info("visit receipt SMS sent: {$sent}, skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
