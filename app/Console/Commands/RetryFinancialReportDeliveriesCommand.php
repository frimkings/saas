<?php

namespace App\Console\Commands;

use App\Models\ReportDelivery;
use App\Services\FinancialReportDeliveryService;
use Illuminate\Console\Command;

class RetryFinancialReportDeliveriesCommand extends Command
{
    protected $signature = 'report:retry-financial {--limit=10}';
    protected $description = 'Retry pending or failed financial report deliveries.';

    public function handle(FinancialReportDeliveryService $deliveryService): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $deliveries = ReportDelivery::whereIn('status', [
                ReportDelivery::STATUS_PENDING,
                ReportDelivery::STATUS_FAILED,
            ])
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($deliveries->isEmpty()) {
            $this->info('No pending financial report deliveries.');
            return self::SUCCESS;
        }

        foreach ($deliveries as $delivery) {
            $result = $deliveryService->attemptDelivery($delivery);
            $delivery->refresh();

            $this->line(sprintf(
                '%s: %s (sent %d, failed %d)',
                $delivery->delivery_key,
                $delivery->status,
                $result['sent'],
                $result['failed']
            ));
        }

        return self::SUCCESS;
    }
}
