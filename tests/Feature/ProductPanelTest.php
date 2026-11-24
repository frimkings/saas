<?php

namespace Tests\Feature;

use App\Livewire\Admin\ProductsComponent;
use App\Models\{Category, Product, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Clicking a product opens its edit form in a side panel (as Partner Clinics do); Add opens one too. */
class ProductPanelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_clicking_a_product_opens_its_edit_panel(): void
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $owner = User::factory()->create();
        $owner->assignRole('Super Admin');
        $this->actingAs($owner);
        $category = Category::create(['user_id' => $owner->id, 'name' => 'Drugs '.uniqid(), 'type' => 'drug']);
        $drops = Product::factory()->create(['user_id' => $owner->id, 'category_id' => $category->id, 'name' => 'Latmo Panel Drops', 'cost_price' => 50, 'selling_price' => 120]);

        Livewire::test(ProductsComponent::class)
            ->call('editProduct', $drops->id)->assertSet('showForm', true)->assertSet('viewProductId', $drops->id)
            ->assertSeeHtml('id="product-form-title"')->assertSee('Edit Latmo Panel Drops')->assertSee('Part payment: off')->assertSee('Save changes')
            ->call('cancelForm')->assertSet('showForm', false)->assertSet('viewProductId', null)->assertDontSeeHtml('id="product-form-title"')
            ->call('showAddForm')->assertSeeHtml('id="product-form-title"')->assertSee('Add a product');
    }
}
