<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Services\OwnerAlertDigestService;
use App\Services\OwnerSummaryService;
use Illuminate\Console\Command;

/**
 * Runs hourly. From 7 AM clinic time, sends each clinic owner the morning alerts and the
 * sales summaries their plan includes, if they haven't gone yet today.
 */
class SendOwnerSummariesCommand extends Command
{
    protected $signature = 'owner:send-summaries';
    protected $description = 'Email clinic owners their morning alerts and daily, weekly and monthly sales summaries when due.';

    public function handle(OwnerSummaryService $summaries, OwnerAlertDigestService $alerts): int
    {
        Clinic::where('status', 'active')->orderBy('id')->each(function (Clinic $clinic) use ($summaries, $alerts) {
            try {
                if ($status = $alerts->sendDue($clinic)) {
                    $this->line("{$clinic->name}: morning alerts {$status}");
                }
                foreach ($summaries->sendDue($clinic) as $period => $status) {
                    $this->line("{$clinic->name}: {$period} summary {$status}");
                }
            } catch (\Throwable $e) {
                report($e);
                $this->warn("{$clinic->name}: {$e->getMessage()}");
            }
        });

        return self::SUCCESS;
    }
}
