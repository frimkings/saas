<?php

namespace Tests\Feature;

use App\Livewire\Platform\PlatformDashboardComponent;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\PlatformInvoice;
use App\Models\SubscriptionApprovalRequest;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformBillingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;
    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->developer = User::factory()->create();
        $this->developer->forceFill(['is_platform_admin' => true])->save();
        $plan = SubscriptionPlan::create(['name' => 'Basic', 'code' => 'basic', 'currency' => 'GHS', 'base_price' => 100, 'annual_price' => 1000, 'tax_rate' => 10, 'included_branches' => 1, 'trial_days' => 14, 'billing_interval' => 'monthly', 'is_active' => true]);
        $this->clinic = Clinic::create(['name' => 'Billing Clinic', 'slug' => 'billing-clinic', 'status' => 'active', 'deployment_mode' => 'hosted', 'default_timezone' => 'UTC', 'default_currency' => 'GHS']);
        ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => 'monthly', 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth()]);
    }

    private function invoice(string $number, string $status = 'unpaid', ?string $due = null, float $total = 110): PlatformInvoice
    {
        return PlatformInvoice::create(['number' => $number, 'clinic_id' => $this->clinic->id, 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
            'due_date' => $due ?? now()->addWeek()->toDateString(), 'subtotal' => $total / 1.1, 'tax' => $total - $total / 1.1, 'total' => $total, 'amount_paid' => $status === 'paid' ? $total : 0,
            'credited_amount' => 0, 'refunded_amount' => 0, 'currency' => 'GHS', 'status' => $status, 'source' => 'renewal']);
    }

    private function billing()
    {
        return Livewire::withQueryParams(['tab' => 'billing'])->actingAs($this->developer)->test(PlatformDashboardComponent::class);
    }

    public function test_invoices_section_shows_summary_and_filters(): void
    {
        $this->invoice('INV-OVERDUE', 'unpaid', now()->subDays(3)->toDateString());
        $this->invoice('INV-CURRENT');
        $this->invoice('INV-PAID', 'paid');

        $this->billing()
            ->assertSee('Outstanding')->assertSee('GHS 220.00')->assertSee('INV-OVERDUE')->assertSee('INV-PAID')
            ->set('invoiceStatus', 'overdue')->assertSee('INV-OVERDUE')->assertDontSee('INV-CURRENT')->assertDontSee('INV-PAID')
            ->set('invoiceStatus', '')->set('invoiceSearch', 'INV-PAID')->assertSee('INV-PAID')->assertDontSee('INV-CURRENT');
    }

    public function test_new_invoice_uses_the_chosen_period_and_opens_in_the_panel(): void
    {
        $page = $this->billing()->call('openNewInvoice')->assertSet('showNewInvoice', true)
            ->set('invoiceClinicId', $this->clinic->id)->set('invoiceAmount', '200')->assertSee('220.00')
            ->set('invoicePeriodStart', '2026-10-01')->set('invoicePeriodEnd', '2026-09-01')->call('generateInvoice')->assertHasErrors('invoicePeriodEnd')
            ->set('invoicePeriodEnd', '2026-12-31')->set('invoiceNotes', 'Training session')->call('generateInvoice')->assertHasNoErrors();

        $invoice = PlatformInvoice::where('notes', 'Training session')->firstOrFail();
        $this->assertSame('2026-10-01', $invoice->period_start->toDateString());
        $this->assertSame('2026-12-31', $invoice->period_end->toDateString());
        $this->assertSame('manual', $invoice->source);
        $this->assertEquals(220, (float) $invoice->total);
        $page->assertSet('showNewInvoice', false)->assertSet('viewInvoiceId', $invoice->id)->assertSee($invoice->number);
    }

    public function test_invoice_panel_records_payment_and_blocks_overpayment(): void
    {
        $invoice = $this->invoice('INV-PAY');

        $this->billing()->call('openInvoice', $invoice->id)->call('startInvoiceAction', 'pay')->assertSet('paymentAmount', '110.00')
            ->set('paymentAmount', '500')->call('submitInvoicePayment')->assertHasErrors('paymentAmount')
            ->set('paymentAmount', '60')->set('paymentReference', 'MOMO-9')->call('submitInvoicePayment')->assertHasNoErrors();

        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertEquals(60, (float) $invoice->fresh()->amount_paid);
    }

    public function test_credit_refund_and_void_go_to_the_approval_queue_with_reasons(): void
    {
        $invoice = $this->invoice('INV-ADJ');
        $page = $this->billing()->call('openInvoice', $invoice->id);

        $page->call('startInvoiceAction', 'void')->call('submitInvoiceVoid')->assertHasErrors('voidReason')
            ->set('voidReason', 'Issued to the wrong clinic')->call('submitInvoiceVoid')->assertHasNoErrors();
        $this->assertDatabaseHas('subscription_approval_requests', ['action' => 'invoice_void', 'target_id' => $invoice->id, 'reason' => 'Issued to the wrong clinic', 'status' => 'pending']);
        $this->assertNull($invoice->fresh()->voided_at);

        $page->call('startInvoiceAction', 'credit')->set('adjustmentAmount', '10')->set('adjustmentReason', 'Goodwill discount')->call('submitInvoiceCredit')->assertHasNoErrors();
        $this->assertDatabaseHas('subscription_approval_requests', ['action' => 'credit_note', 'target_id' => $invoice->id]);

        $page->call('startInvoiceAction', 'pay')->set('paymentAmount', '110')->call('submitInvoicePayment');
        $payment = $invoice->fresh()->payments()->firstOrFail();
        $page->call('startInvoiceAction', 'refund', $payment->id)->assertSet('refundPaymentId', $payment->id)
            ->set('adjustmentReason', 'Paid twice')->call('submitInvoiceRefund')->assertHasNoErrors();
        $this->assertDatabaseHas('subscription_approval_requests', ['action' => 'payment_refund', 'target_id' => $payment->id]);

        $this->billing()->assertSee('Approvals')->call('setBillingSection', 'approvals')->assertSee('Issued to the wrong clinic');
        $this->assertSame(3, SubscriptionApprovalRequest::where('status', 'pending')->count());
    }

    public function test_void_is_not_offered_once_an_invoice_has_payments(): void
    {
        $invoice = $this->invoice('INV-LOCKED', 'paid');

        $this->billing()->call('openInvoice', $invoice->id)->assertDontSee('Void invoice')->assertSee('Receipt PDF');
    }

    public function test_invoice_pdf_is_available_to_platform_admins(): void
    {
        $invoice = $this->invoice('INV-PDF');

        $this->actingAs($this->developer)->withSession(['workspace_mode' => 'platform'])->get(route('platform.invoice.pdf', $invoice))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('platform.invoice.pdf', $invoice))->assertForbidden();
    }
}
