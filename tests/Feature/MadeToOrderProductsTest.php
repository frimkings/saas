<?php

namespace Tests\Feature;

use App\Livewire\Admin\ProductsComponent;
use App\Livewire\Admin\StockMovementComponent;
use App\Livewire\Secretary\SpectaclesComponent;
use App\Models\{AuditTrail, BranchInventoryItem, CashierPatientClearance, Category, Consultations, LensOrder, Patient, Product, Refractions, SaleItem, Sales, User};
use App\Services\Inventory\BranchInventoryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** Lenses ordered per job from a lab: sold and billed at their prices, never counted in stock. */
class MadeToOrderProductsTest extends TestCase
{
    use RefreshDatabase, GivesClinicsAccess;

    private User $user;
    private Category $lenses;
    private Product $frame;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->user = User::factory()->create();
        $clinic = $this->activeClinic(['name' => 'Made To Order', 'slug' => 'made-to-order', 'status' => 'active']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        app(TenantContext::class)->set($this->user, $clinic, $branch, [$branch->id]);
        $this->user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $this->actingAs($this->user);

        $frames = Category::create(['user_id' => $this->user->id, 'name' => 'Frames']);
        $this->lenses = Category::create(['user_id' => $this->user->id, 'name' => 'Lenses']);
        $this->frame = Product::create(['user_id' => $this->user->id, 'name' => 'Diamond Frame A', 'category_id' => $frames->id,
            'batch_number' => 'FRAMEA1', 'manufacture_date' => now()->subYear()->toDateString(), 'expiry_date' => now()->addYears(5)->toDateString(),
            'quantity' => 5, 'cost_price' => 200, 'selling_price' => 800]);
    }

    private function lens(array $extra = []): Product
    {
        return Product::create(array_merge(['user_id' => $this->user->id, 'name' => 'SV Photo AR', 'category_id' => $this->lenses->id,
            'batch_number' => 'SVPHOTO1', 'manufacture_date' => now()->subYear()->toDateString(), 'expiry_date' => now()->addYears(3)->toDateString(),
            'quantity' => 0, 'made_to_order' => true, 'cost_price' => 90, 'selling_price' => 450], $extra));
    }

