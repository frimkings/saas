<?php

namespace Tests\Feature;

use App\Livewire\Platform\PlatformDashboardComponent;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\PlatformInvoice;
use App\Models\SubscriptionApprovalRequest;
use App\Models\SubscriptionCollectionCase;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Found on the live data, 17 Nov 2026: a replaced subscription kept its unpaid renewal invoices (debts the
 * clinic did not owe), the new one was never invoiced, Reinstate revived the old plan, and with one platform
 * administrator no approval could ever be given.
 */
class SubscriptionHandoverBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;
    private SubscriptionPlan $basic;
    private SubscriptionPlan $pro;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->developer = User::factory()->create();
        $this->developer->forceFill(['is_platform_admin' => true])->save();
        $this->basic = SubscriptionPlan::create(['name' => 'Basic', 'code' => 'basic-h', 'currency' => 'GHS', 'base_price' => 100, 'annual_price' => 1000, 'included_branches' => 1, 'billing_interval' => 'monthly', 'is_active' => true]);
        $this->pro = SubscriptionPlan::create(['name' => 'Pro', 'code' => 'pro-h', 'currency' => 'GHS', 'base_price' => 250, 'annual_price' => 2500, 'included_branches' => 3, 'billing_interval' => 'monthly', 'is_active' => true]);
    }

    /** A clinic on Basic whose period ends in two days, with next month's renewal already issued and unpaid. */
    private function clinicWithRenewal(string $status = 'active'): array
    {
        $clinic = Clinic::create(['name' => 'Handover Clinic', 'slug' => 'handover-'.uniqid(), 'status' => 'active', 'deployment_mode' => 'hosted', 'default_timezone' => 'UTC', 'default_currency' => 'GHS']);
        $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $old = ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $this->basic->id, 'status' => $status, 'billing_interval' => 'monthly',
            'current_period_starts_at' => now()->subMonth()->addDays(2)->startOfDay(), 'current_period_ends_at' => now()->addDays(2)->endOfDay()]);
        $renewal = app(SubscriptionBillingService::class)->invoiceFor($old);

        return [$clinic, $old, $renewal];
    }

    private function dashboard()
    {
        return Livewire::actingAs($this->developer)->test(PlatformDashboardComponent::class);
    }

    public function test_assigning_a_plan_voids_the_old_plans_unpaid_renewal_and_invoices_the_new_one_at_once(): void
    {
        [$clinic, , $renewal] = $this->clinicWithRenewal();

        $this->dashboard()->set('clinicId', $clinic->id)->set('planId', $this->pro->id)->set('status', 'active')->set('renewalMode', 'manual')
            ->set('subscriptionInterval', 'monthly')->set('subscriptionReason', 'Moving up to Pro')->call('assignSubscription')->assertHasNoErrors();

        $this->assertSame('void', $renewal->fresh()->status);
        $this->assertStringContainsString('Replaced by Pro', $renewal->fresh()->void_reason);
        $new = $clinic->fresh()->currentSubscription;
        $invoice = PlatformInvoice::where('clinic_subscription_id', $new->id)->sole();
        $this->assertSame([250.0, now()->toDateString()], [(float) $invoice->total, $invoice->period_start->toDateString()]);
        $this->assertSame(250.0, app(PlatformDashboardComponent::class)->clinicOutstanding($clinic->id), 'owes the new plan only, not both');
    }

    public function test_what_was_paid_on_the_replaced_plan_is_credited_to_the_new_plans_invoice(): void
    {
        [$clinic, $old, $renewal] = $this->clinicWithRenewal();
        $renewal->update(['amount_paid' => 100, 'status' => 'paid']);
        $new = ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $this->pro->id, 'status' => 'active', 'billing_interval' => 'monthly',
            'current_period_starts_at' => now()->startOfDay(), 'current_period_ends_at' => now()->addMonth()->subDay()->endOfDay()]);

        $invoice = app(SubscriptionBillingService::class)->handOver($new, $this->developer->id);

        $this->assertSame('paid', $renewal->fresh()->status, 'a paid invoice is never voided');
        $this->assertSame([250.0, 100.0, 150.0], [(float) $invoice->total, (float) $invoice->credited_amount, $invoice->balance()]);
        $this->assertSame('unpaid', $invoice->status);
        $this->assertSame(1, \App\Models\PlatformCreditNote::where('platform_invoice_id', $invoice->id)->count());
    }

    public function test_a_downgrade_at_period_end_starts_the_next_day_and_replaces_the_renewal_issued_at_the_old_price(): void
    {
        [$clinic, $old, $renewal] = $this->clinicWithRenewal();
        $billing = app(SubscriptionBillingService::class);
        $change = $billing->scheduleChange($old, $this->pro, 'monthly', 'period_end', 'Bigger plan from next month', $this->developer->id);

        $this->travelTo($old->current_period_ends_at->copy()->addMinute());
        $billing->applyScheduledChanges();

        $new = $clinic->fresh()->currentSubscription;
        $this->assertSame('applied', $change->fresh()->status);
        $this->assertSame($old->current_period_ends_at->copy()->addDay()->toDateString(), $new->current_period_starts_at->toDateString(), 'no day billed twice');
        $this->assertSame('void', $renewal->fresh()->status);
        $this->assertSame(250.0, (float) PlatformInvoice::where('clinic_subscription_id', $new->id)->sole()->total);
    }

    public function test_an_upgrade_part_way_keeps_the_current_periods_invoice_and_voids_the_renewal_at_the_old_price(): void
    {
        [$clinic, $old, $renewal] = $this->clinicWithRenewal();
        $current = PlatformInvoice::create(['number' => 'INV-CUR', 'clinic_id' => $clinic->id, 'clinic_subscription_id' => $old->id, 'source' => 'renewal', 'status' => 'unpaid',
            'period_start' => $old->current_period_starts_at, 'period_end' => $old->current_period_ends_at, 'due_date' => $old->current_period_starts_at,
            'subtotal' => 100, 'tax' => 0, 'total' => 100, 'amount_paid' => 0, 'currency' => 'GHS']);

        app(SubscriptionBillingService::class)->scheduleChange($old, $this->pro, 'monthly', 'immediate', 'Needs Pro now', $this->developer->id);

        $new = $clinic->fresh()->currentSubscription;
        $this->assertSame($new->id, (int) $current->fresh()->clinic_subscription_id, 'the period being used is still owed');
        $this->assertSame('unpaid', $current->fresh()->status);
        $this->assertSame('void', $renewal->fresh()->status, 'next month is billed at the new price, not the old');
    }

    public function test_reinstate_acts_on_the_clinics_current_subscription_never_a_replaced_one(): void
    {
        [$clinic, $old, $renewal] = $this->clinicWithRenewal('suspended');
        $renewal->update(['due_date' => now()->subDays(5)]);
        SubscriptionCollectionCase::create(['clinic_id' => $clinic->id, 'clinic_subscription_id' => $old->id, 'platform_invoice_id' => $renewal->id, 'status' => 'open', 'aging_bucket' => '1-30']);
        $new = ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $this->pro->id, 'status' => 'active', 'billing_interval' => 'monthly',
            'current_period_starts_at' => now()->subDays(10), 'current_period_ends_at' => now()->addDays(20)]);

        // The current plan is active: the old invoice offers no Reinstate, and asking for one is refused.
        $page = $this->dashboard()->call('setTab', 'billing')->call('setBillingSection', 'collections');
        $page->assertDontSeeHtml('reinstateSubscription(');
        $this->dashboard()->call('reinstateSubscription', $old->id)->assertStatus(422);
        $this->assertSame(0, SubscriptionApprovalRequest::count());
        $this->assertSame('active', $new->fresh()->status);
    }

    public function test_reinstating_a_suspended_current_subscription_starts_a_billed_period(): void
    {
        [$clinic, $old] = $this->clinicWithRenewal('suspended');

        $this->dashboard()->call('reinstateSubscription', $old->id);
        $request = SubscriptionApprovalRequest::sole();
        $this->dashboard()->set('approvalReviewNotes', 'Paid by phone')->call('reviewApproval', $request->id, true)->assertHasNoErrors();

        $this->assertSame('active', $old->fresh()->status);
        $this->assertTrue(PlatformInvoice::where('clinic_subscription_id', $old->id)->whereDate('period_start', now()->toDateString())->exists(), 'the reinstated period is invoiced');
    }

    public function test_a_sole_platform_administrator_can_approve_their_own_request_but_not_when_another_can(): void
    {
        [, , $renewal] = $this->clinicWithRenewal();
        $this->dashboard()->call('openInvoice', $renewal->id)->set('adjustmentReason', 'Raised by mistake')->call('voidInvoice', $renewal->id, 'Raised by mistake');
        $request = SubscriptionApprovalRequest::sole();

        $second = User::factory()->create();
        $second->forceFill(['is_platform_admin' => true])->save();
        $this->dashboard()->call('setTab', 'billing')->call('setBillingSection', 'approvals')->set('approvalReviewNotes', 'Approving my own')->call('reviewApproval', $request->id, true)
            ->assertHasErrors('approval')->assertSee('Another platform administrator must approve or reject this');
        $this->assertSame('unpaid', $renewal->fresh()->status);

        $second->forceFill(['is_platform_admin' => false])->save();
        $this->dashboard()->set('approvalReviewNotes', 'Only administrator')->call('reviewApproval', $request->id, true)->assertHasNoErrors();
        $this->assertSame('void', $renewal->fresh()->status);
    }

    public function test_a_credit_leaves_the_invoice_unpaid_while_anything_is_still_owed(): void
    {
        [, , $renewal] = $this->clinicWithRenewal();

        app(\App\Services\BillingAdjustmentService::class)->credit($renewal, 60, 'Goodwill', $this->developer->id);

        $this->assertSame(['unpaid', 40.0], [$renewal->fresh()->status, $renewal->fresh()->balance()]);
    }
}
