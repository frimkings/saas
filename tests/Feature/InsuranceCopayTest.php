<?php

namespace Tests\Feature;

use App\Livewire\Admin\InsurerReceivablesComponent;
use App\Livewire\Admin\InsurersComponent;
use App\Livewire\Admin\RefundApprovalsComponent;
use App\Livewire\Cashier\CashierPatientClearanceComponent;
use App\Livewire\OutstandingBalancesComponent;
use App\Livewire\POSComponent;
use App\Models\CashierPatientClearance;
use App\Models\Category;
use App\Models\InsuranceClaim;
use App\Models\Insurer;
use App\Models\InsurerCoverageRule;
use App\Models\Patient;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\RefundLog;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Models\User;
use App\Services\Insurance\InsuranceBilling;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class InsuranceCopayTest extends TestCase
{
    use GivesClinicsAccess, DatabaseTransactions;

    private User $cashier;
    private Insurer $insurer;
    private Patient $patient;
    private Category $services;
    private Category $drugs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startOfflineTrial();
        foreach (['Cashier', 'Manager', 'Super Admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->cashier = User::factory()->create();
        $this->cashier->assignRole(['Cashier', 'Manager']);
        $this->actingAs($this->cashier);

        $this->insurer = Insurer::create(['name' => 'Test Health ' . uniqid(), 'scheme_type' => 'Private', 'active' => true]);
        $this->patient = Patient::factory()->create([
            'user_id' => $this->cashier->id,
            'insurer_id' => $this->insurer->id,
            'insurance_member_id' => 'MEM-001',
        ]);
        $this->services = Category::factory()->create([
            'user_id' => $this->cashier->id, 'name' => 'Clinical Services ' . uniqid(), 'type' => 'service',
        ]);
        $this->drugs = Category::factory()->create([
            'user_id' => $this->cashier->id, 'name' => 'Eye Drops ' . uniqid(),
        ]);
    }

    // ── Coverage rules ───────────────────────────────────────────────────

    public function test_category_rule_applies_and_product_rule_overrides_it(): void
    {
        $consult = $this->product($this->services, 400);
        $scan = $this->product($this->services, 200);
        $this->rule(['category_id' => $this->services->id, 'coverage_type' => 'percent', 'coverage_value' => 80]);
        $this->rule(['product_id' => $scan->id, 'coverage_type' => 'excluded']);

        $billing = new InsuranceBilling();

        $this->assertSame(['unit_price' => 400.0, 'insurer' => 320.0, 'covered' => true], $billing->splitLine($this->insurer, $consult));
        $this->assertSame(['unit_price' => 200.0, 'insurer' => 0.0, 'covered' => false], $billing->splitLine($this->insurer, $scan));
    }

    public function test_items_without_a_rule_are_paid_by_the_patient(): void
    {
        $split = (new InsuranceBilling())->splitLine($this->insurer, $this->product($this->drugs, 50), 3);

        $this->assertSame(0.0, $split['insurer']);
        $this->assertFalse($split['covered']);
    }

    public function test_tariff_sets_the_price_when_patient_may_not_pay_the_difference(): void
    {
        $consult = $this->product($this->services, 400);
        $this->rule(['product_id' => $consult->id, 'coverage_type' => 'percent', 'coverage_value' => 50, 'tariff_price' => 300]);

        $this->insurer->update(['patient_pays_difference' => true]);
        $this->assertSame(['unit_price' => 400.0, 'insurer' => 150.0, 'covered' => true], (new InsuranceBilling())->splitLine($this->insurer->fresh(), $consult));

        $this->insurer->update(['patient_pays_difference' => false]);
        $this->assertSame(['unit_price' => 300.0, 'insurer' => 150.0, 'covered' => true], (new InsuranceBilling())->splitLine($this->insurer->fresh(), $consult));
    }

    public function test_fixed_cover_is_per_item_and_never_more_than_the_price(): void
    {
        $drops = $this->product($this->drugs, 30);
        $this->rule(['category_id' => $this->drugs->id, 'coverage_type' => 'fixed', 'coverage_value' => 50]);

        $this->assertSame(60.0, (new InsuranceBilling())->splitLine($this->insurer, $drops, 2)['insurer']);
    }

    public function test_inactive_insurer_is_not_billed(): void
    {
        $this->insurer->update(['active' => false]);

        $this->assertNull((new InsuranceBilling())->insurerFor($this->patient->fresh()));
    }

    // ── Clearance ────────────────────────────────────────────────────────

    public function test_insured_clearance_collects_only_the_patient_share_and_drafts_a_claim(): void
    {
        $consult = $this->product($this->services, 400);
        $this->rule(['category_id' => $this->services->id, 'coverage_type' => 'percent', 'coverage_value' => 80]);

        $this->clearance()
            ->call('openClearanceModal', $this->patient->id)
            ->assertSet('insuranceSplits.' . $consult->id, ['price' => 400.0, 'insurer' => 320.0, 'patient' => 80.0])
            ->call('createClearance', (string) $consult->id, json_encode([['method' => 'cash', 'amount' => 80]]))
            ->assertHasNoErrors();

        $clearance = CashierPatientClearance::where('patient_id', $this->patient->id)->firstOrFail();
        $sale = Sales::findOrFail($clearance->sale_id);

        $this->assertSame('Paid', $clearance->payment_status);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('400.00', $sale->total_amount);
        $this->assertSame('320.00', $sale->insurer_amount);
        $this->assertSame('80.00', $sale->amount_paid);
        $this->assertSame((int) $this->insurer->id, (int) $sale->insurer_id);
        $this->assertSame(0.0, $sale->remaining_balance);

        $claim = InsuranceClaim::where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame('draft', $claim->status);
        $this->assertSame('320.00', $claim->claim_amount);
        $this->assertSame('MEM-001', $claim->member_id);
    }

    public function test_insured_clearance_refuses_the_full_price_from_the_patient(): void
    {
        $consult = $this->product($this->services, 400);
        $this->rule(['category_id' => $this->services->id, 'coverage_type' => 'percent', 'coverage_value' => 80]);

        $this->clearance()
            ->call('createClearance', (string) $consult->id, json_encode([['method' => 'cash', 'amount' => 400]]))
            ->assertHasErrors('selectedServiceId');

        $this->assertFalse(Sales::where('patient_id', $this->patient->id)->exists());
    }

    public function test_fully_covered_clearance_needs_no_payment(): void
    {
        $consult = $this->product($this->services, 400);
        $this->rule(['category_id' => $this->services->id, 'coverage_type' => 'percent', 'coverage_value' => 100]);

        $this->clearance()
            ->call('createClearance', (string) $consult->id, '[]')
            ->assertHasNoErrors();

        $sale = Sales::where('patient_id', $this->patient->id)->firstOrFail();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('400.00', $sale->insurer_amount);
        $this->assertSame(0, PaymentTransaction::where('sale_id', $sale->id)->count());
    }

    public function test_changing_the_insurer_share_needs_a_reason_and_is_audited(): void
    {
        $consult = $this->product($this->services, 400);
        $this->rule(['category_id' => $this->services->id, 'coverage_type' => 'percent', 'coverage_value' => 80]);
        $payments = json_encode([['method' => 'cash', 'amount' => 400]]);

        $this->clearance()
            ->call('createClearance', (string) $consult->id, $payments, json_encode(['bill' => false, 'reason' => '']))
            ->assertHasErrors('selectedServiceId');
        $this->assertFalse(Sales::where('patient_id', $this->patient->id)->exists());

        $this->clearance()
            ->call('createClearance', (string) $consult->id, $payments, json_encode(['bill' => false, 'reason' => 'Card expired last month']))
            ->assertHasNoErrors();

        $sale = Sales::where('patient_id', $this->patient->id)->firstOrFail();
        $this->assertSame('0.00', $sale->insurer_amount);
        $this->assertNull($sale->insurer_id);
        $this->assertFalse(InsuranceClaim::where('sale_id', $sale->id)->exists());
        $this->assertDatabaseHas('audit_trails', ['event' => 'insurance.split_adjusted', 'auditable_id' => $sale->id]);
    }

    public function test_uninsured_clearance_is_unchanged(): void
    {
        $consult = $this->product($this->services, 400);
        $this->patient->update(['insurer_id' => null]);

        $this->clearance()
            ->call('createClearance', (string) $consult->id, json_encode([['method' => 'cash', 'amount' => 400]]))
            ->assertHasNoErrors();

        $sale = Sales::where('patient_id', $this->patient->id)->firstOrFail();
        $this->assertSame('400.00', $sale->amount_paid);
        $this->assertSame('0.00', $sale->insurer_amount);
    }

    // ── POS ──────────────────────────────────────────────────────────────

    public function test_pos_bills_the_insurer_share_and_collects_the_rest(): void
    {
        $covered = $this->product($this->drugs, 100);
        $uncovered = $this->product($this->services, 50);
        $this->rule(['category_id' => $this->drugs->id, 'coverage_type' => 'percent', 'coverage_value' => 70]);

        $component = $this->posWithCart([$covered, $uncovered])
            ->set('payments', [['method' => 'cash', 'amount' => 80]]);
        $key = $component->get('checkoutIdempotencyKey');

        $component->call('checkout')->assertHasNoErrors()
            ->assertDispatched('receipt-data-ready', fn ($event, $data) => (float) $data['insurer_amount'] === 70.0 && (float) $data['balance'] === 0.0);

        $sale = Sales::where('idempotency_key', $key)->firstOrFail();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('150.00', $sale->total_amount);
        $this->assertSame('70.00', $sale->insurer_amount);
        $this->assertSame('80.00', $sale->amount_paid);
        $this->assertSame('70.00', SaleItem::where('sale_id', $sale->id)->where('product_id', $covered->id)->value('insurer_amount'));
        $this->assertSame('0.00', SaleItem::where('sale_id', $sale->id)->where('product_id', $uncovered->id)->value('insurer_amount'));
        $this->assertSame('70.00', InsuranceClaim::where('sale_id', $sale->id)->value('claim_amount'));
    }

    public function test_pos_rejects_a_changed_insurer_share_without_a_reason(): void
    {
        $covered = $this->product($this->drugs, 100);
        $this->rule(['category_id' => $this->drugs->id, 'coverage_type' => 'percent', 'coverage_value' => 70]);

        $component = $this->posWithCart([$covered])
            ->set('insurerOverride', '50')
            ->assertSet('insurerAmount', 50.0)
            ->assertSet('amountDue', 50.0)
            ->set('payments', [['method' => 'cash', 'amount' => 50]]);
        $key = $component->get('checkoutIdempotencyKey');

        $component->call('checkout')->assertDispatched('notify');
        $this->assertFalse(Sales::where('idempotency_key', $key)->exists());

        $component->set('insuranceReason', 'Insurer capped this visit')->call('checkout');
        $this->assertSame('50.00', Sales::where('idempotency_key', $key)->value('insurer_amount'));
    }

    public function test_pos_direct_purchase_never_bills_an_insurer(): void
    {
        $covered = $this->product($this->drugs, 100);
        $this->rule(['category_id' => $this->drugs->id, 'coverage_type' => 'percent', 'coverage_value' => 70]);

        Livewire::test(POSComponent::class)
            ->call('selectDirectPurchaseMode')
            ->call('addToCart', $covered->id)
            ->assertSet('insurerAmount', 0.0)
            ->assertSet('amountDue', 100.0);
    }

    // ── Balances, refunds, receivables ───────────────────────────────────

    public function test_outstanding_balance_excludes_the_insurer_share(): void
    {
        $sale = $this->insuredSale(['total_amount' => 500, 'insurer_amount' => 300, 'amount_paid' => 50, 'payment_status' => 'partial']);

        $this->assertSame(150.0, $sale->remaining_balance);

        Livewire::test(OutstandingBalancesComponent::class)
            ->set('selectedSaleId', $sale->id)
            ->set('collectAmount', 200)
            ->set('paymentMethod', 'cash')
            ->call('collectPayment')
            ->assertHasErrors('collectAmount');

        Livewire::test(OutstandingBalancesComponent::class)
            ->set('selectedSaleId', $sale->id)
            ->set('collectAmount', 150)
            ->set('paymentMethod', 'cash')
            ->call('collectPayment')
            ->assertHasNoErrors();

        $this->assertSame('paid', $sale->fresh()->payment_status);
    }

    public function test_refund_returns_only_what_the_patient_paid_and_reduces_the_draft_claim(): void
    {
        $drops = $this->product($this->drugs, 100, 5);
        $sale = $this->insuredSale(['total_amount' => 100, 'insurer_amount' => 70, 'amount_paid' => 30]);
        SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $drops->id, 'prescribed_quantity' => 1, 'dispensed_quantity' => 1,
            'selling_price' => 100, 'subtotal' => 100, 'insurer_amount' => 70,
        ]);
        PaymentTransaction::create(['sale_id' => $sale->id, 'amount' => 30, 'payment_method' => 'cash', 'collected_by' => $this->cashier->id]);
        app(InsuranceBilling::class)->syncDraftClaim($sale);
        $refund = $this->approvedRefund($sale);

        Livewire::test(RefundApprovalsComponent::class)->call('process', $refund->id);

        $this->assertSame('30.00', $refund->fresh()->refunded_amount);
        $this->assertSame('0.00', $sale->fresh()->insurer_amount);
        $this->assertTrue(InsuranceClaim::withTrashed()->where('sale_id', $sale->id)->firstOrFail()->trashed());
    }

    public function test_refund_is_blocked_once_the_claim_is_submitted(): void
    {
        $drops = $this->product($this->drugs, 100, 5);
        $sale = $this->insuredSale(['total_amount' => 100, 'insurer_amount' => 70, 'amount_paid' => 30]);
        SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $drops->id, 'prescribed_quantity' => 1, 'dispensed_quantity' => 1,
            'selling_price' => 100, 'subtotal' => 100, 'insurer_amount' => 70,
        ]);
        app(InsuranceBilling::class)->syncDraftClaim($sale)->update(['status' => 'submitted', 'submission_date' => today()]);
        $refund = $this->approvedRefund($sale);

        Livewire::test(RefundApprovalsComponent::class)->call('process', $refund->id)->assertDispatched('notify');

        $this->assertSame(RefundLog::STATUS_APPROVED, $refund->fresh()->status);
        $this->assertSame('70.00', $sale->fresh()->insurer_amount);
    }

    public function test_receivables_list_open_insurer_shares_until_the_claim_is_paid(): void
    {
        $open = $this->insuredSale(['total_amount' => 400, 'insurer_amount' => 320, 'amount_paid' => 80]);
        $paid = $this->insuredSale(['total_amount' => 200, 'insurer_amount' => 200, 'amount_paid' => 0]);
        app(InsuranceBilling::class)->syncDraftClaim($open);
        app(InsuranceBilling::class)->syncDraftClaim($paid)->update(['status' => 'paid', 'payment_date' => today()]);

        Livewire::test(InsurerReceivablesComponent::class)
            ->assertSee($open->transaction_id)
            ->assertDontSee($paid->transaction_id)
            ->assertSee('320.00');
    }

    public function test_printed_receipt_shows_the_insurer_share_and_no_balance(): void
    {
        $sale = $this->insuredSale(['total_amount' => 400, 'insurer_amount' => 320, 'amount_paid' => 80, 'business_line' => 'clinic']);

        $this->get(route('cashier.receipt.show', $sale->id))
            ->assertOk()
            ->assertSee('BILLED TO ' . strtoupper($this->insurer->name))
            ->assertSee('PATIENT PAYS')
            ->assertDontSee('BALANCE DUE');
    }

    public function test_insurers_screen_saves_billing_settings_and_coverage(): void
    {
        $consult = $this->product($this->services, 400);

        Livewire::test(InsurersComponent::class)
            ->call('openEdit', $this->insurer->id)
            ->set('state.patient_pays_difference', false)
            ->set('state.shortfall_action', 'write_off')
            ->call('save')
            ->assertHasNoErrors()
            ->call('openCoverage', $this->insurer->id)
            ->set('ruleState.target', 'category')
            ->set('ruleState.category_id', $this->services->id)
            ->set('ruleState.coverage_type', 'percent')
            ->set('ruleState.coverage_value', '150')
            ->call('saveRule')
            ->assertHasErrors('ruleState.coverage_value')
            ->set('ruleState.coverage_value', '90')
            ->call('saveRule')
            ->assertHasNoErrors()
            ->set('ruleState.target', 'product')
            ->call('selectRuleProduct', $consult->id)
            ->set('ruleState.coverage_type', 'fixed')
            ->set('ruleState.coverage_value', '100')
            ->set('ruleState.tariff_price', '250')
            ->call('saveRule')
            ->assertHasNoErrors();

        $insurer = $this->insurer->fresh();
        $this->assertFalse($insurer->patient_pays_difference);
        $this->assertSame('write_off', $insurer->shortfall_action);
        $this->assertSame(2, $insurer->coverageRules()->count());
        $this->assertSame(['unit_price' => 250.0, 'insurer' => 100.0, 'covered' => true], (new InsuranceBilling())->splitLine($insurer, $consult));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function clearance()
    {
        return Livewire::test(CashierPatientClearanceComponent::class)
            ->set('patientClearanceId', $this->patient->id)
            ->set('patientName', $this->patient->name);
    }

    /** A POS session for the insured patient with these products in the cart (one of each). */
    private function posWithCart(array $products)
    {
        $cart = [];
        foreach ($products as $product) {
            $cart[$product->id] = ['product_id' => $product->id, 'quantity' => 1];
        }

        return Livewire::test(POSComponent::class)
            ->call('selectPatient', $this->patient->id)
            ->set('cart', $cart)
            ->set('billInsurer', false)
            ->set('billInsurer', true);
    }

    private function product(Category $category, float $price, int $quantity = 20): Product
    {
        return Product::factory()->create([
            'user_id' => $this->cashier->id, 'category_id' => $category->id,
            'selling_price' => $price, 'cost_price' => round($price / 4, 2), 'quantity' => $quantity,
        ]);
    }

    private function rule(array $attributes): InsurerCoverageRule
    {
        return InsurerCoverageRule::create($attributes + ['insurer_id' => $this->insurer->id]);
    }

    private function insuredSale(array $attributes): Sales
    {
        return Sales::create($attributes + [
            'user_id' => $this->cashier->id,
            'patient_id' => $this->patient->id,
            'insurer_id' => $this->insurer->id,
            'transaction_id' => 'TXN-INS-' . strtoupper(bin2hex(random_bytes(4))),
            'payment_status' => 'paid',
        ]);
    }

    private function approvedRefund(Sales $sale): RefundLog
    {
        return RefundLog::create([
            'sale_id' => $sale->id,
            'request_type' => RefundLog::TYPE_REFUND,
            'reason_code' => 'customer_return',
            'status' => RefundLog::STATUS_APPROVED,
            'initiated_by' => $this->cashier->id,
            'approved_by' => $this->cashier->id,
            'reason' => 'Patient returned the eye drops unopened.',
            'initiated_at' => now(),
            'approved_at' => now(),
        ]);
    }
}
