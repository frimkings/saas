<?php
namespace App\Console\Commands;
use App\Services\SubscriptionBillingService;
use Illuminate\Console\Command;
class ProcessSubscriptionBillingCommand extends Command
{
    protected $signature='subscriptions:process-billing {--lead-days=7}';
    protected $description='Generate renewal invoices and apply due subscription changes';
    public function handle(SubscriptionBillingService $service): int
    {
        $changes=$service->applyScheduledChanges();$invoices=$service->generateDueInvoices((int)$this->option('lead-days'));
        $this->info("Applied changes: {$changes}; generated invoices: {$invoices}");return self::SUCCESS;
    }
}
