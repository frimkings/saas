<?php

namespace Tests\Feature;

use App\Livewire\Admin\DiscountLimitsComponent;
use App\Livewire\POSComponent;
use App\Models\{Patient, Product, Sales, Setting, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 2026-11-24 (Eyes of Hope): a patient paid for her drops but not her eye pressure test, and the till
 * could only hold a balance on frames and lenses. The owner can now allow part payment on everything.
 */
class PartPaymentAllItemsTest extends TestCase
{
    use DatabaseTransactions;

    private function cashierWithPatient(): array
    {
        Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');
        $this->actingAs($cashier);
        $patient = Patient::factory()->create(['user_id' => $cashier->id]);
        $drops = Product::factory()->create(['user_id' => $cashier->id, 'name' => 'Latmo', 'quantity' => 5, 'cost_price' => 50, 'selling_price' => 150]);

        return [$patient, $drops];
    }

    public function test_drugs_and_services_must_be_paid_in_full_unless_the_owner_allows_part_payment(): void
    {
        [$patient, $drops] = $this->cashierWithPatient();
        Setting::getSettings()->update(['part_payment_all_items' => false]);

        $till = Livewire::test(POSComponent::class)->call('selectPatient', $patient->id)->call('addToCart', $drops->id)
            ->assertSet('partPaymentAllowed', false)->assertDontSee('Part Payment / Hold Order');
        $till->set('isPartPayment', true)->set('newPaymentMethod', 'cash')->set('newPaymentAmount', 120)->call('addPayment')
            ->call('checkout')->assertSet('isPartPayment', false);
        $this->assertSame(0, Sales::where('patient_id', $patient->id)->count(), 'No sale is made for less than the bill.');

        Setting::getSettings()->update(['part_payment_all_items' => true]);
        $till = Livewire::test(POSComponent::class)->call('selectPatient', $patient->id)->call('addToCart', $drops->id)
            ->assertSet('partPaymentAllowed', true)->assertSee('Part Payment / Hold Order')
            ->set('isPartPayment', true)->set('newPaymentMethod', 'cash')->set('newPaymentAmount', 120)->call('addPayment');
        $till->call('checkout')->assertHasNoErrors();

        $sale = Sales::where('patient_id', $patient->id)->sole();
        $this->assertSame('partial', $sale->payment_status);
        $this->assertEquals(120, $sale->amount_paid);
        $this->assertEquals(30, $sale->total_amount - $sale->amount_paid);
    }

    public function test_the_owner_switches_it_on_in_settings(): void
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $owner = User::factory()->create();
        $owner->assignRole('Super Admin');
        $this->actingAs($owner);
        Setting::getSettings()->update(['part_payment_all_items' => false]);

        Livewire::test(DiscountLimitsComponent::class)->assertSee('Allow part payment on drugs and services')
            ->set('partPaymentAllItems', true)->call('savePartPayment')->assertHasNoErrors();

        $this->assertTrue((bool) Setting::query()->value('part_payment_all_items'));
        $this->assertDatabaseHas('audit_trails', ['event' => 'settings.part_payment']);
    }
}
