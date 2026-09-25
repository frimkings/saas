<?php

namespace App\Console\Commands;

use App\Services\SubscriptionReconciliationService;
use Illuminate\Console\Command;

class ReconcileSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:reconcile';
    protected $description = 'Reconcile subscription, agreement, invoice, payment and approval integrity';

    public function handle(SubscriptionReconciliationService $service): int
    {
        $run = $service->run();
        $this->info("{$run->status}: {$run->clinics_checked} clinics, {$run->records_checked} records, {$run->critical_issues} critical, {$run->warning_issues} warnings.");
        return $run->critical_issues ? self::FAILURE : self::SUCCESS;
    }
}
