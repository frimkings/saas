<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchInventoryItem;
use App\Models\Category;
use App\Models\Clinic;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Livewire\Admin\PurchaseOrderComponent;
use App\Services\Inventory\BranchInventoryService;
use App\Services\Inventory\StockTransferService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class InventoryTenancyTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    public function test_inventory_tables_are_tenant_ready(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'clinic_id'));
        foreach (['stocks', 'stock_movements', 'purchase_orders', 'purchase_order_items'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, ['clinic_id', 'branch_id']));
            $this->assertSame(0, DB::table($table)->whereNull('clinic_id')->count());
        }
        foreach (['branch_inventory_items', 'inventory_lots', 'stock_transfers', 'stock_transfer_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }

    public function test_stock_is_isolated_and_can_transfer_only_between_clinic_branches(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $clinic = $this->activeClinic(['name' => 'Clinic', 'slug' => 'clinic']);
        $source = $this->branch($clinic, $user, 'SRC', true);
        $destination = $this->branch($clinic, $user, 'DST');
        $context = app(TenantContext::class);
        $context->set($user, $clinic, $source, [$source->id, $destination->id]);
        $category = Category::create(['user_id' => $user->id, 'name' => 'Frames', 'type' => 'frame']);
        $product = Product::create(['user_id' => $user->id, 'category_id' => $category->id, 'name' => 'Frame', 'quantity' => 0, 'cost_price' => 10, 'selling_price' => 20]);

        app(BranchInventoryService::class)->increase($product, 10);
        $transfer = app(StockTransferService::class)->create($destination->id, [['product_id' => $product->id, 'quantity' => 4]]);
        app(StockTransferService::class)->dispatch($transfer);
        $this->assertSame(6, app(BranchInventoryService::class)->quantity($product, $source->id));

        $context->set($user, $clinic, $destination, [$source->id, $destination->id]);
        app(StockTransferService::class)->receive($transfer);
        $this->assertSame(4, app(BranchInventoryService::class)->quantity($product, $destination->id));
        $this->assertSame(10, (int) $product->fresh()->quantity);
        $this->assertSame([$destination->id], BranchInventoryItem::query()->pluck('branch_id')->all());
    }

    public function test_explicit_inventory_branch_must_be_authorized_in_active_context(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $clinic = $this->activeClinic(['name' => 'Clinic', 'slug' => 'authorized-inventory']);
        $authorized = $this->branch($clinic, $user, 'YES', true);
        $unassigned = $clinic->branches()->create(['code' => 'NO', 'name' => 'NO', 'is_active' => true]);
        app(TenantContext::class)->set($user, $clinic, $authorized, [$authorized->id]);
        $category = Category::create(['user_id' => $user->id, 'name' => 'Frames', 'type' => 'frame']);
        $product = Product::create(['user_id' => $user->id, 'category_id' => $category->id, 'name' => 'Private stock', 'quantity' => 0, 'cost_price' => 10, 'selling_price' => 20]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not authorized');
        app(BranchInventoryService::class)->quantity($product, $unassigned->id);
    }

    public function test_transfer_destination_must_be_a_permitted_branch(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $clinic = $this->activeClinic(['name' => 'Clinic', 'slug' => 'transfer-permissions']);
        $source = $this->branch($clinic, $user, 'SRC', true);
        $unassigned = $clinic->branches()->create(['code' => 'PRIVATE', 'name' => 'Private']);
        app(TenantContext::class)->set($user, $clinic, $source, [$source->id]);
        $category = Category::create(['user_id' => $user->id, 'name' => 'Frames', 'type' => 'frame']);
        $product = Product::create(['user_id' => $user->id, 'category_id' => $category->id, 'name' => 'Frame', 'quantity' => 5, 'cost_price' => 10, 'selling_price' => 20]);

        $this->expectException(RuntimeException::class);
        app(StockTransferService::class)->create($unassigned->id, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]);
    }

    public function test_receiving_cannot_exceed_the_purchase_order_line_balance(): void
    {
        [$user, $product, $po, $item] = $this->purchaseOrderFixture();
        $this->actingAs($user);

        Livewire::test(PurchaseOrderComponent::class)
            ->set('grnPoId', $po->id)
            ->set('grnLines', [[
                'id' => $item->id,
                'receive_qty' => 6,
                'batch_number' => '',
                'expiry_date' => '',
            ]])
            ->call('receiveGoods')
            ->assertHasErrors(['grnLines']);

        $this->assertSame(0.0, (float) $item->fresh()->quantity_received);
        $this->assertSame(0, app(BranchInventoryService::class)->quantity($product));
    }

    public function test_purchase_order_payment_cannot_exceed_outstanding_balance(): void
    {
        [$user, , $po] = $this->purchaseOrderFixture();
        $this->actingAs($user);
        $po->update(['invoice_amount' => 100, 'paid_amount' => 25, 'invoice_status' => 'partial']);

        Livewire::test(PurchaseOrderComponent::class)
            ->set('paymentPoId', $po->id)
            ->set('pay_amount', '76.00')
            ->set('pay_date', now()->toDateString())
            ->call('savePayment')
            ->assertHasErrors(['pay_amount']);

        $this->assertSame(25.0, (float) $po->fresh()->paid_amount);
    }

    private function purchaseOrderFixture(): array
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $clinic = $this->activeClinic(['name' => 'Clinic', 'slug' => 'po-'.str()->random(8)]);
        $branch = $this->branch($clinic, $user, 'MAIN', true);
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);
        $category = Category::create(['user_id' => $user->id, 'name' => 'Drugs', 'type' => 'drug']);
        $product = Product::create([
            'user_id' => $user->id, 'category_id' => $category->id, 'name' => 'Drops',
            'quantity' => 0, 'cost_price' => 10, 'selling_price' => 15,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-TEST-'.str()->random(8), 'status' => 'ordered',
            'order_date' => now(), 'total_amount' => 50, 'created_by' => $user->id,
        ]);
        $item = $po->items()->create([
            'product_id' => $product->id, 'description' => 'Drops',
            'quantity_ordered' => 5, 'quantity_received' => 0,
            'unit_cost' => 10, 'subtotal' => 50,
        ]);

        return [$user, $product, $po, $item];
    }

    private function branch(Clinic $clinic, User $user, string $code, bool $default = false): Branch
    {
        $branch = $clinic->branches()->create(['code' => $code, 'name' => $code, 'is_default' => $default]);
        $clinic->users()->syncWithoutDetaching([$user->id => ['status' => 'active']]);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => $default]);
        return $branch;
    }
}
