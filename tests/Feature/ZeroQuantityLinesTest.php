<?php

namespace Tests\Feature;

use App\Livewire\Doctor\PatientRecordsComponent;
use App\Models\{Cart, CashierPatientClearance, Category, Consultations, Patient, Product, SaleItem};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** A doctor's item left at quantity 0 went through the till and printed as "0 … GH₵ 0.00" on the receipt. */
class ZeroQuantityLinesTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    public function test_a_line_with_nothing_sold_is_left_off_the_receipt(): void
    {
        $this->ownerOn('clinic-pro');
        $sale = $this->paidSale();
        $renewal = Product::factory()->create(['user_id' => auth()->id(), 'name' => 'DVLA RENEWAL', 'category_id' => $sale->items->first()->product->category_id]);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $renewal->id, 'prescribed_quantity' => 0, 'dispensed_quantity' => 0, 'selling_price' => 80, 'subtotal' => 0]);

        $this->assertTrue($sale->fresh('items')->items->last()->is_empty_line);
        $this->assertFalse($sale->fresh('items')->items->first()->is_empty_line);
        $this->get(route('cashier.receipt.show', $sale->id))->assertOk()
            ->assertSee($sale->items->first()->product->name)->assertDontSee('DVLA RENEWAL')
            ->assertSee('white-space: nowrap', false);
    }

    public function test_a_doctor_cannot_save_a_prescribed_item_at_quantity_zero(): void
    {
        $this->ownerOn('clinic-starter');
        $this->signIn($this->staff('Doctor'));
        $category = Category::firstOrCreate(['name' => 'Drugs'], ['type' => 'drug', 'user_id' => auth()->id()]);
        $drops = Product::create(['user_id' => auth()->id(), 'name' => 'Anadorz-T eye drops', 'category_id' => $category->id, 'batch_number' => 'DRG-1001',
            'manufacture_date' => now()->subYear()->toDateString(), 'expiry_date' => now()->addYears(2)->toDateString(),
            'quantity' => 10, 'cost_price' => 60, 'selling_price' => 130]);
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Ama Mensah', 'contact' => '0244556677', 'gender' => 'Female']);
        $clearance = CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => $patient->id, 'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = Consultations::create(['user_id' => auth()->id(), 'patient_id' => $patient->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Red eye']);

        // Typing 0 resets the box to 1; a 0 that reaches saving is saved as 1.
        $file = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')
            ->call('selectProduct', $drops->id)
            ->set('productsList.0.frequency', 'Twice Daily')->set('productsList.0.duration_value', 7)
            ->call('updateProductQuantity', 0, 0)->assertSet('productsList.0.quantity', 1)
            ->set('productsList.0.quantity', 0)
            ->call('savePrescription');

        $cart = Cart::where('consultation_id', $consultation->id)->sole();
        $this->assertSame([1, 130.0], [(int) $cart->quantity, (float) $cart->total]);
    }
}
