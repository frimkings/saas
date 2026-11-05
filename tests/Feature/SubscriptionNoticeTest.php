<?php

namespace Tests\Feature;

use App\Models\ClinicSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** The trial banner said "Trial ends in 3.1477784807639 day(s)". */
class SubscriptionNoticeTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    private function banner(): string
    {
        return view('components.subscription-notice')->render();
    }

    public function test_the_trial_banner_counts_whole_days_and_names_the_date(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(9, 30));
        $this->ownerOn('clinic-plus');
        $subscription = ClinicSubscription::where('clinic_id', $this->clinic->id)->latest('id')->first();

        $subscription->update(['status' => 'trial', 'trial_ends_at' => now()->setDate(2026, 11, 8)->setTime(17, 26)]);
        $this->assertStringContainsString('Trial ends in 3 days, on 08 Nov 2026.', $this->banner());

        $subscription->update(['trial_ends_at' => now()->setDate(2026, 11, 6)->setTime(8, 0)]);
        $this->assertStringContainsString('Trial ends tomorrow, on 06 Nov 2026.', $this->banner());

        $subscription->update(['trial_ends_at' => now()->setTime(23, 0)]);
        $this->assertStringContainsString('Trial ends today', $this->banner());
    }
}
