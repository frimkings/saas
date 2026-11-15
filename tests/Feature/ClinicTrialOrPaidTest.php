<?php

namespace Tests\Feature;

use App\Livewire\Platform\PlatformDashboardComponent;
use App\Models\{Clinic, ClinicSubscription, PlatformInvoice, SubscriptionPlan, User};
use App\Services\{SubscriptionLifecycleService, SubscriptionService};
use App\Support\PlanProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** No trials since 2026-11-15: a new clinic starts paying the day it is registered. Trials already running end as before. */
class ClinicTrialOrPaidTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->developer = User::factory()->create();
        $this->developer->forceFill(['is_platform_admin' => true])->save();
        // The plan still says 14 days: the 2-day rule wins.
        $this->plan = SubscriptionPlan::create(['name' => 'Clinic Pro', 'code' => 'clinic-pro-t', 'family_code' => 'clinic-pro-t', 'version' => 1, 'product' => 'clinic',
            'features' => PlanProduct::features('clinic', []), 'currency' => 'GHS', 'base_price' => 320, 'annual_price' => 3840,
            'included_branches' => 1, 'trial_days' => 14, 'billing_interval' => 'monthly', 'is_active' => true]);
    }

    private function onboard(string $name, string $start)
    {
        return Livewire::actingAs($this->developer)->test(PlatformDashboardComponent::class)
            ->call('openOnboarding')->set('newClinicProduct', 'clinic')->set('newClinicName', $name)->set('newBranchTimezone', 'Africa/Accra')
            ->set('onboardingStep', 3)->set('newAdminName', 'Owner')->set('newAdminEmail', str($name)->slug().'@example.test')
            ->set('newPlanId', $this->plan->id)->set('newClinicStart', $start);
    }

    public function test_there_is_no_trial_a_new_clinic_starts_its_paid_subscription_at_once(): void
    {
        // The wizard offers no trial, and one asked for anyway is refused.
        $this->onboard('Trial Eyes', 'trial')->assertDontSee('Free trial')->assertSee('There is no free trial')
            ->call('onboardClinic')->assertHasErrors('newClinicStart');
        $this->assertFalse(Clinic::where('name', 'Trial Eyes')->exists());

        $this->onboard('Trial Eyes', 'paid')->call('onboardClinic')->assertHasNoErrors();
        $subscription = Clinic::where('name', 'Trial Eyes')->sole()->currentSubscription;
        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->trial_ends_at);
        $this->assertSame(now()->toDateString(), $subscription->current_period_starts_at->toDateString());
        $this->assertSame(1, PlatformInvoice::where('clinic_id', $subscription->clinic_id)->count(), 'first invoice issued the same day');
    }

    public function test_a_trial_that_was_already_running_still_ends_in_suspension(): void
    {
        $clinic = Clinic::create(['name' => 'Old Trial', 'slug' => 'old-trial', 'deployment_mode' => 'hosted', 'status' => 'active']);
        $subscription = ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $this->plan->id, 'status' => 'trial',
            'trial_ends_at' => now()->addDays(2), 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addDays(2)]);

        $this->travelTo(now()->addDays(2)->addMinutes(5));
        app(SubscriptionService::class)->forgetCurrent();
        $this->assertSame('suspended', app(SubscriptionService::class)->access($clinic->fresh())['status']);
        $this->assertSame('suspended', app(SubscriptionLifecycleService::class)->evaluate($subscription->fresh())->status);
    }

    public function test_a_paid_start_is_active_with_its_first_invoice_paid_when_received(): void
    {
        $this->onboard('Paid Eyes', 'paid')->assertSee('Payment received now')
            ->set('newClinicInterval', 'monthly')->set('newPaymentReceived', true)->set('newPaymentMethod', 'mobile_money')->set('newPaymentReference', 'MM-123')
            ->call('onboardClinic')->assertHasNoErrors();

        $subscription = Clinic::where('name', 'Paid Eyes')->sole()->currentSubscription;
        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->trial_ends_at);
        $this->assertSame(now()->addMonth()->subDay()->toDateString(), $subscription->current_period_ends_at->toDateString());
        $invoice = PlatformInvoice::where('clinic_id', $subscription->clinic_id)->sole();
        $this->assertSame(['paid', 320.0], [$invoice->status, (float) $invoice->total]);
        $this->assertDatabaseHas('platform_payments', ['platform_invoice_id' => $invoice->id, 'reference' => 'MM-123', 'method' => 'mobile_money']);
        $this->assertSame(1, ClinicSubscription::where('clinic_id', $subscription->clinic_id)->count());
        $this->assertSame($subscription->current_period_ends_at->toDateString(), $subscription->fresh()->current_period_ends_at->toDateString(), 'paying the first invoice does not add another month');
    }

    public function test_a_paid_start_without_payment_leaves_the_first_invoice_due(): void
    {
        $this->onboard('Due Eyes', 'paid')->set('newClinicInterval', 'yearly')->call('onboardClinic')->assertHasNoErrors();

        $subscription = Clinic::where('name', 'Due Eyes')->sole()->currentSubscription;
        $this->assertSame(['active', 'yearly'], [$subscription->status, $subscription->billing_interval]);
        $invoice = PlatformInvoice::where('clinic_id', $subscription->clinic_id)->sole();
        $this->assertGreaterThan(0, $invoice->balance());
    }
}
