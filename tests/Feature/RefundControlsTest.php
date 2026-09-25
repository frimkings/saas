<?php

namespace Tests\Feature;

use App\Livewire\Admin\RefundApprovalsComponent;
use App\Models\AuditTrail;
use App\Models\Category;
use App\Models\Patient;
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

class RefundControlsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_processing_refund_snapshots_payment_restores_stock_and_creates_receipt(): void
    {
        Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $manager = User::factory()->create();
        $manager->assignRole('Manager');
        $cashier = User::factory()->create();
        $patient = Patient::factory()->create(['user_id' => $cashier->id]);
        $category = Category::firstOrCreate(
            ['name' => 'Refund Test Items'],
            ['user_id' => $manager->id]
        );
        $product = Product::factory()->create([
            'user_id' => $manager->id,
            'category_id' => $category->id,
            'quantity' => 3,
        ]);

        $sale = Sales::create([
            'user_id' => $cashier->id,
            'patient_id' => $patient->id,
            'transaction_id' => 'TXN-REFUND-CONTROL',
            'total_amount' => 100,
            'amount_paid' => 100,
            'payment_status' => 'paid',
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'prescribed_quantity' => 2,
            'dispensed_quantity' => 2,
            'selling_price' => 50,
            'subtotal' => 100,
        ]);
        $payment = PaymentTransaction::create([
            'sale_id' => $sale->id,
            'amount' => 100,
            'payment_method' => 'cash',
            'collected_by' => $cashier->id,
        ]);
        $refund = RefundLog::create([
            'sale_id' => $sale->id,
            'request_type' => RefundLog::TYPE_REFUND,
            'reason_code' => 'customer_return',
            'status' => RefundLog::STATUS_APPROVED,
            'initiated_by' => $cashier->id,
            'approved_by' => $manager->id,
            'reason' => 'Customer returned the complete purchase.',
            'initiated_at' => now(),
            'approved_at' => now(),
        ]);

        $this->actingAs($manager);

        Livewire::test(RefundApprovalsComponent::class)
            ->call('process', $refund->id)
            ->assertDispatched('refund-receipt-ready');

        $refund = $refund->fresh();
        $this->assertSame(RefundLog::STATUS_PROCESSED, $refund->status);
        $this->assertNotNull($refund->refund_number);
        $this->assertSame('100.00', $refund->refunded_amount);
        $this->assertSame($payment->id, $refund->original_payment_references[0]['payment_transaction_id']);
        $this->assertSame(2, $refund->stock_restoration[0]['quantity_restored']);
        $this->assertSame(5, $product->fresh()->quantity);
        $this->assertTrue($sale->fresh()->is_refunded);
        $this->assertDatabaseHas('audit_trails', [
            'event' => 'refund.processed',
            'auditable_id' => $refund->id,
        ]);
        $this->get(route('refunds.receipt', $refund))->assertOk();
    }

    public function test_processed_refund_record_is_immutable(): void
    {
        $refund = new RefundLog(['status' => RefundLog::STATUS_PROCESSED]);
        $refund->exists = true;
        $refund->setRawAttributes(['id' => 999999, 'status' => RefundLog::STATUS_PROCESSED], true);
        $refund->reason = 'Changed';

        $this->expectException(\LogicException::class);

        $refund->save();
    }
}
