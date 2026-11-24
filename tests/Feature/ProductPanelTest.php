<?php

namespace Tests\Feature;

use App\Livewire\Admin\ProductsComponent;
use App\Models\{Category, Product, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Products open in a side panel (as Partner Clinics do); add and edit are side panels too. */
class ProductPanelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_clicking_a_product_opens_its_panel_and_edit_opens_the_form_panel(): void
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $owner = User::factory()->create();
        $owner->assignRole('Super Admin');
        $this->actingAs($owner);
        $category = Category::create(['user_id' => $owner->id, 'name' => 'Drugs '.uniqid(), 'type' => 'drug']);
        $drops = Product::factory()->create(['user_id' => $owner->id, 'category_id' => $category->id, 'name' => 'Latmo Panel Drops', 'cost_price' => 50, 'selling_price' => 120]);

        Livewire::test(ProductsComponent::class)
            ->call('openProduct', $drops->id)->assertSet('viewProductId', $drops->id)
            ->assertSeeHtml('id="product-title"')->assertSee('Selling price')->assertSee('Paid in full at the till')
            ->call('editProduct', $drops->id)->assertSet('showForm', true)->assertSeeHtml('id="product-form-title"')->assertDontSeeHtml('id="product-title"')
            ->call('cancelForm')->assertSet('showForm', false)->assertSeeHtml('id="product-title"')
            ->call('closeProduct')->assertSet('viewProductId', null)->assertDontSeeHtml('id="product-title"')
            ->call('showAddForm')->assertSeeHtml('id="product-form-title"')->assertSee('Add a product');
    }
}
