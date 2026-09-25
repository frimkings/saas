<?php

namespace Tests\Feature;

use App\Livewire\Secretary\SpectaclesComponent;
use App\Models\CashierPatientClearance;
use App\Models\Category;
use App\Models\Consultations;
use App\Models\LensOrder;
use App\Models\Patient;
use App\Models\Product;
use App\Models\Refractions;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Models\User;
use App\Services\Inventory\BranchInventoryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class SpectacleOrderStockTest extends TestCase
{
    use RefreshDatabase, GivesClinicsAccess;

    private User $user;
    private Product $frame;
    private Product $lens;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.enabled' => true]);
        $this->user = User::factory()->create();
        $clinic = $this->activeClinic(['name' => 'Spectacle Stock', 'slug' => 'spectacle-stock', 'status' => 'active']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        app(TenantContext::class)->set($this->user, $clinic, $branch, [$branch->id]);
        $this->actingAs($this->user);

        $frames = Category::create(['user_id' => $this->user->id, 'name' => 'Frames']);
        $lenses = Category::create(['user_id' => $this->user->id, 'name' => 'Lenses']);
        $this->frame = Product::create([
            'user_id' => $this->user->id, 'name' => 'Diamond Frame A', 'category_id' => $frames->id,
            'quantity' => 5, 'cost_price' => 200, 'selling_price' => 800,
        ]);
        $this->lens = Product::create([
            'user_id' => $this->user->id, 'name' => 'Blue Block Lenses', 'category_id' => $lenses->id,
            'quantity' => 5, 'cost_price' => 100, 'selling_price' => 500,
        ]);
    }

    /** A dispensing refraction whose POS sale sold the given products (stock already deducted, as POS checkout does). */
    private function refractionSoldAtPos(array $soldProducts): Refractions
    {
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $this->user->id, 'name' => 'Zedekiah Azinogo', 'contact' => '0240000001', 'gender' => 'Male',
        ]);
        $clearance = CashierPatientClearance::create([
            'user_id' => $this->user->id, 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid',
        ]);
        $consultation = Consultations::create([
            'user_id' => $this->user->id, 'patient_id' => $patient->id,
            'clearance_id' => $clearance->id, 'chiefComplaint' => 'Blurred vision',
        ]);
        $refraction = Refractions::create([
            'user_id' => $this->user->id, 'consultation_id' => $consultation->id,
            'refractionOD' => '-2.00', 'refractionOS' => '-1.75',
            'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6',
            'dispensing_required' => true, 'dispensing_authorized_at' => now(),
            'dispensing_authorized_by' => $this->user->id,
        ]);

        $total = collect($soldProducts)->sum(fn ($price) => $price);
        $sale = Sales::create([
            'user_id' => $this->user->id, 'patient_id' => $patient->id, 'consultation_id' => $consultation->id,
            'transaction_id' => 'POS-'.uniqid(), 'total_amount' => $total, 'amount_paid' => $total, 'payment_status' => 'paid',
        ]);
        foreach ($soldProducts as $productId => $price) {
            SaleItem::create([
                'sale_id' => $sale->id, 'product_id' => $productId, 'prescribed_quantity' => 0,
                'dispensed_quantity' => 1, 'selling_price' => $price, 'subtotal' => $price,
            ]);
            app(BranchInventoryService::class)->decrease($productId, 1);
        }

        return $refraction;
    }

    private function stock(Product $product): int
    {
        return app(BranchInventoryService::class)->quantity($product);
    }

    public function test_order_reuses_frame_and_lens_sold_at_pos_without_deducting_stock_again(): void
    {
        $refraction = $this->refractionSoldAtPos([$this->frame->id => 750, $this->lens->id => 550]);
        $this->assertSame(4, $this->stock($this->frame));
        $this->assertSame(4, $this->stock($this->lens));

        $component = Livewire::test(SpectaclesComponent::class)
            ->call('openOrderModal', $refraction->id)
            ->assertSet('selectedFrameId', $this->frame->id)
            ->assertSet('selectedLensId', $this->lens->id)
            ->assertSee('Sold at POS')
            ->set('selectedFrameId', null)
            ->set('selectedLensId', null)
            ->call('createOrder')
            ->assertHasNoErrors();

        $order = LensOrder::where('refraction_id', $refraction->id)->firstOrFail();
        $this->assertSame($this->frame->id, (int) $order->frame_product_id);
        $this->assertSame($this->lens->id, (int) $order->lens_product_id);
        $this->assertEquals(750, $order->frame_price);
        $this->assertEquals(550, $order->lens_price);
        $this->assertTrue($order->frame_stock_from_sale);
        $this->assertTrue($order->lens_stock_from_sale);
        $this->assertNull($order->stock_reserved_at);
        $this->assertSame(4, $this->stock($this->frame));
        $this->assertSame(4, $this->stock($this->lens));

        $component->call('openCancelConfirm', $order->id)
            ->set('cancelReason', 'Patient changed mind')
            ->call('confirmCancelOrder')
            ->assertHasNoErrors();

        $this->assertSame('Cancelled', $order->fresh()->status);
        $this->assertSame(4, $this->stock($this->frame));
        $this->assertSame(4, $this->stock($this->lens));
    }

    public function test_only_the_item_missing_from_the_pos_sale_is_selected_and_deducted(): void
    {
        $refraction = $this->refractionSoldAtPos([$this->frame->id => 800]);

        $component = Livewire::test(SpectaclesComponent::class)
            ->call('openOrderModal', $refraction->id)
            ->assertSet('selectedFrameId', $this->frame->id)
            ->assertSet('selectedLensId', null)
            ->call('createOrder')
            ->assertHasErrors(['selectedLensId' => 'required'])
            ->set('selectedLensId', $this->lens->id)
            ->call('createOrder')
            ->assertHasNoErrors();

        $order = LensOrder::where('refraction_id', $refraction->id)->firstOrFail();
        $this->assertTrue($order->frame_stock_from_sale);
        $this->assertFalse($order->lens_stock_from_sale);
        $this->assertNotNull($order->stock_reserved_at);
        $this->assertSame(4, $this->stock($this->frame));
        $this->assertSame(4, $this->stock($this->lens));

        $component->call('openCancelConfirm', $order->id)
            ->set('cancelReason', 'Lab unavailable')
            ->call('confirmCancelOrder');

        $this->assertSame(4, $this->stock($this->frame));
        $this->assertSame(5, $this->stock($this->lens));
    }

    public function test_lens_only_pos_sale_defaults_to_patients_own_frame(): void
    {
        $refraction = $this->refractionSoldAtPos([$this->lens->id => 550]);

        $component = Livewire::test(SpectaclesComponent::class)
            ->call('openOrderModal', $refraction->id)
            ->assertSet('ownFrame', true)
            ->assertSee('Patient brings own frame')
            ->set('ownFrameDescription', 'Black metal Ray-Ban')
            ->call('createOrder')
            ->assertHasNoErrors();

        $order = LensOrder::where('refraction_id', $refraction->id)->firstOrFail();
        $this->assertTrue($order->own_frame);
        $this->assertNull($order->frame_product_id);
        $this->assertSame("Patient's own frame - Black metal Ray-Ban", $order->frame_model_number);
        $this->assertEquals(0, $order->frame_price);
        $this->assertEquals(550, $order->lens_price);
        $this->assertNull($order->stock_reserved_at);
        $this->assertSame(5, $this->stock($this->frame));
        $this->assertSame(4, $this->stock($this->lens));

        $component->call('openPrintPreview', $order->id)
            ->assertSee("PATIENT'S OWN FRAME - BLACK METAL RAY-BAN")
            ->assertSee("GLAZED AT OWNER'S RISK", false);
        $this->assertStringContainsString("GLAZED AT OWNER'S RISK", view('pdf.job-card-thermal', [
            'order' => $order->fresh(['refraction.consultation.patient', 'refraction.consultation.sale.items.product.category', 'frameProduct', 'user']),
            'appSettings' => \App\Models\Setting::getSettings(),
        ])->render());
    }

    public function test_staff_can_sell_a_frame_when_pos_sale_has_only_a_lens(): void
    {
        $refraction = $this->refractionSoldAtPos([$this->lens->id => 550]);

        Livewire::test(SpectaclesComponent::class)
            ->call('openOrderModal', $refraction->id)
            ->set('ownFrame', false)
            ->call('createOrder')
            ->assertHasErrors(['selectedFrameId' => 'required'])
            ->set('selectedFrameId', $this->frame->id)
            ->call('createOrder')
            ->assertHasNoErrors();

        $order = LensOrder::where('refraction_id', $refraction->id)->firstOrFail();
        $this->assertFalse($order->own_frame);
        $this->assertSame($this->frame->id, (int) $order->frame_product_id);
        $this->assertEquals(800, $order->frame_price);
        $this->assertSame(4, $this->stock($this->frame));
        $this->assertSame(4, $this->stock($this->lens));
    }
}
