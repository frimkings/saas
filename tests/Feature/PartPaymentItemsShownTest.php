<?php

namespace Tests\Feature;

use App\Livewire\Cashier\SalesRecordsComponent;
use App\Models\{Patient, Product, SaleItem, Sales, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A part-paid sale keeps its items on hold (dispensed 0, stored subtotal 0) until the balance
 * is paid; Sales Records and the receipt still show what was sold and its price.
 */
class PartPaymentItemsShownTest extends TestCase
{
    use DatabaseTransactions;

    public function test_on_hold_items_show_their_quantity_and_price(): void
    {
        Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');
        $this->actingAs($cashier);

        $patient = Patient::factory()->create(['user_id' => $cashier->id, 'name' => 'Ama Agyeman']);
        $frame = Product::factory()->create(['user_id' => $cashier->id, 'name' => 'Frame RL 3139-C4', 'quantity' => 5, 'selling_price' => 382.50]);
        $sale = Sales::create(['user_id' => $cashier->id, 'patient_id' => $patient->id, 'transaction_id' => 'TXN-HOLD1',
            'total_amount' => 765, 'amount_paid' => 300, 'payment_status' => 'partial']);
        // Exactly as POSComponent saves a part payment.
        $item = SaleItem::create(['sale_id' => $sale->id, 'product_id' => $frame->id, 'prescribed_quantity' => 2, 'dispensed_quantity' => 0,
            'selling_price' => 382.50, 'subtotal' => 765, 'notes' => 'On Hold - Part Payment']);

        $item->refresh();
        $this->assertSame(0.0, (float) $item->subtotal, 'The stored subtotal is 0 while on hold.');
        $this->assertTrue($item->is_on_hold);
        $this->assertSame(2, $item->shown_quantity);
        $this->assertSame(765.0, $item->shown_subtotal);

        $page = Livewire::test(SalesRecordsComponent::class);
        $page->assertSee('Frame RL 3139-C4 (on hold until paid)')
            ->assertSeeHtml('<span class="quantity">2</span>')
            ->assertSeeHtml('<span class="subtotal">765.00</span>');

        // A normal (fully paid) item is unchanged.
        $paid = SaleItem::create(['sale_id' => $sale->id, 'product_id' => $frame->id, 'prescribed_quantity' => 0, 'dispensed_quantity' => 1, 'selling_price' => 382.50]);
        $this->assertFalse($paid->is_on_hold);
        $this->assertSame(1, $paid->shown_quantity);
        $this->assertSame(382.5, $paid->shown_subtotal);
    }
}
