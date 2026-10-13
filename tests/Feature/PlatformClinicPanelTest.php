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

    public function test_an_owner_who_already_has_an_account_can_be_given_a_second_separate_business(): void
    {
        $onboard = fn (string $name, string $email) => Livewire::actingAs($this->developer)->test(PlatformDashboardComponent::class)
            ->call('openOnboarding')->set('newClinicName', $name)->set('newBranchTimezone', 'Africa/Accra')->set('onboardingStep', 3)
            ->set('newAdminName', 'Typed Name')->set('newAdminEmail', $email)->set('newPlanId', $this->basic->id);
        $onboard('First Eye Clinic', 'owner@example.test')->call('onboardClinic')->assertHasNoErrors();
        $owner = \App\Models\User::where('email', 'owner@example.test')->firstOrFail();
        $owner->forceFill(['name' => 'Dr Owner', 'must_change_password' => false, 'password' => \Illuminate\Support\Facades\Hash::make('their-own-password')])->save();
        $first = Clinic::where('slug', 'first-eye-clinic')->firstOrFail();

        // The same email again: refused until the platform admin says it is the same person.
        $second = $onboard('Second Optical Shop', 'Owner@Example.test')
            ->assertSee('Dr Owner')->assertSee('already has an account with this email')->assertSee('First Eye Clinic')
            ->call('onboardClinic')->assertHasErrors(['newAdminEmail']);
        $this->assertNull(Clinic::where('slug', 'second-optical-shop')->first());
        $second->set('newAdminLinkExisting', true)->call('onboardClinic')->assertHasNoErrors()
            ->assertSee('they sign in with their usual password');

        // One account, two businesses; nothing about the sign-in changed.
        $shop = Clinic::where('slug', 'second-optical-shop')->firstOrFail();
        $this->assertSame(1, \App\Models\User::whereRaw('LOWER(email) = ?', ['owner@example.test'])->count());
        $owner->refresh();
        $this->assertSame('Dr Owner', $owner->name);
        $this->assertFalse((bool) $owner->must_change_password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('their-own-password', $owner->password));
        $this->assertEqualsCanonicalizing([$first->id, $shop->id], $owner->clinics()->pluck('clinics.id')->all());
        $this->assertDatabaseHas('clinic_user', ['clinic_id' => $shop->id, 'user_id' => $owner->id, 'clinic_role' => 'Super Admin', 'status' => 'active']);
        $branch = $shop->branches()->firstOrFail();
        $this->assertSame(['Super Admin'], app(\App\Support\Tenancy\BranchRoleManager::class)->roles($owner, $branch)->pluck('name')->all());
        $this->assertSame($first->id, app(\App\Support\Tenancy\ClinicMembershipManager::class)->ensureDefault($owner)->id, 'Their first business stays the default.');
        // Each business has its own subscription.
        $this->assertSame(2, \App\Models\ClinicSubscription::whereIn('clinic_id', [$first->id, $shop->id])->count());

        // Signed in, they are offered both and can move to the new one.
        $this->actingAs($owner)->get(route('tenant.clinic.select'))->assertOk()->assertSee('First Eye Clinic')->assertSee('Second Optical Shop');
        $this->actingAs($owner)->post(route('tenant.clinic.switch'), ['clinic_id' => $shop->id])->assertRedirect();
        $this->assertSame($shop->id, (int) session(config('tenancy.session_keys.clinic')));

        // Changing the email clears the tick, so it can't carry over to a different person.
        $onboard('Third Shop', 'owner@example.test')->set('newAdminLinkExisting', true)->set('newAdminEmail', 'someone.new@example.test')
            ->assertSet('newAdminLinkExisting', false)->call('onboardClinic')->assertHasNoErrors();
        $this->assertTrue((bool) \App\Models\User::where('email', 'someone.new@example.test')->value('must_change_password'));
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