    private function dispensingRefraction(): Refractions
    {
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $this->user->id, 'name' => 'Ama Lab', 'contact' => '0240000009', 'gender' => 'Female']);
        $clearance = CashierPatientClearance::create(['user_id' => $this->user->id, 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = Consultations::create(['user_id' => $this->user->id, 'patient_id' => $patient->id,
            'clearance_id' => $clearance->id, 'chiefComplaint' => 'Blurred vision']);

        return Refractions::create(['user_id' => $this->user->id, 'consultation_id' => $consultation->id,
            'refractionOD' => '-2.00', 'refractionOS' => '-1.75', 'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6',
            'dispensing_required' => true, 'dispensing_authorized_at' => now(), 'dispensing_authorized_by' => $this->user->id]);
    }

    public function test_made_to_order_lens_is_never_taken_from_or_put_back_into_stock(): void
    {
        $lens = $this->lens();
        $inventory = app(BranchInventoryService::class);

        $this->assertNull($inventory->decrease($lens, 2));
        $this->assertNull($inventory->increase($lens, 1));
        $this->assertSame(0, (int) $lens->fresh()->quantity);
        $this->assertFalse(BranchInventoryItem::where('product_id', $lens->id)->exists());

        // Still sold and billed: available to pick, never low or out of stock.
        $this->assertTrue($lens->canSupply(3));
        $this->assertContains($lens->id, Product::inStock()->pluck('id'));
        $this->assertNotContains($lens->id, Product::lowStock()->pluck('id'));
        $this->assertNotContains($lens->id, Product::outOfStock()->pluck('id'));
    }

    public function test_spectacle_order_uses_a_made_to_order_lens_with_no_stock(): void
    {
        $lens = $this->lens();
        $refraction = $this->dispensingRefraction();
        // The frame was paid for at POS (which takes its stock); the lens is picked on the order.
        $sale = Sales::create(['user_id' => $this->user->id, 'patient_id' => $refraction->consultation->patient_id, 'consultation_id' => $refraction->consultation_id,
            'transaction_id' => 'POS-'.uniqid(), 'total_amount' => 800, 'amount_paid' => 800, 'payment_status' => 'paid']);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $this->frame->id, 'prescribed_quantity' => 0,
            'dispensed_quantity' => 1, 'selling_price' => 800, 'subtotal' => 800]);
        app(BranchInventoryService::class)->decrease($this->frame, 1);

        $component = Livewire::test(SpectaclesComponent::class)
            ->call('openOrderModal', $refraction->id)
            ->assertSee('SV Photo AR (Made to order)')
            ->set('selectedLensId', $lens->id)->call('createOrder')->assertHasNoErrors();

        $order = LensOrder::where('refraction_id', $refraction->id)->firstOrFail();
        $this->assertSame($lens->id, (int) $order->lens_product_id);
        $this->assertEquals(450, $order->lens_price);
        $this->assertSame(0, (int) $lens->fresh()->quantity);
        $this->assertSame(4, app(BranchInventoryService::class)->quantity($this->frame));

        // Cancelling puts nothing back for the lens either.
        $component->call('openCancelConfirm', $order->id)->set('cancelReason', 'Lab unavailable')->call('confirmCancelOrder');
        $this->assertSame('Cancelled', $order->fresh()->status);
        $this->assertSame(0, (int) $lens->fresh()->quantity);
    }

    public function test_ticking_made_to_order_clears_made_up_stock_and_keeps_the_old_number(): void
    {
        $lens = $this->lens(['made_to_order' => false, 'quantity' => 37]);
        app(BranchInventoryService::class)->decrease($lens, 2);
        $this->assertSame(35, (int) $lens->fresh()->quantity);

        Livewire::test(ProductsComponent::class)
            ->call('editProduct', $lens->id)->assertSet('state.made_to_order', false)
            ->set('state.made_to_order', true)->assertSee('Saving clears the current quantity of 35')
            ->call('updateProduct')->assertHasNoErrors();

        $lens->refresh();
        $this->assertTrue($lens->made_to_order);
        $this->assertSame(0, (int) $lens->quantity);
        $this->assertSame(0, (int) BranchInventoryItem::where('product_id', $lens->id)->sum('quantity'));

        $audit = AuditTrail::where('event', 'product.made_to_order')->where('auditable_id', $lens->id)->firstOrFail();
        $this->assertSame(35, $audit->old_values['quantity']);

        // Saving again does not clear or record anything twice.
        Livewire::test(ProductsComponent::class)->call('editProduct', $lens->id)->call('updateProduct')->assertHasNoErrors();
        $this->assertSame(1, AuditTrail::where('event', 'product.made_to_order')->where('auditable_id', $lens->id)->count());
    }

    public function test_new_made_to_order_product_needs_no_quantity(): void
    {
        Livewire::test(ProductsComponent::class)
            ->call('showAddForm')
            ->set('state.name', 'SV Clear 1.56')->set('state.batch_number', 'SVCLEAR1')->set('state.category_id', $this->lenses->id)
            ->set('state.manufacture_date', now()->subYear()->format('Y-m-d'))->set('state.expiry_date', now()->addYears(3)->format('Y-m-d'))
            ->set('state.made_to_order', true)->set('state.cost_price', '60.00')->set('state.selling_price', '300.00')
            ->call('createProduct')->assertHasNoErrors();

        $product = Product::where('name', 'SV Clear 1.56')->firstOrFail();
        $this->assertTrue($product->made_to_order);
        $this->assertSame(0, (int) $product->quantity);
    }

    public function test_stock_cannot_be_received_into_a_made_to_order_product(): void
    {
        $lens = $this->lens();

        Livewire::test(StockMovementComponent::class)
            ->set('productId', $lens->id)->set('quantity', 10)->call('receiveStock')
            ->assertHasErrors('productId');

        $this->assertSame(0, (int) $lens->fresh()->quantity);
    }
}
