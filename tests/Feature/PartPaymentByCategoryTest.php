<?php

namespace Tests\Feature;

use App\Livewire\Admin\CategoryComponent;
use App\Livewire\POSComponent;
use App\Models\{Category, Patient, Product, Sales, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 2026-11-24 (Eyes of Hope): a patient paid for her drops but not her eye pressure test. The owner now
 * sets, per category, whether items may be part-paid and the least percent to pay now.
 */
class PartPaymentByCategoryTest extends TestCase
{
    use DatabaseTransactions;

    private User $cashier;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('Cashier');
        $this->actingAs($this->cashier);
        $this->patient = Patient::factory()->create(['user_id' => $this->cashier->id]);
    }

    private function item(string $category, string $type, float $price, bool $partPayment, int $minPercent = 0): Product
    {
        $cat = Category::factory()->create(['user_id' => $this->cashier->id, 'name' => $category.' '.uniqid(), 'type' => $type,
            'part_payment' => $partPayment, 'part_payment_min_percent' => $minPercent]);

        return Product::factory()->create(['user_id' => $this->cashier->id, 'category_id' => $cat->id, 'quantity' => 10, 'cost_price' => 10, 'selling_price' => $price]);
    }

    private function till(Product ...$products)
    {
        $till = Livewire::test(POSComponent::class)->call('selectPatient', $this->patient->id);
        foreach ($products as $product) $till->call('addToCart', $product->id);

        return $till;
    }

    private function pay($till, float $amount)
    {
        return $till->set('isPartPayment', true)->set('newPaymentMethod', 'cash')->set('newPaymentAmount', $amount)->call('addPayment')->call('checkout');
    }

    public function test_drops_paid_in_full_and_the_test_may_wait(): void
    {
        $drops = $this->item('Accessories', 'consumable', 120, false);
        $test = $this->item('Services', 'service', 30, true);

        $till = $this->till($drops, $test)->assertSet('partPaymentAllowed', true)->assertSet('minimumDueNow', 120.0)
            ->assertSee('Pay at least');
        $this->pay($till, 100);
        $this->assertSame(0, Sales::where('patient_id', $this->patient->id)->count(), 'Less than the drops is refused.');

        $this->pay($this->till($drops, $test), 120)->assertHasNoErrors();
        $sale = Sales::where('patient_id', $this->patient->id)->sole();
        $this->assertSame('partial', $sale->payment_status);
        $this->assertEquals(120, $sale->amount_paid);
        $this->assertEquals(150, $sale->total_amount);
    }

    public function test_a_minimum_deposit_is_kept_and_categories_that_are_off_offer_no_part_payment(): void
    {
        $frame = $this->item('Frames', 'frame', 200, true, 50);
        $this->till($frame)->assertSet('partPaymentAllowed', true)->assertSet('minimumDueNow', 100.0);
        $this->pay($this->till($frame), 80);
        $this->assertSame(0, Sales::where('patient_id', $this->patient->id)->count());

        $drops = $this->item('Accessories', 'consumable', 120, false);
        $this->till($drops)->assertSet('partPaymentAllowed', false)->assertDontSee('Part Payment / Hold Order');
    }

    public function test_frames_and_lenses_categories_start_with_part_payment_on(): void
    {
        $frames = Category::create(['user_id' => $this->cashier->id, 'name' => 'Frames '.uniqid(), 'type' => 'frame']);
        $drugs = Category::create(['user_id' => $this->cashier->id, 'name' => 'Drugs '.uniqid(), 'type' => 'drug']);

        $this->assertTrue($frames->fresh()->part_payment);
        $this->assertFalse($drugs->fresh()->part_payment);
    }

    public function test_only_the_owner_sets_part_payment_on_a_category(): void
    {
        $services = Category::create(['user_id' => $this->cashier->id, 'name' => 'Services '.uniqid(), 'type' => 'service']);

        // A cashier who may edit categories cannot change the rule.
        Livewire::test(CategoryComponent::class)->call('editCategoryModal', $services->id)
            ->set('state.part_payment', true)->set('state.part_payment_min_percent', 20)->call('updateCategory');
        $this->assertFalse($services->fresh()->part_payment);

        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $owner = User::factory()->create();
        $owner->assignRole('Super Admin');
        $this->actingAs($owner);
        Livewire::test(CategoryComponent::class)->call('editCategoryModal', $services->id)
            ->set('state.part_payment', true)->set('state.part_payment_min_percent', 20)->call('updateCategory')->assertHasNoErrors()
            ->assertSee('On · 20% now');

        $this->assertTrue($services->fresh()->part_payment);
        $this->assertSame(20, $services->fresh()->part_payment_min_percent);
        $this->assertDatabaseHas('audit_trails', ['event' => 'category.part_payment']);
    }
}
