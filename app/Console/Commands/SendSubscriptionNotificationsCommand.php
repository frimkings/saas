<?php
namespace App\Console\Commands;
use App\Services\BillingNotificationService;
use Illuminate\Console\Command;
class SendSubscriptionNotificationsCommand extends Command
{protected $signature='subscriptions:send-notifications';protected $description='Send deduplicated subscription lifecycle and billing alerts';public function handle(BillingNotificationService $service):int{$count=$service->processDue();$this->info("Sent {$count} subscription notification(s).");return self::SUCCESS;}}
