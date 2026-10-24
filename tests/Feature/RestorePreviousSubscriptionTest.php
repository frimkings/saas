<?php

namespace Tests\Feature;

use App\Models\{Clinic, ClinicSubscription, PlatformInvoice, SubscriptionPlan};
use App\Support\PlanProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A clinic put on a test plan goes back to the subscription it had, with its old dates. */
class RestorePreviousSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $code, string $product): SubscriptionPlan
    {
        return SubscriptionPlan::create(['name' => ucfirst($code), 'code' => $code, 'family_code' => $code, 'version' => 1, 'product' => $product,
            'features' => PlanProduct::features($product, []), 'currency' => 'GHS', 'base_price' => 100, 'annual_price' => 1000,
            'included_branches' => 1, 'trial_days' => 14, 'billing_interval' => 'monthly', 'is_active' => true]);
    }

    private function subscribe(Clinic $clinic, SubscriptionPlan $plan, string $ends): ClinicSubscription
    {
        return ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'renewal_mode' => 'manual',
            'billing_interval' => 'yearly', 'current_period_starts_at' => now()->subMonth(), 'current_period_ends_at' => $ends]);
    }

    public function test_test_plans_are_undone_and_others_left_alone(): void
    {
        $clinicPlan = $this->plan('plus', 'clinic');
        $both = $this->plan('both-test', 'both');
        $tested = Clinic::create(['name' => 'Tested', 'slug' => 'tested', 'status' => 'active', 'deployment_mode' => 'hosted']);
        $untouched = Clinic::create(['name' => 'Untouched', 'slug' => 'untouched', 'status' => 'active', 'deployment_mode' => 'hosted']);
        $original = $this->subscribe($tested, $clinicPlan, '2027-03-01 00:00:00');
        $this->subscribe($tested, $both, '2026-11-24 00:00:00');
        $test = $this->subscribe($tested, $both, '2026-11-25 00:00:00');
        $this->subscribe($untouched, $clinicPlan, '2027-01-01 00:00:00');
        PlatformInvoice::create(['number' => 'INV-TEST-1', 'clinic_id' => $tested->id, 'clinic_subscription_id' => $test->id, 'subtotal' => 100, 'tax' => 0,
            'total' => 100, 'currency' => 'GHS', 'status' => 'unpaid', 'period_start' => now(), 'period_end' => now()->addMonth(), 'due_date' => now()]);

        $this->artisan('subscriptions:restore-previous', ['--dry-run' => true])->expectsOutputToContain('INV-TEST-1')->assertSuccessful();
        $this->assertSame($test->id, $tested->fresh()->currentSubscription->id);

        $this->artisan('subscriptions:restore-previous')->assertSuccessful();
        $now = $tested->fresh()->currentSubscription;
        $this->assertNotSame($test->id, $now->id);
        $this->assertSame($clinicPlan->id, $now->subscription_plan_id);
        $this->assertSame('yearly', $now->billing_interval);
        $this->assertSame('2027-03-01', $now->current_period_ends_at->toDateString());
        $this->assertSame($original->feature_snapshot, $now->feature_snapshot);
        $this->assertSame(4, ClinicSubscription::where('clinic_id', $tested->id)->count());
        $this->assertSame(1, ClinicSubscription::where('clinic_id', $untouched->id)->count());
        $this->assertDatabaseHas('platform_audit_logs', ['clinic_id' => $tested->id, 'action' => 'SUBSCRIPTION_RESTORED']);

        // Running it again leaves the clinic alone; a clinic always on a both plan is listed and skipped.
        $this->artisan('subscriptions:restore-previous')->expectsOutputToContain('skipped: no earlier plan')->doesntExpectOutputToContain('Tested')->assertSuccessful();
        $this->assertSame($now->id, $tested->fresh()->currentSubscription->id);
    }
}
