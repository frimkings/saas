<?php
namespace App\Console\Commands;
use App\Services\ClinicOffboardingService;
use Illuminate\Console\Command;
class ProcessClinicOffboardingCommand extends Command
{protected $signature='subscriptions:process-offboarding';protected $description='Process scheduled cancellations and completed retention periods';public function handle(ClinicOffboardingService $service):int{$count=$service->processDue();$this->info("Processed {$count} offboarding request(s).");return self::SUCCESS;}}
