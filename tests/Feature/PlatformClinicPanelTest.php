<?php

namespace Tests\Feature;

use App\Livewire\Platform\PlatformDashboardComponent;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\PlatformInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformClinicPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;
    private SubscriptionPlan $basic;
    private SubscriptionPlan $pro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->developer = User::factory()->create();
        $this->developer->forceFill(['is_platform_admin' => true])->save();
        $this->basic = SubscriptionPlan::create(['name' => 'Basic', 'code' => 'basic-test', 'currency' => 'GHS', 'base_price' => 100, 'annual_price' => 1000, 'included_branches' => 1, 'trial_days' => 14, 'billing_interval' => 'monthly', 'is_active' => true]);
        $this->pro = SubscriptionPlan::create(['name' => 'Pro', 'code' => 'pro-test', 'currency' => 'GHS', 'base_price' => 250, 'annual_price' => 2500, 'included_branches' => 3, 'trial_days' => 14, 'billing_interval' => 'monthly', 'is_active' => true]);
    }

    private function clinic(): Clinic
    {
        $clinic = Clinic::create(['name' => 'Panel Clinic', 'slug' => 'panel-clinic', 'status' => 'active', 'deployment_mode' => 'hosted', 'default_timezone' => 'UTC', 'default_currency' => 'GHS']);
        $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $this->basic->id, 'status' => 'active', 'billing_interval' => 'monthly',
            'current_period_starts_at' => now()->subMonth()->addDay(), 'current_period_ends_at' => now()->addDay()]);

        return $clinic;
    }

    private function panel(Clinic $clinic)
    {
        return Livewire::actingAs($this->developer)->test(PlatformDashboardComponent::class)->call('selectClinic', $clinic->id);
    }

    public function test_panel_opens_with_editable_details_and_saves_changes_with_audit(): void
    {
        $clinic = $this->clinic();

        $this->panel($clinic)
            ->assertSee('Panel Clinic')->assertSet('clinicForm.name', 'Panel Clinic')
            ->set('clinicForm.name', 'Panel Clinic Renamed')->set('clinicForm.billing_email', 'billing@panel.test')->set('clinicForm.default_currency', 'usd')
            ->call('saveClinic')->assertHasNoErrors();

        $clinic->refresh();
        $this->assertSame('Panel Clinic Renamed', $clinic->name);
        $this->assertSame('billing@panel.test', $clinic->billing_email);
        $this->assertSame('USD', $clinic->default_currency);
        $this->assertSame('panel-clinic', $clinic->slug);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'CLINIC_UPDATED', 'clinic_id' => $clinic->id]);
    }

    public function test_domain_must_stay_unique_and_email_valid(): void
    {
        $clinic = $this->clinic();
        Clinic::create(['name' => 'Other', 'slug' => 'other', 'domain' => 'taken.test', 'status' => 'active', 'deployment_mode' => 'hosted', 'default_timezone' => 'UTC', 'default_currency' => 'GHS']);

        $this->panel($clinic)->set('clinicForm.domain', 'taken.test')->set('clinicForm.billing_email', 'not-an-email')
            ->call('saveClinic')->assertHasErrors(['clinicForm.domain', 'clinicForm.billing_email']);
        $this->assertNull($clinic->fresh()->domain);
    }

    public function test_suspend_requires_a_reason(): void
    {
        $clinic = $this->clinic();
        $panel = $this->panel($clinic)->call('setPanelTab', 'danger');

        $panel->call('changeClinicStatus')->assertHasErrors('statusReason');
        $this->assertSame('active', $clinic->fresh()->status);

        $panel->set('statusReason', 'Unpaid for three months')->call('changeClinicStatus')->assertHasNoErrors();
        $this->assertSame('suspended', $clinic->fresh()->status);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'CLINIC_STATUS_CHANGED', 'clinic_id' => $clinic->id, 'reason' => 'Unpaid for three months']);
    }

    public function test_plan_change_applies_through_the_billing_service(): void
    {
        $clinic = $this->clinic();

        $this->panel($clinic)->call('setPanelTab', 'plan')
            ->set('panelPlanId', $this->pro->id)->set('panelTiming', 'period_end')->call('panelChangePlan')->assertHasErrors('panelReason')
            ->set('panelReason', 'Opening a second branch')->call('panelChangePlan')->assertHasNoErrors();

        $this->assertDatabaseHas('subscription_changes', ['clinic_id' => $clinic->id, 'to_plan_id' => $this->pro->id]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'SUBSCRIPTION_CHANGE_SCHEDULED', 'clinic_id' => $clinic->id]);
    }

    public function test_renew_issues_one_invoice_and_paying_it_extends_the_subscription(): void
    {
        $clinic = $this->clinic();
        $endsBefore = $clinic->currentSubscription->current_period_ends_at;
        $panel = $this->panel($clinic)->call('setPanelTab', 'plan');

        // Issuing twice reuses the open renewal invoice instead of creating a duplicate.
        $panel->call('panelRenew')->call('panelRenew')->assertHasNoErrors();
        $this->assertSame(1, PlatformInvoice::where('clinic_id', $clinic->id)->count());
        $invoice = PlatformInvoice::where('clinic_id', $clinic->id)->firstOrFail();
        $this->assertGreaterThan(0, $invoice->balance());

        $panel->set('renewPayNow', true)->set('renewMethod', 'mobile_money')->set('renewReference', 'MOMO-123')->call('panelRenew')->assertHasNoErrors();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertTrue($clinic->fresh('currentSubscription')->currentSubscription->current_period_ends_at->gt($endsBefore));
    }

    public function test_billing_tab_records_a_partial_payment_and_rejects_overpayment(): void
    {
        $clinic = $this->clinic();
        $panel = $this->panel($clinic)->call('setPanelTab', 'plan')->call('panelRenew');
        $invoice = PlatformInvoice::where('clinic_id', $clinic->id)->firstOrFail();

        $panel->call('setPanelTab', 'billing')->assertSee($invoice->number)->call('selectPanelInvoice', $invoice->id)
            ->assertSet('panelPaymentAmount', number_format($invoice->balance(), 2, '.', ''))
            ->set('panelPaymentAmount', '9999')->call('panelRecordPayment')->assertHasErrors('panelPaymentAmount')
            ->set('panelPaymentAmount', '40')->set('panelPaymentReference', 'BANK-1')->call('panelRecordPayment')->assertHasNoErrors();

        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertEquals(40, (float) $invoice->fresh()->amount_paid);
    }

    public function test_onboarding_modal_validates_each_step_and_opens_the_new_clinic(): void
    {
        $component = Livewire::actingAs($this->developer)->test(PlatformDashboardComponent::class)
            ->call('openOnboarding')->assertSet('showOnboarding', true)->assertSet('onboardingStep', 1)
            ->call('onboardingNext')->assertHasErrors('newClinicName')->assertSet('onboardingStep', 1)
            ->set('newClinicName', 'Modal Eye Care')->assertSet('newClinicSlug', 'modal-eye-care')
            ->call('onboardingNext')->assertHasNoErrors()->assertSet('onboardingStep', 2)
            ->set('newBranchTimezone', 'Africa/Accra')->call('onboardingNext')->assertSet('onboardingStep', 3)
            ->call('onboardClinic')->assertHasErrors(['newAdminName', 'newAdminEmail', 'newPlanId'])
            ->set('newAdminName', 'Modal Admin')->set('newAdminEmail', 'admin@modal.test')->set('newPlanId', $this->basic->id)
            ->call('onboardClinic')->assertHasNoErrors();

        $clinic = Clinic::where('slug', 'modal-eye-care')->firstOrFail();
        $component->assertSet('showOnboarding', false)->assertSet('selectedClinicId', $clinic->id)->assertSee('Modal Eye Care');
        $this->assertSame('Africa/Accra', $clinic->default_timezone);
        $this->assertDatabaseHas('clinic_user', ['clinic_id' => $clinic->id, 'clinic_role' => 'Super Admin']);
        $this->assertDatabaseHas('clinic_subscriptions', ['clinic_id' => $clinic->id, 'subscription_plan_id' => $this->basic->id, 'status' => 'trial']);
    }

    public function test_slug_stops_following_the_name_once_edited(): void
    {
        Livewire::actingAs($this->developer)->test(PlatformDashboardComponent::class)->call('openOnboarding')
            ->set('newClinicName', 'First Name')->assertSet('newClinicSlug', 'first-name')
            ->set('newClinicSlug', 'custom-slug')->set('newClinicName', 'Second Name')->assertSet('newClinicSlug', 'custom-slug');
    }

    public function test_clinic_filters_show_result_count_and_removable_chips(): void
    {
        $this->clinic();
        $total = Clinic::count();

        Livewire::actingAs($this->developer)->test(PlatformDashboardComponent::class)
            ->assertSee("{$total} of {$total} clinics")->assertDontSee('Clear all')
            ->set('statusFilter', 'overdue')->set('deploymentFilter', 'hosted')
            ->assertSee("0 of {$total} clinics")->assertSee('Status: Overdue')->assertSee('Hosted cloud')->assertSee('Clear all')
            ->set('statusFilter', '')->assertDontSee('Status: Overdue')->assertSee('Hosted cloud')
            ->call('resetFilters')->assertSee("{$total} of {$total} clinics")->assertDontSee('Clear all');
    }
}
