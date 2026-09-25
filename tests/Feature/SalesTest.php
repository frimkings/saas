<?php

namespace Tests\Feature;

use App\Livewire\Cashier\SalesRecordsComponent;
use App\Livewire\POSComponent;
use App\Models\Patient;
use App\Models\CashierPatientClearance;
use App\Models\Category;
use App\Models\Cart;
use App\Models\Consultations;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\RefundLog;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use DatabaseTransactions;

    private User $cashier;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);

        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('Cashier');
        $this->actingAs($this->cashier);

        $this->patient = Patient::factory()->create([
            'user_id' => $this->cashier->id,
        ]);
    }

    private function makeSale(array $overrides = []): Sales
    {
        return Sales::create(array_merge([
            'user_id'        => $this->cashier->id,
            'patient_id'     => $this->patient->id,
            'transaction_id' => 'TXN-' . strtoupper(bin2hex(random_bytes(4))),
            'total_amount'   => 200.00,
            'amount_paid'    => 200.00,
            'payment_status' => 'paid',
        ], $overrides));
    }

    // ── Sales model ──────────────────────────────────────────────────────

    public function test_remaining_balance_is_zero_when_fully_paid(): void
    {
        $sale = $this->makeSale(['total_amount' => 150.00, 'amount_paid' => 150.00]);

        $this->assertEquals(0.0, $sale->remaining_balance);
    }

    public function test_remaining_balance_reflects_partial_payment(): void
    {
        $sale = $this->makeSale(['total_amount' => 300.00, 'amount_paid' => 100.00]);

        $this->assertEquals(200.0, $sale->remaining_balance);
    }

    public function test_remaining_balance_never_goes_negative(): void
    {
        $sale = $this->makeSale(['total_amount' => 100.00, 'amount_paid' => 150.00]);

        $this->assertEquals(0.0, $sale->remaining_balance);
    }

    public function test_is_fully_paid_returns_true_for_paid_status(): void
    {
        $sale = $this->makeSale(['payment_status' => 'paid']);

        $this->assertTrue($sale->isFullyPaid());
    }

    public function test_is_fully_paid_returns_false_for_partial_status(): void
    {
        $sale = $this->makeSale(['payment_status' => 'partial', 'amount_paid' => 100.00]);

        $this->assertFalse($sale->isFullyPaid());
    }

    public function test_sale_belongs_to_patient(): void
    {
        $sale = $this->makeSale();

        $this->assertInstanceOf(Patient::class, $sale->patient);
        $this->assertEquals($this->patient->id, $sale->patient->id);
    }

    public function test_sale_belongs_to_user(): void
    {
        $sale = $this->makeSale();

        $this->assertInstanceOf(User::class, $sale->user);
        $this->assertEquals($this->cashier->id, $sale->user->id);
    }

    public function test_direct_purchase_uses_customer_name_snapshot(): void
    {
        $sale = $this->makeSale([
            'patient_id' => null,
            'customer_name' => 'Ama Boateng',
        ]);

        $this->assertEquals('Ama Boateng', $sale->customer_display_name);
    }

    public function test_unnamed_direct_purchase_is_walk_in(): void
    {
        $sale = $this->makeSale([
            'patient_id' => null,
            'customer_name' => null,
        ]);

        $this->assertEquals('Walk-in', $sale->customer_display_name);
    }

    public function test_pos_can_switch_to_direct_purchase_mode(): void
    {
        Livewire::test(POSComponent::class)
            ->call('selectDirectPurchaseMode')
            ->assertSet('purchaseMode', 'direct')
            ->set('directCustomerName', 'Direct Buyer')
            ->assertSet('directCustomerName', 'Direct Buyer');
    }

    public function test_pos_does_not_switch_selected_patient_cart_to_direct_mode(): void
    {
        Livewire::test(POSComponent::class)
            ->set('patientId', $this->patient->id)
            ->call('selectDirectPurchaseMode')
            ->assertSet('purchaseMode', 'patient')
            ->assertDispatched('notify');
    }

    public function test_walk_in_cannot_enable_part_payment(): void
    {
        Livewire::test(POSComponent::class)
            ->set('isPartPayment', true)
            ->assertSet('isPartPayment', false)
            ->assertDispatched('notify');
    }

    public function test_business_line_scopes_do_not_conflict_with_clinic_relation(): void
    {
        $clinicSale = $this->makeSale(['business_line' => 'clinic']);
        $opticalSale = $this->makeSale(['business_line' => 'optical']);

        $this->assertTrue(Sales::clinicSales()->whereKey($clinicSale->id)->exists());
        $this->assertFalse(Sales::clinicSales()->whereKey($opticalSale->id)->exists());
        $this->assertTrue(Sales::opticalSales()->whereKey($opticalSale->id)->exists());
    }

    public function test_direct_checkout_persists_customer_and_decrements_stock_once(): void
    {
        $product = Product::factory()->create([
            'user_id' => $this->cashier->id,
            'quantity' => 10,
            'cost_price' => 60,
            'selling_price' => 100,
        ]);

        $component = Livewire::test(POSComponent::class)
            ->call('selectDirectPurchaseMode')
            ->set('directCustomerName', 'Direct Buyer')
            ->call('addToCart', $product->id)
            ->set('newPaymentMethod', 'cash')
            ->set('newPaymentAmount', 100)
            ->call('addPayment');

        $idempotencyKey = $component->get('checkoutIdempotencyKey');

        $component->call('checkout')->assertHasNoErrors();

        $sale = Sales::where('idempotency_key', $idempotencyKey)->firstOrFail();

        $this->assertSame('Direct Buyer', $sale->customer_name);
        $this->assertSame(9, $product->fresh()->quantity);
        $this->assertSame(1, $sale->items()->count());
        $this->assertSame(1, $sale->paymentTransactions()->count());
    }

    public function test_retried_checkout_key_does_not_duplicate_sale_or_stock_decrement(): void
    {
        $product = Product::factory()->create([
            'user_id' => $this->cashier->id,
            'quantity' => 10,
            'cost_price' => 60,
            'selling_price' => 100,
        ]);
        $idempotencyKey = (string) \Illuminate\Support\Str::uuid();

        $checkout = fn () => Livewire::test(POSComponent::class)
            ->call('selectDirectPurchaseMode')
            ->set('directCustomerName', 'Retry Buyer')
            ->call('addToCart', $product->id)
            ->set('newPaymentMethod', 'cash')
            ->set('newPaymentAmount', 100)
            ->call('addPayment')
            ->set('checkoutIdempotencyKey', $idempotencyKey)
            ->call('checkout');

        $checkout()->assertHasNoErrors();
        $checkout()->assertRedirect();

        $this->assertSame(1, Sales::where('idempotency_key', $idempotencyKey)->count());
        $this->assertSame(9, $product->fresh()->quantity);
    }

    public function test_checkout_recalculates_tampered_client_totals_from_product_prices(): void
    {
        $product = Product::factory()->create(['user_id' => $this->cashier->id, 'quantity' => 5, 'cost_price' => 30, 'selling_price' => 100]);
        $component = Livewire::test(POSComponent::class)
            ->call('selectDirectPurchaseMode')->call('addToCart', $product->id)
            ->set('newPaymentAmount', 100)->call('addPayment')
            ->set('totalAmount', 1)->set('finalAmount', 1)->set('amountPaid', 1);
        $key = $component->get('checkoutIdempotencyKey');
        $component->call('checkout');
        $sale = Sales::where('idempotency_key', $key)->firstOrFail();
        $this->assertSame('100.00', $sale->total_amount);
        $this->assertSame('100.00', $sale->amount_paid);
    }

    public function test_forged_discount_flags_without_owned_approval_cannot_reduce_sale(): void
    {
        $category = Category::factory()->create(['user_id' => $this->cashier->id, 'name' => 'Frames Security']);
        $product = Product::factory()->create(['user_id' => $this->cashier->id, 'category_id' => $category->id, 'quantity' => 5, 'selling_price' => 100]);
        $component = Livewire::test(POSComponent::class)
            ->call('selectDirectPurchaseMode')->call('addToCart', $product->id)
            ->set('discountType', 'percentage')->set('discountValue', 50)
            ->set('discountApproved', true)->set('discountApprovedById', $this->cashier->id)
            ->set('pendingDiscountApprovalId', null)
            ->set('newPaymentAmount', 50)->call('addPayment');
        $key = $component->get('checkoutIdempotencyKey');
        $component->call('checkout')->assertDispatched('notify');
        $this->assertDatabaseMissing('sales', ['idempotency_key' => $key]);
    }

    public function test_checkout_rejects_consultation_that_does_not_belong_to_selected_patient(): void
    {
        $other = Patient::factory()->create(['user_id' => $this->cashier->id]);
        $clearance = CashierPatientClearance::create(['user_id' => $this->cashier->id, 'patient_id' => $other->id, 'payment_status' => 'Paid', 'doctor_status' => false, 'clearance_date' => today()]);
        $foreignConsultation = Consultations::create(['patient_id' => $other->id, 'user_id' => $this->cashier->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Foreign visit']);
        $product = Product::factory()->create(['user_id' => $this->cashier->id, 'quantity' => 5, 'selling_price' => 100]);
        $component = Livewire::test(POSComponent::class)
            ->set('patientId', $this->patient->id)
            ->set('cart', [$product->id => ['product_id' => $product->id, 'quantity' => 1]])
            ->set('prescriptionConsultationId', $foreignConsultation->id)
            ->set('payments', [['method' => 'cash', 'amount' => 100]]);
        $key = $component->get('checkoutIdempotencyKey');
        $component->call('checkout')->assertDispatched('notify');
        $this->assertDatabaseMissing('sales', ['idempotency_key' => $key]);
    }

    public function test_registered_patient_can_enable_part_payment(): void
    {
        Livewire::test(POSComponent::class)
            ->set('patientId', $this->patient->id)
            ->set('isPartPayment', true)
            ->assertSet('isPartPayment', true);
    }

    public function test_patient_checkout_can_merge_into_open_clearance_visit_bill(): void
    {
        $category = Category::factory()->create([
            'user_id' => $this->cashier->id,
            'name' => 'Visit Bill Products ' . uniqid(),
        ]);
        $service = Product::factory()->create([
            'user_id' => $this->cashier->id,
            'category_id' => $category->id,
            'quantity' => 100,
            'cost_price' => 20,
            'selling_price' => 50,
        ]);
        $medicine = Product::factory()->create([
            'user_id' => $this->cashier->id,
            'category_id' => $category->id,
            'quantity' => 10,
            'cost_price' => 60,
            'selling_price' => 100,
        ]);
        $visitSale = $this->makeSale([
            'total_amount' => 50,
            'amount_paid' => 50,
            'bill_status' => 'open',
        ]);
        SaleItem::create([
            'sale_id' => $visitSale->id,
            'product_id' => $service->id,
            'prescribed_quantity' => 1,
            'dispensed_quantity' => 1,
            'selling_price' => 50,
            'subtotal' => 50,
            'notes' => 'Clearance Service',
        ]);
        PaymentTransaction::create([
            'sale_id' => $visitSale->id,
            'amount' => 50,
            'payment_method' => 'cash',
            'collected_by' => $this->cashier->id,
        ]);
        CashierPatientClearance::create([
            'user_id' => $this->cashier->id,
            'patient_id' => $this->patient->id,
            'service_id' => $service->id,
            'payment_status' => 'Paid',
            'clearance_date' => today(),
            'sale_id' => $visitSale->id,
        ]);
        $clearance = CashierPatientClearance::where('sale_id', $visitSale->id)->firstOrFail();
        $consultation = Consultations::create([
            'user_id' => $this->cashier->id,
            'patient_id' => $this->patient->id,
            'clearance_id' => $clearance->id,
            'chiefComplaint' => 'Test consultation',
        ]);
        Cart::create([
            'status' => 'pending',
            'is_dispensed' => false,
            'purchased' => false,
            'consultation_id' => $consultation->id,
            'quantity' => 1,
            'patient_id' => $this->patient->id,
            'dispensed_by' => $this->cashier->id,
            'product_id' => $medicine->id,
            'price' => 100,
            'total' => 100,
        ]);

        Livewire::test(POSComponent::class)
            ->call('loadCartFromList', $this->patient->id, $consultation->id)
            ->set('newPaymentMethod', 'card')
            ->set('newPaymentAmount', 100)
            ->call('addPayment')
            ->call('checkout')
            ->assertHasNoErrors();

        $visitSale->refresh();
        $this->assertSame(1, Sales::where('patient_id', $this->patient->id)->count());
        $this->assertSame(150.0, (float) $visitSale->total_amount);
        $this->assertSame(150.0, (float) $visitSale->amount_paid);
        $this->assertSame('finalized', $visitSale->bill_status);
        $this->assertSame(2, $visitSale->items()->count());
        $this->assertSame(2, $visitSale->paymentTransactions()->count());
        $this->assertSame(9, $medicine->fresh()->quantity);
    }

    // ── SalesRecordsComponent ────────────────────────────────────────────

    public function test_cashier_can_view_sales_records_component(): void
    {
        $this->makeSale();

        Livewire::test(SalesRecordsComponent::class)
            ->assertStatus(200);
    }

    public function test_admin_can_view_sales_records_page(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $this->actingAs($admin)
            ->get(route('admin.sales-records'))
            ->assertOk();
    }

    public function test_search_filters_by_transaction_id(): void
    {
        $txnId = 'TXN-FINDME12345';
        $this->makeSale(['transaction_id' => $txnId]);
        $this->makeSale(); // noise

        $ids = Livewire::test(SalesRecordsComponent::class)
            ->set('searchTerm', 'FINDME12345')
            ->set('fromDate', now()->subDay()->format('Y-m-d'))
            ->set('toDate', now()->addDay()->format('Y-m-d'))
            ->viewData('sales')
            ->pluck('transaction_id');

        $this->assertContains($txnId, $ids->toArray());
    }

    public function test_search_filters_by_direct_customer_name(): void
    {
        $sale = $this->makeSale([
            'patient_id' => null,
            'customer_name' => 'Unique Direct Buyer',
        ]);

        $ids = Livewire::test(SalesRecordsComponent::class)
            ->set('searchTerm', 'Direct Buyer')
            ->set('fromDate', now()->subDay()->format('Y-m-d'))
            ->set('toDate', now()->addDay()->format('Y-m-d'))
            ->viewData('sales')
            ->pluck('id');

        $this->assertContains($sale->id, $ids->toArray());
    }

    public function test_date_range_excludes_sales_outside_window(): void
    {
        $old = $this->makeSale();
        Sales::where('id', $old->id)->update(['created_at' => now()->subMonth()]);

        $recent = $this->makeSale();

        $ids = Livewire::test(SalesRecordsComponent::class)
            ->set('fromDate', now()->format('Y-m-d'))
            ->set('toDate', now()->format('Y-m-d'))
            ->viewData('sales')
            ->pluck('id');

        $this->assertContains($recent->id, $ids->toArray());
        $this->assertNotContains($old->id, $ids->toArray());
    }

    public function test_refund_filter_shows_only_non_refunded_sales(): void
    {
        $normal   = $this->makeSale(['is_refunded' => false]);
        $refunded = $this->makeSale(['is_refunded' => true]);

        $ids = Livewire::test(SalesRecordsComponent::class)
            ->set('fromDate', now()->format('Y-m-d'))
            ->set('toDate', now()->format('Y-m-d'))
            ->set('filterRefunded', 0)
            ->viewData('sales')
            ->pluck('id');

        $this->assertContains($normal->id, $ids->toArray());
        $this->assertNotContains($refunded->id, $ids->toArray());
    }

    // ── Refund flow ──────────────────────────────────────────────────────

    public function test_refund_request_requires_reason_of_at_least_10_chars(): void
    {
        $sale = $this->makeSale();

        Livewire::test(SalesRecordsComponent::class)
            ->call('initiateRefund', $sale->id)
            ->set('initiateRefundReasonCode', 'other')
            ->set('initiateRefundType', 'refund')
            ->set('initiateRefundReason', 'Too short')
            ->call('submitRefundRequest')
            ->assertHasErrors(['initiateRefundReason']);
    }

    public function test_cashier_can_submit_refund_request(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $sale = $this->makeSale();
        $product = Product::factory()->create(['user_id' => $this->cashier->id]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'prescribed_quantity' => 1,
            'dispensed_quantity' => 1,
            'selling_price' => 200,
            'subtotal' => 200,
        ]);

        Livewire::test(SalesRecordsComponent::class)
            ->call('initiateRefund', $sale->id)
            ->set('initiateRefundReasonCode', 'customer_return')
            ->set('initiateRefundType', 'refund')
            ->set('initiateRefundReason', 'Customer requested a refund for this purchase.')
            ->call('submitRefundRequest')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('refund_logs', [
            'sale_id'      => $sale->id,
            'status'       => RefundLog::STATUS_PENDING,
            'initiated_by' => $this->cashier->id,
        ]);
    }

    public function test_duplicate_refund_request_is_blocked(): void
    {
        $sale = $this->makeSale();

        RefundLog::create([
            'sale_id'      => $sale->id,
            'status'       => RefundLog::STATUS_PENDING,
            'initiated_by' => $this->cashier->id,
            'reason'       => 'Original refund request here.',
            'initiated_at' => now(),
        ]);

        Livewire::test(SalesRecordsComponent::class)
            ->call('initiateRefund', $sale->id)
            ->assertDispatched('notify');

        $this->assertEquals(1, RefundLog::where('sale_id', $sale->id)->count());
    }

    public function test_already_refunded_sale_triggers_http_exception(): void
    {
        $sale = $this->makeSale(['is_refunded' => true]);

        $this->withoutExceptionHandling();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        Livewire::test(SalesRecordsComponent::class)
            ->call('initiateRefund', $sale->id);
    }
}
