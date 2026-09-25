<?php
namespace App\Console\Commands;
use App\Services\SubscriptionCollectionService;
use Illuminate\Console\Command;
class SynchronizeSubscriptionCollectionsCommand extends Command{protected $signature='subscriptions:sync-collections';protected $description='Open, age and resolve subscription collection cases';public function handle(SubscriptionCollectionService $service):int{$r=$service->synchronize();$this->info("Opened {$r['opened']}; resolved {$r['resolved']} collection case(s).");return self::SUCCESS;}}
