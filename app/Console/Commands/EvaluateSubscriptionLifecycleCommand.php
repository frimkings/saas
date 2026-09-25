<?php

namespace App\Console\Commands;

use App\Models\ClinicSubscription;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Console\Command;

class EvaluateSubscriptionLifecycleCommand extends Command
{
    protected $signature = 'subscriptions:evaluate-lifecycle {--clinic= : Evaluate one clinic ID only}';
    protected $description = 'Evaluate trial, renewal, grace, restriction and suspension transitions';

    public function handle(SubscriptionLifecycleService $lifecycle): int
    {
        if ($clinicId = $this->option('clinic')) {
            $subscription = ClinicSubscription::query()->where('clinic_id', $clinicId)->latest('id')->first();
            if (! $subscription) {
                $this->error('No subscription exists for that clinic.');
                return self::FAILURE;
            }
            $this->info('Subscription status: '.$lifecycle->evaluate($subscription)->status);
            return self::SUCCESS;
        }

        $counts = $lifecycle->evaluateAll();
        $this->info('Evaluated subscriptions: '.collect($counts)->map(fn ($count, $status) => "$status=$count")->implode(', '));
        return self::SUCCESS;
    }
}
