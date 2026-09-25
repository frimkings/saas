<?php

namespace Tests\Feature;

use App\Livewire\Admin\ProductsComponent;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->actingAs($this->admin);

        $this->category = Category::create([
            'user_id' => $this->admin->id,
            'name'    => 'Test Category ' . uniqid(),
            'type'    => 'drug',
        ]);
    }

    // ── Product model scopes ─────────────────────────────────────────────

    public function test_in_stock_scope_excludes_zero_quantity_products(): void
    {
        $in  = Product::factory()->create(['user_id' => $this->admin->id, 'category_id' => $this->category->id, 'quantity' => 10]);
        $out = Product::factory()->create(['user_id' => $this->admin->id, 'category_id' => $this->category->id, 'quantity' => 0]);

        $ids = Product::inStock()->pluck('id');

        $this->assertContains($in->id, $ids);
        $this->assertNotContains($out->id, $ids);
    }

    public function test_low_stock_scope_returns_products_between_1_and_10(): void
    {
        $low    = Product::factory()->create(['user_id' => $this->admin->id, 'category_id' => $this->category->id, 'quantity' => 5]);
        $normal = Product::factory()->create(['user_id' => $this->admin->id, 'category_id' => $this->category->id, 'quantity' => 50]);
        $zero   = Product::factory()->create(['user_id' => $this->admin->id, 'category_id' => $this->category->id, 'quantity' => 0]);

        $ids = Product::lowStock()->pluck('id');

        $this->assertContains($low->id, $ids);
        $this->assertNotContains($normal->id, $ids);
        $this->assertNotContains($zero->id, $ids);
    }

    public function test_out_of_stock_scope_returns_only_zero_quantity(): void
    {
        $in  = Product::factory()->create(['user_id' => $this->admin->id, 'category_id' => $this->category->id, 'quantity' => 1]);
        $out = Product::factory()->create(['user_id' => $this->admin->id, 'category_id' => $this->category->id, 'quantity' => 0]);

        $ids = Product::outOfStock()->pluck('id');

        $this->assertContains($out->id, $ids);
        $this->assertNotContains($in->id, $ids);
    }

    public function test_expired_scope_returns_products_with_past_expiry_date(): void
    {
        $expired = Product::factory()->create([
            'user_id'          => $this->admin->id,
            'category_id'      => $this->category->id,
            'manufacture_date' => now()->subYears(2)->format('Y-m-d'),
            'expiry_date'      => now()->subDay()->format('Y-m-d'),
        ]);
        $valid = Product::factory()->create([
            'user_id'     => $this->admin->id,
            'category_id' => $this->category->id,
            'expiry_date' => now()->addYear()->format('Y-m-d'),
        ]);

        $ids = Product::expired()->pluck('id');

        $this->assertContains($expired->id, $ids);
        $this->assertNotContains($valid->id, $ids);
    }

    // ── Product model methods ────────────────────────────────────────────

    public function test_is_expired_returns_true_for_past_expiry(): void
    {
        $product = Product::factory()->create([
            'user_id'          => $this->admin->id,
            'category_id'      => $this->category->id,
            'manufacture_date' => now()->subYears(2)->format('Y-m-d'),
            'expiry_date'      => now()->subDay()->format('Y-m-d'),
        ]);

        $this->assertTrue($product->isExpired());
    }

    public function test_is_expired_returns_false_for_future_expiry(): void
    {
        $product = Product::factory()->create([
            'user_id'     => $this->admin->id,
            'category_id' => $this->category->id,
            'expiry_date' => now()->addYear()->format('Y-m-d'),
        ]);

        $this->assertFalse($product->isExpired());
    }

    public function test_is_low_stock_detects_threshold_correctly(): void
    {
        $base = ['user_id' => $this->admin->id, 'category_id' => $this->category->id];

        $low    = Product::factory()->make(array_merge($base, ['quantity' => 5]));
        $normal = Product::factory()->make(array_merge($base, ['quantity' => 50]));

        $this->assertTrue($low->isLowStock());
        $this->assertFalse($normal->isLowStock());
    }

    public function test_profit_margin_calculated_correctly(): void
    {
        $product = new Product(['cost_price' => 100, 'selling_price' => 150]);

        $this->assertEquals(50.0, $product->getProfitMargin());
    }

    public function test_profit_margin_returns_zero_when_cost_price_is_zero(): void
    {
        $product = new Product(['cost_price' => 0, 'selling_price' => 50]);

        $this->assertEquals(0, $product->getProfitMargin());
    }

    // ── ProductsComponent CRUD ───────────────────────────────────────────

    public function test_admin_can_create_product(): void
    {
        Livewire::test(ProductsComponent::class)
            ->call('showAddForm')
            ->assertSet('showForm', true)
            ->set('state.name', 'Unique Test Eye Drop')
            ->set('state.batch_number', 'BATCH001A')
            ->set('state.category_id', $this->category->id)
            ->set('state.manufacture_date', now()->subYear()->format('Y-m-d'))
            ->set('state.expiry_date', now()->addYear()->format('Y-m-d'))
            ->set('state.quantity', 100)
            ->set('state.cost_price', '20.00')
            ->set('state.selling_price', '35.00')
            ->call('createProduct')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('products', [
            'name'         => 'Unique Test Eye Drop',
            'batch_number' => 'BATCH001A',
            'user_id'      => $this->admin->id,
        ]);
    }

    public function test_selling_price_must_be_at_least_cost_price(): void
    {
        Livewire::test(ProductsComponent::class)
            ->call('showAddForm')
            ->set('state.name', 'Bad Price Product')
            ->set('state.batch_number', 'BATCH002B')
            ->set('state.category_id', $this->category->id)
            ->set('state.manufacture_date', now()->subYear()->format('Y-m-d'))
            ->set('state.expiry_date', now()->addYear()->format('Y-m-d'))
            ->set('state.quantity', 10)
            ->set('state.cost_price', '50.00')
            ->set('state.selling_price', '30.00')
            ->call('createProduct')
            ->assertHasErrors(['selling_price']);
    }

    public function test_product_name_must_be_unique(): void
    {
        Product::factory()->create([
            'user_id'     => $this->admin->id,
            'category_id' => $this->category->id,
            'name'        => 'Duplicate Product',
        ]);

        Livewire::test(ProductsComponent::class)
            ->call('showAddForm')
            ->set('state.name', 'Duplicate Product')
            ->set('state.batch_number', 'BATCH003C')
            ->set('state.category_id', $this->category->id)
            ->set('state.manufacture_date', now()->subYear()->format('Y-m-d'))
            ->set('state.expiry_date', now()->addYear()->format('Y-m-d'))
            ->set('state.quantity', 10)
            ->set('state.cost_price', '20.00')
            ->set('state.selling_price', '30.00')
            ->call('createProduct')
            ->assertHasErrors(['name']);
    }

    public function test_expiry_date_must_be_after_manufacture_date(): void
    {
        Livewire::test(ProductsComponent::class)
            ->call('showAddForm')
            ->set('state.name', 'Wrong Date Product')
            ->set('state.batch_number', 'BATCH004D')
            ->set('state.category_id', $this->category->id)
            ->set('state.manufacture_date', now()->addYear()->format('Y-m-d'))
            ->set('state.expiry_date', now()->subYear()->format('Y-m-d'))
            ->set('state.quantity', 10)
            ->set('state.cost_price', '20.00')
            ->set('state.selling_price', '30.00')
            ->call('createProduct')
            ->assertHasErrors(['expiry_date']);
    }

    public function test_admin_can_edit_and_update_product(): void
    {
        $product = Product::factory()->create([
            'user_id'      => $this->admin->id,
            'category_id'  => $this->category->id,
            'name'         => 'Original Product Name',
            'cost_price'   => 20.00,
            'selling_price' => 35.00,
        ]);

        Livewire::test(ProductsComponent::class)
            ->call('editProduct', $product->id)
            ->assertSet('showForm', true)
            ->assertSet('state.name', 'Original Product Name')
            ->set('state.name', 'Updated Product Name')
            ->set('state.selling_price', '40.00')
            ->call('updateProduct')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('products', [
            'id'   => $product->id,
            'name' => 'Updated Product Name',
        ]);
    }

    public function test_admin_can_delete_product(): void
    {
        $product = Product::factory()->create([
            'user_id'     => $this->admin->id,
            'category_id' => $this->category->id,
        ]);

        Livewire::test(ProductsComponent::class)
            ->call('confirmProductDelete', $product->id);

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }
}
