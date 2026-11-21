<?php

namespace Tests\Feature;

use App\Models\{Clinic, ClinicSubscription, SubscriptionPlan};
use App\Support\Pricing\ClinicalSuitePlans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Standard Optical is the Optical Suite's only plan (18 Nov 2026); others stop being sold unless an active clinic is on them. */
class OneOpticalPlanTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $name, string $code, string $product, array $features): SubscriptionPlan
    {
        return SubscriptionPlan::create(['name' => $name, 'code' => $code, 'product' => $product, 'features' => $features, 'base_price' => 250,
            'billing_interval' => 'monthly', 'currency' => 'GHS', 'is_active' => true]);
    }

    private function on(SubscriptionPlan $plan, string $status): void
    {
        $clinic = Clinic::create(['name' => $plan->code.' '.$status, 'slug' => $plan->code.'-'.$status, 'status' => 'active', 'deployment_mode' => 'hosted']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => $status, 'billing_interval' => 'monthly',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
    }

    public function test_only_standard_optical_and_plans_active_clinics_use_stay_on_sale(): void
    {
        $standard = $this->plan('Standard Optical', 'dssd-v3', 'optical', ['optical', '*']);
        $shop = $this->plan('Optical Shop', 'optical-shop', 'optical', ['optical']);
        $combo = $this->plan('BASIC', '12313', 'both', ['clinical', 'optical', '*']);
        $used = $this->plan('PRO', '200', 'both', ['clinical', 'optical', '*']);
        $clinicOnly = $this->plan('Clinic Pro', 'clinic-pro', 'clinic', ['clinical']);
        $this->on($combo, 'suspended');
        $this->on($used, 'active');

        $retired = ClinicalSuitePlans::retireOtherOpticalPlans();

        $this->assertEqualsCanonicalizing(['Optical Shop (optical-shop)', 'BASIC (12313)'], $retired);
        $this->assertSame([true, false, false, true, true], [$standard->fresh()->is_active, $shop->fresh()->is_active, $combo->fresh()->is_active,
            $used->fresh()->is_active, $clinicOnly->fresh()->is_active]);
        $this->assertSame($standard->id, ClinicalSuitePlans::opticalPlan()->id);

        // The pricing command no longer brings an optical plan back, and the page prices the optical shop from Standard Optical.
        config(['tenancy.enabled' => true]);
        $this->artisan('pricing:clinical-suite')->assertSuccessful();
        $this->assertSame(1, SubscriptionPlan::where('product', 'optical')->where('is_active', true)->count());
        $this->get(route('pricing'))->assertOk()->assertSee('optical shop, GHS 250 a month', false);
    }

    /** 21 Nov 2026: Clinic + Optical, GHS 500, is the one plan sold with both sides; it is never retired with the other optical plans. */
    public function test_the_clinic_and_optical_plan_is_made_at_500_and_kept_on_sale(): void
    {
        $this->plan('Standard Optical', 'dssd-v3', 'optical', ['optical', '*']);
        config(['tenancy.enabled' => true]);
        $this->artisan('pricing:clinical-suite')->expectsOutputToContain('Clinic + Optical: created at GHS 500')->assertSuccessful();

        $plan = ClinicalSuitePlans::combinedPlanOnSale();
        $this->assertSame(['Clinic + Optical', 'both', 500.0, 6000.0, 500.0, null, 2048], [$plan->name, $plan->product, (float) $plan->base_price,
            (float) $plan->annual_price, (float) $plan->additional_branch_price, $plan->included_users, $plan->storage_limit_mb]);
        $features = $plan->features;
        foreach ([\App\Support\Feature::INSURANCE, \App\Support\Feature::OPTICAL, \App\Support\Feature::CLINICAL, \App\Support\Feature::SCHEDULED_BACKUPS] as $feature) {
            $this->assertTrue(\App\Support\PlanProduct::allows($features, $feature), $feature);
        }
        $this->assertFalse(\App\Support\PlanProduct::allows($features, \App\Support\Feature::ATTENDANCE), 'staff attendance stays an add-on');
        $this->assertSame(2000, ClinicalSuitePlans::YEARLY_SMS[ClinicalSuitePlans::COMBINED_FAMILY]);

        // Not retired with the other optical plans, not made twice, and priced on the pricing page.
        $this->assertSame([], ClinicalSuitePlans::retireOtherOpticalPlans());
        $this->artisan('pricing:clinical-suite')->expectsOutputToContain('Clinic + Optical: already up to date')->assertSuccessful();
        $this->assertSame(1, SubscriptionPlan::where('family_code', ClinicalSuitePlans::COMBINED_FAMILY)->count());
        $this->get(route('pricing'))->assertOk()->assertSee('Clinic Pro plus the Optical Suite, GHS 500 a month', false);
    }

    public function test_without_standard_optical_nothing_is_retired(): void
    {
        $shop = $this->plan('Optical Shop', 'optical-shop', 'optical', ['optical']);

        $this->assertSame([], ClinicalSuitePlans::retireOtherOpticalPlans());
        $this->assertTrue($shop->fresh()->is_active);
    }
}
