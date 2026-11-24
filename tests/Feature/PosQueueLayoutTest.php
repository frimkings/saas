<?php

namespace Tests\Feature;

use App\Livewire\POSComponent;
use App\Models\{Cart, Category, Consultations, CashierPatientClearance, Patient, Product, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The till as a cashier's desk (2026-11-24): patients waiting to pay, their bill, the payment. */
class PosQueueLayoutTest extends TestCase
{
    use DatabaseTransactions;

    public function test_doctors_carts_wait_in_the_queue_and_load_with_desk_items_tagged_added(): void
    {
        Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $cashier = User::factory()->create(['name' => 'Desk Cashier']);
        $cashier->assignRole('Cashier');
        $doctor = User::factory()->create(['name' => 'Dr Queue']);
        $this->actingAs($cashier);
        $patient = Patient::factory()->create(['user_id' => $cashier->id, 'name' => 'Queue Patient']);
        $clearance = CashierPatientClearance::create(['user_id' => $cashier->id, 'patient_id' => $patient->id, 'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = Consultations::create(['user_id' => $doctor->id, 'patient_id' => $patient->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Itchy eyes']);
        $drugs = Category::create(['user_id' => $cashier->id, 'name' => 'Drugs '.uniqid(), 'type' => 'drug']);
        $services = Category::create(['user_id' => $cashier->id, 'name' => 'Services '.uniqid(), 'type' => 'service']);
        $drops = Product::factory()->create(['user_id' => $cashier->id, 'category_id' => $drugs->id, 'name' => 'Queue Drops', 'quantity' => 10, 'selling_price' => 35]);
        $test = Product::factory()->create(['user_id' => $cashier->id, 'category_id' => $services->id, 'name' => 'Queue Pressure Test', 'quantity' => 10, 'selling_price' => 30]);
        Cart::create(['patient_id' => $patient->id, 'dispensed_by' => $doctor->id, 'consultation_id' => $consultation->id, 'product_id' => $drops->id,
            'quantity' => 1, 'price' => 35, 'total' => 35, 'frequency' => 'Three Times Daily', 'eye' => 'OU', 'status' => 'pending', 'purchased' => false]);

        $till = Livewire::test(POSComponent::class)
            ->assertSeeHtml('data-tour="pos-queue"')->assertSee('Queue Patient')->assertSee('From Dr Queue')
            ->call('loadCartFromList', $patient->id, $consultation->id)
            ->assertSee('Three Times Daily · Both eyes')->assertDontSee('Added</span>', false)
            ->call('addToCart', $test->id)
            ->assertSee('Queue Pressure Test')->assertSeeHtml('pos-added-badge')
            ->assertSee('Still needed');
        $this->assertEquals(65, $till->get('amountDue'));
    }

    public function test_walk_in_sale_starts_a_direct_purchase(): void
    {
        Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');
        $this->actingAs($cashier);

        Livewire::test(POSComponent::class)->assertSee('Walk-in sale')
            ->call('startWalkIn')->assertSet('purchaseMode', 'direct')->assertDispatched('pos-open-picker');
    }
}
