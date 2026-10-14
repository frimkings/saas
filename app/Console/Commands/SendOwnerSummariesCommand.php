<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Services\OwnerAlertDigestService;
use App\Services\OwnerAlerts;
use App\Services\OwnerSummaryService;
use Illuminate\Console\Command;

/**
 * Runs hourly. From 10 AM clinic time, sends each clinic owner the morning alerts and the
 * sales summaries their plan includes, if they haven't gone yet today. Every hour, it also
 * tells the owner about an attendance terminal that has stopped sending.
 */
class SendOwnerSummariesCommand extends Command
{
    protected $signature = 'owner:send-summaries';
    protected $description = 'Email clinic owners their morning alerts and daily, weekly and monthly sales summaries when due.';

    public function handle(OwnerSummaryService $summaries, OwnerAlertDigestService $alerts, OwnerAlerts $notices): int
    {
        Clinic::where('status', 'active')->orderBy('id')->each(function (Clinic $clinic) use ($summaries, $alerts, $notices) {
            try {
                // Not tied to 10 AM: a terminal that stops sending is reported two hours after the branch opens.
                if ($silent = $notices->terminalsSilent($clinic)) {
                    $this->line("{$clinic->name}: {$silent} attendance terminal(s) not sending");
                }
                if ($status = $alerts->sendDue($clinic)) {
                    $this->line("{$clinic->name}: morning alerts {$status}");
                }
                foreach ($summaries->sendDue($clinic) as $period => $status) {
                    $this->line("{$clinic->name}: {$period} summary {$status}");
                }
                if ($heads = $summaries->sendToDepartmentHeads($clinic)) {
                    $this->line("{$clinic->name}: {$heads} department head email(s)");
                }
            } catch (\Throwable $e) {
                report($e);
                $this->warn("{$clinic->name}: {$e->getMessage()}");
            }
        });

        return self::SUCCESS;
    }
}
