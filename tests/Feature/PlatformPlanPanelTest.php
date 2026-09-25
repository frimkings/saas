<?php

namespace Tests\Feature;

use App\Livewire\Platform\PlatformDashboardComponent;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformPlanPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->developer = User::factory()->create();
        $this->developer->forceFill(['is_platform_admin' => true])->save();
    }

    private function page()
    {
        return Livewire::withQueryParams(['tab' => 'plans'])->actingAs($this->developer)->test(PlatformDashboardComponent::class);
    }

    public function test_new_plan_is_created_from_the_panel_with_labelled_fields(): void
    {
        $this->page()->call('openNewPlan')->assertSet('showPlanPanel', true)->assertSee('Monthly price')->assertSee('Branches included')
            ->call('savePlan')->assertHasErrors(['planName', 'planCode'])
            ->set('planName', 'Starter')->set('planCode', 'starter')->set('basePrice', '120')->set('annualPrice', '1200')
            ->set('includedBranches', 2)->set('planProduct', 'both')
            ->call('savePlan')->assertHasNoErrors()->assertSet('showPlanPanel', true)->assertSee('Plan created.');

        $plan = SubscriptionPlan::where('code', 'starter')->firstOrFail();
        $this->assertEquals(120, (float) $plan->base_price);
        $this->assertSame(['clinical', 'optical'], $plan->features);
        $this->assertSame('both', $plan->product);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'PLAN_CREATED']);
    }

    public function test_editing_publishes_a_new_version_and_keeps_the_panel_on_it(): void
    {
        $plan = SubscriptionPlan::create(['name' => 'Basic', 'code' => 'basic', 'family_code' => 'basic', 'version' => 1, 'currency' => 'GHS', 'base_price' => 100, 'annual_price' => 1000, 'included_branches' => 1, 'trial_days' => 14, 'billing_interval' => 'monthly', 'is_active' => true]);

        $page = $this->page()->call('openPlan', $plan->id)->assertSet('planName', 'Basic')->assertSee('Save as new version')
            ->set('basePrice', '150')->call('savePlan')->assertHasNoErrors();

        $v2 = SubscriptionPlan::where('family_code', 'basic')->where('version', 2)->firstOrFail();
        $this->assertFalse((bool) $plan->fresh()->is_active);
        $this->assertEquals(150, (float) $v2->base_price);
        $page->assertSet('editingPlanId', $v2->id)->assertSee('Saved as version 2');
    }

    public function test_clinics_tab_lists_subscribers_and_assigns_a_clinic(): void
    {
        $plan = SubscriptionPlan::create(['name' => 'Basic', 'code' => 'basic', 'family_code' => 'basic', 'version' => 1, 'currency' => 'GHS', 'base_price' => 100, 'annual_price' => 1000, 'included_branches' => 1, 'trial_days' => 14, 'billing_interval' => 'monthly', 'is_active' => true]);
        $onPlan = Clinic::create(['name' => 'Already On', 'slug' => 'already-on', 'status' => 'active', 'deployment_mode' => 'hosted', 'default_timezone' => 'UTC', 'default_currency' => 'GHS']);
        ClinicSubscription::create(['clinic_id' => $onPlan->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => 'monthly', 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth()]);
        $newcomer = Clinic::create(['name' => 'Newcomer', 'slug' => 'newcomer', 'status' => 'active', 'deployment_mode' => 'hosted', 'default_timezone' => 'UTC', 'default_currency' => 'GHS']);

        $this->page()->call('openPlan', $plan->id)->call('setPlanPanelTab', 'clinics')
            ->assertSee('Already On')->assertSet('planId', $plan->id)
            ->set('clinicId', $newcomer->id)->call('assignSubscription')->assertHasErrors('subscriptionReason')
            ->set('subscriptionReason', 'Signed contract')->call('assignSubscription')->assertHasNoErrors()
            ->assertSee('Newcomer is now on Basic.');

        $this->assertSame($plan->id, $newcomer->fresh('currentSubscription')->currentSubscription->subscription_plan_id);
    }

    public function test_archived_plans_are_hidden_until_requested(): void
    {
        SubscriptionPlan::create(['name' => 'Retired Plan', 'code' => 'retired', 'currency' => 'GHS', 'base_price' => 0, 'annual_price' => 0, 'billing_interval' => 'monthly', 'is_active' => false]);
        SubscriptionPlan::create(['name' => 'Live Plan', 'code' => 'live', 'currency' => 'GHS', 'base_price' => 0, 'annual_price' => 0, 'billing_interval' => 'monthly', 'is_active' => true]);

        $this->page()->assertSee('Live Plan')->assertDontSee('Retired Plan')
            ->set('showArchivedPlans', true)->assertSee('Retired Plan');
    }
}
