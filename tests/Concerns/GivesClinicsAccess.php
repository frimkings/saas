<?php

namespace Tests\Concerns;

use App\Models\{Clinic, ClinicSubscription, Setting, SubscriptionPlan};

/**
 * Clinics without a subscription (hosted) or a license/trial (offline) are read-only, so tests
 * that exercise normal clinic work must give their clinics access first.
 */
trait GivesClinicsAccess
{
    /** Create a clinic with an active, all-features subscription. */
    protected function activeClinic(array $attributes): Clinic
    {
        $clinic = Clinic::create($attributes);
        $this->giveActiveSubscription($clinic);

        return $clinic;
    }

    protected function giveActiveSubscription(Clinic $clinic): ClinicSubscription
    {
        $plan = SubscriptionPlan::firstOrCreate(['code' => 'test-all-features'], [
            'name' => 'Test (all features)', 'features' => ['*'], 'base_price' => 0, 'billing_interval' => 'monthly',
        ]);

        return ClinicSubscription::create([
            'clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => 'monthly',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth(),
        ]);
    }

    /** Offline (local) clinics: start the 30-day trial so the install is writable. */
    protected function startOfflineTrial(): void
    {
        Setting::getSettings()->forceFill(['trial_started_at' => now()->toDateString()])->saveQuietly();
    }
}
