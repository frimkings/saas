<?php

namespace App\Console\Commands;

use App\Models\ReportDelivery;
use App\Models\Setting;
use App\Services\FinancialReportDeliveryService;
use Illuminate\Console\Command;

class SendFinancialReportCommand extends Command
{
    protected $signature = 'report:send-financial {--period=} {--force} {--today}';
    protected $description = 'Queue and email the financial summary report to configured recipients.';

    public function handle(FinancialReportDeliveryService $deliveryService): int
    {
        $settings = Setting::getSettings();

        if (!$this->option('force') && !$settings->report_enabled) {
            $this->info('Report delivery is disabled. Pass --force to send anyway.');
            return self::SUCCESS;
        }

        $recipients = array_filter((array) ($settings->report_recipients ?? []));
        if (empty($recipients)) {
            $this->warn('No recipients configured. Add them in Admin > Report Delivery.');
            return self::SUCCESS;
        }

        $period = $this->option('period') ?: ($settings->report_frequency ?: 'daily');
        $delivery = $deliveryService->queueReport(
            $period,
            (bool) $this->option('force'),
            (bool) $this->option('today')
        );

        if ($delivery->status === ReportDelivery::STATUS_SENT) {
            $this->info("Report already sent: {$delivery->delivery_key}");
            return self::SUCCESS;
        }

        $result = $deliveryService->attemptDelivery($delivery);
        $delivery->refresh();

        if ($delivery->status === ReportDelivery::STATUS_SENT) {
            $this->info("Done. Sent to {$result['sent']} recipient(s).");
            return self::SUCCESS;
        }

        $this->warn("Delivery queued for retry. Sent {$result['sent']}, failed {$result['failed']}.");
        if ($delivery->last_error) {
            $this->line('Last error: ' . $delivery->last_error);
        }

        return self::SUCCESS;
    }
}
