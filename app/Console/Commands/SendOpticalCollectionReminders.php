<?php

namespace App\Console\Commands;

use App\Services\LicenseService;
use App\Services\OpticalCollectionNotifier;
use App\Support\Feature;
use App\Support\Messaging\SmsAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendOpticalCollectionReminders extends Command
{
    protected $signature = 'sms:optical-collection-reminders {--dry-run : Preview without sending}';
    protected $description = 'Text customers (and partner clinics, one message each) whose glasses are ready but uncollected, on the days set in Optical Settings.';

    public function handle(OpticalCollectionNotifier $notifier): int
    {
        if (! LicenseService::has(Feature::OPTICAL)) {
            $this->info('Optical is not enabled for this clinic.');
            return self::SUCCESS;
        }
        ['orders' => $orders, 'partners' => $partners] = $notifier->dueReminders();
        if ($orders->isEmpty() && $partners->isEmpty()) {
            $this->info('No uncollected glasses are due a reminder.');
            return self::SUCCESS;
        }
        $dryRun = (bool) $this->option('dry-run');
        $sms = SmsAvailability::check();
        if (! $sms['available'] && ! $dryRun) {
            // Staff can still remind by WhatsApp from Awaiting Collection.
            $this->warn(($orders->count() + $partners->count())." reminder(s) due but SMS is unavailable: {$sms['reason']}");
            return self::SUCCESS;
        }

        $sent = $failed = 0;
        foreach ($orders as ['order' => $order, 'steps' => $steps]) {
            if ($dryRun) {
                $this->line("  {$order->order_id} → ".($notifier->recipient($order)['phone'] ?? 'no phone').': '.$notifier->message($order, OpticalCollectionNotifier::REMINDER));
                continue;
            }
            $result = $notifier->sendSms($order, OpticalCollectionNotifier::REMINDER, $steps);
            $result['success'] ? $sent++ : $failed++;
            if (! $result['success']) $this->warn("  {$order->order_id}: {$result['error']}");
        }
        foreach ($partners as ['partner' => $partner, 'steps' => $steps]) {
            if ($dryRun) {
                $this->line("  {$partner->name} → ".($partner->messagingPhone() ?? 'no phone').': '.$notifier->partnerDigestMessage($partner));
                continue;
            }
            $result = $notifier->sendPartnerDigest($partner, $steps);
            $result['success'] ? $sent++ : $failed++;
            if (! $result['success']) $this->warn("  {$partner->name}: {$result['error']}");
        }
        if (! $dryRun) {
            Log::info("sms:optical-collection-reminders — sent: {$sent}, failed: {$failed}");
            $this->info("Done. Sent: {$sent} | Failed: {$failed}");
        }
        return self::SUCCESS;
    }
}
