<?php

namespace Tests\Feature;

use App\Livewire\Admin\RefundApprovalsComponent;
use App\Livewire\Cashier\SalesRecordsComponent;
use App\Models\{Category, Patient, PaymentTransaction, Product, RefundLog, SaleItem, Sales, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The consultation fee is recorded at clearance as an open visit bill until the end of the day.
 * Refunds used to be refused while it was open, so a same-day fee could not be refunded.
 */
class ConsultationFeeRefundTest extends TestCase
{
    use DatabaseTransactions;

    private function consultationFeeSale(User $cashier, array $overrides = []): Sales
    {
        $patient = Patient::factory()->create(['user_id' => $cashier->id]);
        $services = Category::factory()->create(['user_id' => $cashier->id, 'name' => 'Services '.uniqid()]);
        $service = Product::factory()->create(['user_id' => $cashier->id, 'category_id' => $services->id, 'name' => 'Consultation', 'selling_price' => 150]);
        // As CashierPatientClearanceComponent records a paid clearance.
        $sale = Sales::create(array_merge(['business_line' => 'clinic', 'user_id' => $cashier->id, 'patient_id' => $patient->id,
            'transaction_id' => 'TXN-CONSULT-'.uniqid(), 'total_amount' => 150, 'amount_paid' => 150, 'payment_status' => 'paid',
            'bill_status' => 'open', 'expires_at' => now()->endOfDay()], $overrides));
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $service->id, 'prescribed_quantity' => 1, 'dispensed_quantity' => 1,
            'selling_price' => 150, 'subtotal' => 150, 'notes' => 'Clearance Service']);
        PaymentTransaction::create(['sale_id' => $sale->id, 'amount' => 150, 'payment_method' => 'cash', 'collected_by' => $cashier->id]);

        return $sale;
    }

    public function test_todays_consultation_fee_can_be_refunded(): void
    {
        foreach (['Cashier', 'Manager'] as $role) Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');
        $manager = User::factory()->create();
        $manager->assignRole('Manager');
        $this->actingAs($cashier);
        $sale = $this->consultationFeeSale($cashier);

        Livewire::test(SalesRecordsComponent::class)
            ->call('initiateRefund', $sale->id)->assertDispatched('show-initiateRefundModal')
            ->set('initiateRefundReasonCode', 'other')->set('initiateRefundType', 'refund')
            ->set('initiateRefundReason', 'Patient left before seeing the doctor.')
            ->call('submitRefundRequest')->assertHasNoErrors();

        $this->assertSame('finalized', $sale->fresh()->bill_status, 'The paid visit bill is closed for the refund.');
        $this->assertDatabaseHas('audit_trails', ['event' => 'visit_bill.finalized_for_refund', 'auditable_id' => $sale->id]);
        $refund = RefundLog::where('sale_id', $sale->id)->firstOrFail();

        $this->actingAs($manager);
        Livewire::test(RefundApprovalsComponent::class)->call('confirmApprove', $refund->id);
        Livewire::test(RefundApprovalsComponent::class)->call('process', $refund->id)->assertDispatched('refund-receipt-ready');

        $this->assertSame(RefundLog::STATUS_PROCESSED, $refund->fresh()->status);
        $this->assertSame('150.00', $refund->fresh()->refunded_amount);
        $this->assertTrue($sale->fresh()->is_refunded);
    }

    public function test_approving_a_revoke_requests_a_refund_of_the_fee_only(): void
    {
        foreach (['Cashier', 'Manager'] as $role) Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');
        $manager = User::factory()->create();
        $manager->assignRole('Manager');
        $this->actingAs($cashier);
        $sale = $this->consultationFeeSale($cashier);
        $feeItem = $sale->items()->firstOrFail();
        // Drops bought later in the visit, on the same open bill: not part of the fee refund.
        $drops = Product::factory()->create(['user_id' => $cashier->id, 'quantity' => 5, 'selling_price' => 40]);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $drops->id, 'prescribed_quantity' => 0, 'dispensed_quantity' => 1, 'selling_price' => 40, 'subtotal' => 40]);

        $clearance = \App\Models\CashierPatientClearance::create(['user_id' => $cashier->id, 'patient_id' => $sale->patient_id,
            'service_id' => $feeItem->product_id, 'sale_id' => $sale->id, 'payment_status' => 'Paid', 'doctor_status' => false, 'clearance_date' => today()]);
        $revoke = \App\Models\ClearanceRevokeLog::create(['clearance_id' => $clearance->id, 'status' => 'pending', 'requested_by' => $cashier->id,
            'reason' => 'Patient left before seeing the doctor.', 'requested_at' => now()]);

        $this->actingAs($manager);
        Livewire::test(\App\Livewire\Admin\ClearanceRevokeApprovalsComponent::class)
            ->call('approve', $revoke->id)
            ->assertDispatched('notify', fn ($name, $params) => str_contains($params['message'] ?? '', 'refund request for the GH₵ 150.00 fee'));

        $this->assertSoftDeleted($clearance);
        $refund = RefundLog::where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame(RefundLog::STATUS_PENDING, $refund->status);
        $this->assertSame([$feeItem->id], $refund->sale_item_ids);
        $this->assertSame($cashier->id, (int) $refund->initiated_by);
        $this->assertSame('service_cancelled', $refund->reason_code);
        $this->assertStringContainsString('Patient left before seeing the doctor.', $refund->reason);
        $this->assertSame('finalized', $sale->fresh()->bill_status);

        // Approving and processing it pays back the fee, not the drops.
        Livewire::test(RefundApprovalsComponent::class)->call('confirmApprove', $refund->id);
        Livewire::test(RefundApprovalsComponent::class)->call('process', $refund->id)->assertDispatched('refund-receipt-ready');
        $this->assertSame('150.00', $refund->fresh()->refunded_amount);

        // Approving the same revoke twice does nothing more.
        $this->assertSame(1, RefundLog::where('sale_id', $sale->id)->count());
    }

    public function test_a_bill_with_a_balance_owing_explains_instead_of_erroring(): void
    {
        Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');
        $this->actingAs($cashier);
        $sale = $this->consultationFeeSale($cashier, ['amount_paid' => 50, 'payment_status' => 'partial']);

        Livewire::test(SalesRecordsComponent::class)
            ->call('initiateRefund', $sale->id)
            ->assertNotDispatched('show-initiateRefundModal')
            ->assertDispatched('notify', fn ($name, $params) => str_contains($params['message'] ?? '', 'balance owing'));

        $this->assertSame('open', $sale->fresh()->bill_status);
    }
}
