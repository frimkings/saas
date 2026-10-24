<?php

namespace Tests\Feature;

use App\Livewire\Cashier\SalesRecordsComponent;
use App\Livewire\ReportsComponent;
use App\Models\{Category, ClinicAddon, Clinic, ClinicSubscription, Insurer, PasswordResetRequest, Patient, PaymentTransaction, Product, RefundLog, SaleItem, Sales, SubscriptionPlan, User};
use App\Services\Insurance\InsuranceBilling;
use App\Support\Feature;
use App\Support\PlanProduct;
use App\Support\Pricing\ClinicalSuitePlans;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The four Clinical Suite plans (2026-10-24): what each opens, and clinics on older plans keeping everything. */
class ClinicPlanTiersTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    /** The owner of a clinic on one of the four plans, signed in. */
    protected function ownerOn(string $family): User
    {
        config(['tenancy.enabled' => true]);
        foreach (['Super Admin', 'Manager', 'Cashier', 'Doctor', 'Secretary'] as $name) Role::findOrCreate($name, 'web');
        $definition = ClinicalSuitePlans::plans()[$family];
        $plan = SubscriptionPlan::create(['name' => $definition['name'], 'code' => $family, 'family_code' => $family, 'version' => 1, 'product' => PlanProduct::CLINIC,
            'features' => ClinicalSuitePlans::features($definition), 'included_branches' => 1, 'included_users' => $definition['users'],
            'storage_limit_mb' => $definition['storage_mb'], 'base_price' => $definition['price'], 'annual_price' => $definition['price'] * 10,
            'currency' => 'GHS', 'billing_interval' => 'monthly', 'is_active' => true]);
        $this->clinic = Clinic::create(['name' => 'Bright Eyes', 'slug' => 'bright-'.$family, 'status' => 'active', 'deployment_mode' => 'hosted']);
        $branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'feature_snapshot' => $plan->features,
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        $owner = User::factory()->create();
        $owner->assignRole('Super Admin');
        $this->clinic->users()->attach($owner->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Super Admin']);
        $branch->users()->attach($owner->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $owner->id, 'role_id' => Role::findByName('Super Admin')->id, 'created_at' => now(), 'updated_at' => now()]);
        app(TenantContext::class)->set($owner, $this->clinic, $branch, [$branch->id]);
        $this->actingAs($owner);

        return $owner;
    }

    private function blocked(string $route): void
    {
        $this->assertContains($this->get(route($route))->status(), [302, 403], $route.' should be closed');
    }

    public function test_clinics_on_plans_from_before_the_tiers_keep_every_feature(): void
    {
        $old = ['clinical', 'appointments', 'inventory', 'attendance_parts_chosen'];
        foreach (PlanProduct::CLINIC_TIER_FEATURES as $feature) {
            $this->assertTrue(PlanProduct::allows($old, $feature), $feature);
            $this->assertTrue(PlanProduct::allows(['*'], $feature), $feature);
        }
        // Editing such a plan keeps them ticked, so saving it again changes nothing for its clinics.
        $this->assertEqualsCanonicalizing([...PlanProduct::CLINIC_TIER_FEATURES, Feature::APPOINTMENTS, Feature::INVENTORY],
            array_values(array_intersect(PlanProduct::extrasOf($old), [...PlanProduct::CLINIC_TIER_FEATURES, Feature::APPOINTMENTS, Feature::INVENTORY])));

        $starter = ClinicalSuitePlans::features(ClinicalSuitePlans::plans()['clinic-starter']);
        $this->assertFalse(PlanProduct::allows($starter, Feature::APPOINTMENTS));
        $this->assertFalse(PlanProduct::allows($starter, Feature::NEEDS_ATTENTION));
        $this->assertTrue(PlanProduct::allows($starter, Feature::INVENTORY));
    }

    public function test_starter_runs_the_day_without_the_extras(): void
    {
        $this->ownerOn('clinic-starter');

        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Dashboard')->assertDontSee('Revenue, last 7 days')->assertDontSee('Full reports');
        $this->get(route('secretary.patients'))->assertOk();
        $this->get(route('cashier.sales-records'))->assertOk()->assertDontSee('wire:click="exportCSV"', false);
        foreach (['secretary.appointments', 'attention', 'staff.messages', 'admin.reports', 'admin.cash-book', 'admin.quotations',
                     'admin.insurance.claims', 'admin.patient-ledger', 'admin.suppliers', 'admin.roles-permissions', 'admin.sms-settings'] as $route) {
            $this->blocked($route);
        }
    }

    public function test_standard_sees_this_months_sales_and_its_own_extras(): void
    {
        $this->ownerOn('clinic-standard');

        $this->get(route('secretary.appointments'))->assertOk();
        $this->get(route('attention'))->assertOk();
        $this->get(route('admin.reports'))->assertOk()->assertSee('This month')->assertDontSee('Export PDF');
        $reports = Livewire::test(ReportsComponent::class)->assertSet('monthOnly', true)->set('fromDate', '2020-01-01');
        $this->assertSame(now()->startOfMonth()->toDateString(), $reports->get('fromDate'));
        foreach (['admin.cash-book', 'admin.expenses', 'admin.income-statement', 'admin.insurance.claims', 'cashier.outstanding-balances'] as $route) {
            $this->blocked($route);
        }
    }

    public function test_plus_has_the_books_and_pro_adds_the_rest(): void
    {
        $this->ownerOn('clinic-plus');
        $this->get(route('admin.cash-book'))->assertOk();
        $this->get(route('admin.expenses'))->assertOk();
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Business Overview');
        Livewire::test(ReportsComponent::class)->assertSet('monthOnly', false);
        foreach (['admin.income-statement', 'admin.patient-ledger', 'admin.insurance.claims', 'admin.audit-trail'] as $route) {
            $this->blocked($route);
        }

        $this->ownerOn('clinic-pro');
        foreach (['admin.income-statement', 'admin.patient-ledger', 'admin.insurance.claims', 'admin.audit-trail'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_insurers_are_billed_only_on_pro(): void
    {
        $this->ownerOn('clinic-plus');
        $insurer = Insurer::create(['name' => 'Acme Health', 'active' => true]);
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Ama Serwaa', 'contact' => '0241112222', 'gender' => 'Female', 'insurer_id' => $insurer->id]);
        $this->assertNull(app(InsuranceBilling::class)->insurerFor($patient), 'Plus clinics bill the patient in full');

        $this->ownerOn('clinic-pro');
        $insurer = Insurer::create(['name' => 'Acme Health', 'active' => true]);
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Kofi Boateng', 'contact' => '0241113333', 'gender' => 'Male', 'insurer_id' => $insurer->id]);
        $this->assertSame($insurer->id, app(InsuranceBilling::class)->insurerFor($patient)?->id);
    }

    /** A paid sale of two items, from the signed-in owner. */
    private function paidSale(): Sales
    {
        $category = Category::firstOrCreate(['name' => 'Drops'], ['user_id' => auth()->id()]);
        $product = Product::factory()->create(['user_id' => auth()->id(), 'category_id' => $category->id, 'quantity' => 3]);
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Esi Mensah', 'contact' => '0241114444', 'gender' => 'Female']);
        $sale = Sales::create(['user_id' => auth()->id(), 'patient_id' => $patient->id, 'transaction_id' => 'TXN-TIER-'.uniqid(), 'business_line' => 'clinic',
            'total_amount' => 100, 'amount_paid' => 100, 'payment_status' => 'paid']);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'prescribed_quantity' => 2, 'dispensed_quantity' => 2, 'selling_price' => 50, 'subtotal' => 100]);
        PaymentTransaction::create(['sale_id' => $sale->id, 'amount' => 100, 'payment_method' => 'cash', 'collected_by' => auth()->id()]);

        return $sale->fresh('items');
    }

    private function askForRefund(Sales $sale): void
    {
        Livewire::test(SalesRecordsComponent::class)->call('initiateRefund', $sale->id)
            ->set('initiateRefundReasonCode', array_key_first(RefundLog::REASON_CODES))->set('initiateRefundReason', 'Customer brought the drops back unopened.')
            ->set('initiateRefundItemIds', $sale->items->pluck('id')->all())->call('submitRefundRequest');
    }

    public function test_without_approvals_a_refund_is_done_when_asked_for(): void
    {
        $this->ownerOn('clinic-plus');
        $sale = $this->paidSale();
        $this->askForRefund($sale);

        $refund = RefundLog::where('sale_id', $sale->id)->sole();
        $this->assertSame(RefundLog::STATUS_PROCESSED, $refund->status);
        $this->assertNotNull($refund->refund_number);
        $this->assertTrue($sale->fresh()->is_refunded);
    }

    public function test_with_approvals_a_refund_waits_for_the_manager(): void
    {
        $this->ownerOn('clinic-pro');
        $sale = $this->paidSale();
        $this->askForRefund($sale);

        $this->assertSame(RefundLog::STATUS_PENDING, RefundLog::where('sale_id', $sale->id)->sole()->status);
        $this->assertFalse($sale->fresh()->is_refunded);
    }

    public function test_without_approvals_a_refund_from_sales_reports_is_done_too(): void
    {
        $this->ownerOn('clinic-plus');
        $sale = $this->paidSale();
        Livewire::test(ReportsComponent::class)->call('showRefundModal', $sale->id)
            ->set('refundReason', 'Customer brought the drops back unopened.')->call('processRefund');

        $this->assertSame(RefundLog::STATUS_PROCESSED, RefundLog::where('sale_id', $sale->id)->sole()->status);
        $this->assertTrue($sale->fresh()->is_refunded);
        // Links in notices about requests left pending lead to the Approvals page, not a 403.
        $this->get(route('admin.refund-approvals'))->assertRedirect();
    }

    public function test_month_only_reports_stay_in_this_month_after_a_reset(): void
    {
        $this->ownerOn('clinic-standard');
        $reports = Livewire::test(ReportsComponent::class)->call('switchTab', 'history')->call('resetFilters');
        $this->assertSame(now()->startOfMonth()->toDateString(), $reports->get('fromDate'));
    }

    public function test_attendance_only_plans_keep_staff_messages_but_no_clinic_tier_features(): void
    {
        $attendance = PlanProduct::features(PlanProduct::ATTENDANCE, []);
        $this->assertTrue(PlanProduct::allows($attendance, Feature::STAFF_MESSAGES));
        foreach ([Feature::CUSTOM_ROLES, Feature::NEEDS_ATTENTION, Feature::DISCOUNTS, Feature::INSURANCE] as $feature) {
            $this->assertFalse(PlanProduct::allows($attendance, $feature), $feature);
        }
    }

    public function test_an_add_on_gives_a_smaller_plan_the_feature(): void
    {
        $owner = $this->ownerOn('clinic-plus');
        $this->blocked('admin.income-statement');
        $addons = app(\App\Services\ClinicAddonService::class);

        // A priced add-on and one of the new tier features, added by the platform at a price it chooses.
        $addons->add($this->clinic, Feature::ADVANCED_REPORTS, 30, 'Asked on the phone', $owner);
        $addons->add($this->clinic, Feature::INSURANCE, 50, 'Takes insurance patients', $owner);
        $addons->forget($this->clinic->id);
        $this->get(route('admin.income-statement'))->assertOk();
        $this->get(route('admin.insurance.claims'))->assertOk();

        // Approvals bought as an add-on: refunds wait for the manager again instead of going through.
        $addons->add($this->clinic, Feature::APPROVALS, 30, null, $owner);
        $addons->forget($this->clinic->id);
        $sale = $this->paidSale();
        $this->askForRefund($sale);
        $this->assertSame(RefundLog::STATUS_PENDING, RefundLog::where('sale_id', $sale->id)->sole()->status);
    }

    public function test_clinical_documents_on_a_plan_without_storage_comes_with_a_pack_that_stays_while_it_does(): void
    {
        $owner = $this->ownerOn('clinic-starter');
        // None of the four plans is without storage now (Starter has 20 MB); a custom plan can be.
        $subscription = ClinicSubscription::where('clinic_id', $this->clinic->id)->sole();
        $subscription->update(['plan_snapshot' => ['storage_limit_mb' => 0] + $subscription->plan_snapshot]);
        $addons = app(\App\Services\ClinicAddonService::class);
        \Illuminate\Support\Facades\Artisan::call('pricing:clinical-suite');

        Livewire::test(\App\Livewire\Admin\ClinicAddonsComponent::class)
            ->assertSee('Your plan has no scan storage, so a 1 GB storage pack (20.00 a month) comes with it.')
            ->assertSee('Reminder texts to patients also need');

        $documents = $addons->add($this->clinic, Feature::CLINICAL_DOCUMENTS, 15, 'Asked for', $owner);
        $addons->forget($this->clinic->id);
        $pack = \App\Models\ClinicAddon::where('clinic_id', $this->clinic->id)->where('feature', Feature::SCAN_STORAGE_PACK)->sole();
        $this->assertEquals(20, (float) $pack->monthly_price);
        $this->assertSame(1024, app(\App\Services\SubscriptionQuotaService::class)->storageLimitMb($this->clinic));

        // The pack can't go while documents needs it; once documents goes, it can.
        try {
            $addons->cancel($pack, $owner);
            $this->fail('The last storage pack was removed while Clinical documents needed it.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('Remove Clinical documents first', $e->getMessage());
        }
        $addons->cancel($documents, $owner);
        $this->assertNotNull($addons->cancel($pack->fresh(), $owner)->cancelled_at);
    }

    public function test_documents_on_a_plan_with_storage_adds_no_pack_and_broadcast_opens_sms_credits(): void
    {
        $owner = $this->ownerOn('clinic-starter');
        $addons = app(\App\Services\ClinicAddonService::class);

        // Broadcast bought alone still reaches SMS credits and logs, which it needs to send.
        $this->blocked('admin.sms-settings');
        $addons->add($this->clinic, Feature::SMS_CAMPAIGNS, 20, null, $owner);
        $addons->forget($this->clinic->id);
        $this->get(route('admin.sms-settings'))->assertOk();
        $this->get(route('admin.sms-logs'))->assertOk();
        $this->blocked('admin.messages');

        $this->assertFalse($addons->needsStoragePack($this->clinic), 'Starter has 20 MB of its own');
        $addons->add($this->clinic, Feature::CLINICAL_DOCUMENTS, 15, null, $owner);
        $this->assertSame(0, ClinicAddon::where('clinic_id', $this->clinic->id)->where('feature', Feature::SCAN_STORAGE_PACK)->count());
    }

    public function test_plus_can_have_ten_users_not_the_old_free_tier_three(): void
    {
        $this->ownerOn('clinic-plus');
        foreach (range(1, 2) as $n) {
            $user = User::factory()->create();
            $this->clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => false]);
        }
        // Three users so far: a fourth is within Plus's ten.
        $this->assertSame(3, $this->clinic->users()->count());
        app(\App\Services\SubscriptionQuotaService::class)->assertUserAvailable($this->clinic);
        // The user limits run before the form is checked: no "free tier is limited to 3" refusal, just the form's own errors.
        Livewire::test(\App\Livewire\Admin\UserRoleManagerComponent::class)->call('store')
            ->assertNotDispatched('show-alert')->assertHasErrors();
    }

    /** Work left over from a bigger plan: an open claim, a scan, a booked appointment, a part-paid bill from before the change. */
    protected function leftovers(User $owner): array
    {
        $branchId = (int) $this->clinic->branches()->value('id');
        $insurer = Insurer::create(['name' => 'Acme Health', 'active' => true]);
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $owner->id, 'name' => 'Abena Owusu', 'contact' => '0241115555', 'gender' => 'Female']);
        DB::table('insurance_claims')->insert(['clinic_id' => $this->clinic->id, 'branch_id' => $branchId, 'patient_id' => $patient->id, 'insurer_id' => $insurer->id,
            'claim_amount' => 120, 'status' => 'submitted', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('patient_documents')->insert(['clinic_id' => $this->clinic->id, 'branch_id' => $branchId, 'patient_id' => $patient->id, 'document_type' => 'oct',
            'title' => 'OCT scan', 'file_path' => 'documents/oct.pdf', 'original_name' => 'oct.pdf', 'file_size' => 1024, 'created_at' => now(), 'updated_at' => now()]);
        \App\Models\Appointments::create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'title' => 'Review', 'scheduled_at' => now()->addWeek(), 'status' => 'Confirmed', 'duration_minutes' => 30]);
        $sale = Sales::create(['user_id' => $owner->id, 'patient_id' => $patient->id, 'transaction_id' => 'TXN-OLD-1', 'business_line' => 'clinic',
            'total_amount' => 200, 'amount_paid' => 50, 'payment_status' => 'partial']);
        DB::table('sales')->where('id', $sale->id)->update(['created_at' => now()->subMonth()]);
        \App\Support\PlanLeftovers::forget();

        return [$patient, $insurer];
    }

    public function test_the_impact_of_a_downgrade_is_shown_before_it_happens(): void
    {
        $owner = $this->ownerOn('clinic-pro');
        foreach (range(1, 2) as $n) $this->clinic->users()->attach(User::factory()->create()->id, ['status' => 'active', 'is_default' => false]);
        $this->leftovers($owner);
        $definition = ClinicalSuitePlans::plans()['clinic-starter'];
        $starter = SubscriptionPlan::create(['name' => 'Clinic Starter', 'code' => 'starter-x', 'family_code' => 'starter-x', 'version' => 1, 'product' => PlanProduct::CLINIC,
            'features' => ClinicalSuitePlans::features($definition), 'included_branches' => 1, 'included_users' => 2, 'storage_limit_mb' => 20,
            'base_price' => 100, 'annual_price' => 1000, 'currency' => 'GHS', 'billing_interval' => 'monthly', 'is_active' => true]);

        $impact = app(\App\Services\PlanChangeImpact::class)->for($this->clinic, $starter);
        $this->assertTrue($impact['downgrade']);
        $this->assertContains('Insurance (co-pay, claims, insurer payments)', $impact['lost']);
        $this->assertContains('Appointments', $impact['lost']);
        $this->assertStringContainsString('3 active users; Clinic Starter allows 2', implode(' ', $impact['over']));
        $leftovers = implode(' ', $impact['leftovers']);
        foreach (['1 open insurance claim', '1 scan already uploaded', '1 booked appointment', '1 part-paid bill'] as $line) $this->assertStringContainsString($line, $leftovers);
        $this->assertStringContainsString('when the period already paid for ends', $impact['billing']);
        $this->assertStringContainsString('no credit is issued', app(\App\Services\PlanChangeImpact::class)->for($this->clinic, $starter, now: true)['billing']);

        // The clinic sees the same before asking for the change.
        Livewire::test(\App\Livewire\Admin\ClinicSubscriptionPortalComponent::class)->set('requestedPlanId', $starter->id)
            ->assertSee('Before moving to a smaller plan')->assertSee('1 open insurance claim');
    }

    public function test_after_a_downgrade_open_work_can_be_finished_but_not_started(): void
    {
        $owner = $this->ownerOn('clinic-starter');
        // Nothing left over: the pages stay closed.
        foreach (['admin.insurance.claims', 'secretary.appointments', 'cashier.outstanding-balances'] as $route) $this->blocked($route);

        [$patient] = $this->leftovers($owner);
        $this->get(route('admin.insurance.claims'))->assertOk();
        $this->get(route('admin.insurance.payments'))->assertOk();
        $this->get(route('secretary.appointments'))->assertOk();
        $this->get(route('cashier.outstanding-balances'))->assertOk();

        // Booked appointments can be managed, but no new one booked.
        Livewire::test(\App\Livewire\Secretary\AppointmentsComponent::class)->assertDontSee('New appointment')->assertDontSee('Walk-in')
            ->call('saveAppointment')->assertHasErrors(['patient_id'])
            ->call('openNewAppointmentModal')->call('openWalkInModal')->call('quickBookWalkIn');
        $this->assertSame(1, \App\Models\Appointments::count(), 'no new appointment was booked');

        // Scans already uploaded stay viewable; the upload form is gone.
        $clearance = \App\Models\CashierPatientClearance::create(['user_id' => $owner->id, 'patient_id' => $patient->id, 'payment_status' => 'Paid', 'doctor_status' => false, 'clearance_date' => now()->toDateString()]);
        Livewire::test(\App\Livewire\Doctor\PatientRecordsComponent::class, ['clearance' => $clearance])
            ->assertSee('Clinical Documents')->call('switchTab', 'bills')->assertSet('activeTab', 'bills')
            ->assertSee('Attached Images & Documents', false)->assertDontSee('Attach Clinical Document');
    }

    public function test_a_paid_yearly_renewal_brings_the_plans_free_sms_once(): void
    {
        $this->ownerOn('clinic-plus');
        $billing = app(\App\Services\SubscriptionBillingService::class);
        $sms = app(\App\Services\Messaging\SmsCreditService::class);
        $subscription = ClinicSubscription::where('clinic_id', $this->clinic->id)->sole();

        // Monthly: no free SMS.
        $monthly = $billing->invoiceFor($subscription->fresh());
        $billing->allocatePayment($monthly, (float) $monthly->total, 'mobile_money', 'MM-1', null);
        $this->assertSame(0, $sms->balance($this->clinic->id));

        // Yearly: Plus brings 1,000 SMS, once, however often the payment is seen.
        $subscription->update(['billing_interval' => 'yearly']);
        $yearly = $billing->invoiceFor($subscription->fresh());
        $billing->allocatePayment($yearly, (float) $yearly->total, 'mobile_money', 'MM-2', null);
        $this->assertSame(1000, $sms->balance($this->clinic->id));
        $sms->grantYearlyPlanSms($yearly->fresh('subscription.plan'));
        $this->assertSame(1000, $sms->balance($this->clinic->id));
        $this->assertSame([30, 300, 1000, 2000], array_values(ClinicalSuitePlans::YEARLY_SMS));
    }

    public function test_the_approvals_page_still_handles_password_resets_without_approvals(): void
    {
        $this->ownerOn('clinic-plus');
        PasswordResetRequest::create(['email' => 'cashier@bright.test', 'status' => 'pending']);

        // Only password resets: discounts, refunds, corrections and revokes apply when asked for on this plan.
        $this->get(route('admin.approvals'))->assertOk()
            ->assertSee("switchType('password_reset')", false)
            ->assertDontSee("switchType('discount')", false)->assertDontSee("switchType('refund')", false)
            ->assertDontSee("switchType('correction')", false)->assertDontSee("switchType('revoke')", false);
    }
}
