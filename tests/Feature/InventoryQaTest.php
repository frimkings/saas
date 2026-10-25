<?php

namespace Tests\Feature;

use App\Livewire\Admin\InventoryAlertsComponent;
use App\Livewire\Admin\ProductsComponent;
use App\Livewire\Admin\PurchaseOrderComponent;
use App\Livewire\Admin\StockMovementComponent;
use App\Livewire\Admin\StockTransferComponent;
use App\Livewire\Admin\SupplierComponent;
use App\Livewire\POSComponent;
use App\Livewire\PosProductGrid;
use App\Models\{CashMovement, Category, InventoryLot, Product, PurchaseOrder, PurchaseOrderItem, StockTransfer, Supplier};
use App\Services\Inventory\BranchInventoryService;
use App\Services\Reminders\AttentionItems;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** The inventory walk-through (2026-10-25): transfers, purchase orders, batches, alerts and reorder levels. */
class InventoryQaTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    private function product(string $name, string $type = 'drug', array $extra = []): Product
    {
        $category = Category::firstOrCreate(['name' => ucfirst($type).'s'], ['type' => $type, 'user_id' => auth()->id()]);

        return Product::create(array_merge(['user_id' => auth()->id(), 'name' => $name, 'category_id' => $category->id,
            'batch_number' => strtoupper(substr($name, 0, 3)).'-'.random_int(1000, 9999), 'manufacture_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->addYears(2)->toDateString(), 'quantity' => 50, 'cost_price' => 20, 'selling_price' => 35], $extra));
    }

    public function test_transfers_list_their_lines_and_refuse_what_cannot_move(): void
    {
        $owner = $this->ownerOn('clinic-pro');
        $east = $this->clinic->branches()->create(['code' => 'EAST', 'name' => 'East', 'is_default' => false, 'is_active' => true]);
        $east->users()->attach($owner->id, ['status' => 'active', 'is_default' => false]);
        app(TenantContext::class)->set($owner, $this->clinic, $this->branch, [$this->branch->id, $east->id]);
        $drug = $this->product('Timolol');
        $service = $this->product('Consultation', 'service', ['made_to_order' => true, 'quantity' => 0]);

        $page = Livewire::test(StockTransferComponent::class)
            ->set('destinationBranchId', $east->id)->set('productId', $service->id)->set('quantity', 1)->call('createTransfer')
            ->assertHasErrors('quantity')
            ->set('productId', $drug->id)->set('quantity', 500)->call('createTransfer')
            ->assertHasErrors('quantity')
            ->set('destinationBranchId', $east->id)->set('productId', $drug->id)->set('quantity', 5)->call('createTransfer')
            ->assertHasNoErrors();

        $this->assertSame(1, StockTransfer::count());
        // The page lists the transfer and its product (it crashed once a transfer existed).
        $page->assertSee('Timolol');
    }

    public function test_a_purchase_order_with_two_lines_is_received_in_two_deliveries(): void
    {
        $this->ownerOn('clinic-pro');
        $drug = $this->product('Timolol');
        $frame = $this->product('Ray-Ban Frame', 'frame', ['quantity' => 10, 'cost_price' => 150, 'selling_price' => 400]);
        $po = PurchaseOrder::create(['po_number' => 'PO-QA-1', 'status' => 'draft', 'order_date' => today(), 'total_amount' => 1060, 'created_by' => auth()->id()]);
        foreach ([[$drug, 20, 18], [$frame, 5, 140]] as [$product, $qty, $cost]) {
            PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'description' => $product->name,
                'quantity_ordered' => $qty, 'quantity_received' => 0, 'unit_cost' => $cost, 'subtotal' => $qty * $cost]);
        }

        // A draft is not received.
        Livewire::test(PurchaseOrderComponent::class)->call('openGrn', $po->id)->assertSet('showGrnModal', false);
        $po->update(['status' => 'ordered']);

        $grn = fn () => Livewire::test(PurchaseOrderComponent::class)->call('openGrn', $po->id);
        $grn()->set('grnLines.0.receive_qty', 5)->set('grnLines.0.expiry_date', now()->addYear()->toDateString())->call('receiveGoods')->assertHasNoErrors();
        // More than is outstanding: refused, and the reason is shown.
        $grn()->set('grnLines.0.receive_qty', 16)->call('receiveGoods')->assertHasErrors('grnLines')->assertSee('exceeds the outstanding quantity');
        // An expired delivery is refused.
        $grn()->set('grnLines.0.receive_qty', 15)->set('grnLines.0.expiry_date', now()->subDay()->toDateString())->call('receiveGoods')->assertHasErrors('grnLines.0.expiry_date');
        // The rest of both lines in one delivery (two movements against the same order).
        $grn()->set('grnLines.0.receive_qty', 15)->set('grnLines.0.expiry_date', now()->addYear()->toDateString())->set('grnLines.1.receive_qty', 5)
            ->call('receiveGoods')->assertHasNoErrors();

        $this->assertSame('received', $po->fresh()->status);
        $this->assertSame(70, (int) $drug->fresh()->quantity);
        $this->assertSame(15, (int) $frame->fresh()->quantity);
    }

    public function test_a_supplier_saves_without_a_lead_time_and_its_cash_payment_leaves_the_cash_book(): void
    {
        $this->ownerOn('clinic-pro');
        Livewire::test(SupplierComponent::class)->call('openCreate')->set('state.name', 'Vision Supplies')->call('save')->assertHasNoErrors();
        $supplier = Supplier::where('name', 'Vision Supplies')->sole();
        $this->assertNull($supplier->lead_time_days);

        $po = PurchaseOrder::create(['po_number' => 'PO-QA-2', 'supplier_id' => $supplier->id, 'status' => 'received', 'order_date' => today(),
            'total_amount' => 1000, 'created_by' => auth()->id(), 'invoice_number' => 'INV-1', 'invoice_amount' => 1000, 'invoice_status' => 'invoiced', 'paid_amount' => 0]);
        Livewire::test(PurchaseOrderComponent::class)->call('openPaymentModal', $po->id)
            ->set('pay_amount', 500)->set('pay_method', 'cash')->call('savePayment')->assertHasNoErrors();

        $movement = CashMovement::where('type', CashMovement::SUPPLIER)->sole();
        $this->assertSame(-500.0, (float) $movement->amount);
        $this->assertSame('cash', $movement->payment_method);
    }

    public function test_an_expired_delivery_is_not_received(): void
    {
        $this->ownerOn('clinic-pro');
        $drug = $this->product('Timolol');
        Livewire::test(StockMovementComponent::class)->set('productId', $drug->id)->set('quantity', 10)
            ->set('expiryDate', now()->subDay()->toDateString())->call('receiveStock')->assertHasErrors('expiryDate');
        $this->assertSame(50, (int) $drug->fresh()->quantity);
    }

    public function test_sales_draw_batches_down_earliest_first_and_alerts_see_expired_batches(): void
    {
        $this->ownerOn('clinic-pro');
        $drug = $this->product('Timolol', 'drug', ['expiry_date' => now()->addYears(2)->toDateString()]);
        $inventory = app(BranchInventoryService::class);
        $inventory->increase($drug, 10, ['batch_number' => 'LATER', 'expiry_date' => now()->addYear()->toDateString()]);
        $inventory->increase($drug, 10, ['batch_number' => 'SOON', 'expiry_date' => now()->addMonths(6)->toDateString()]);
        $expired = InventoryLot::create(['branch_id' => $this->branch->id, 'product_id' => $drug->id, 'batch_number' => 'OLD',
            'expiry_date' => now()->subDays(10)->toDateString(), 'opening_quantity' => 4, 'quantity' => 4]);

        // The 50 held before batches became a batch of their own.
        $this->assertSame(50, (int) InventoryLot::where('product_id', $drug->id)->where('batch_number', $drug->batch_number)->value('quantity'));

        $inventory->decrease($drug, 12);
        $lot = fn (string $batch) => (int) InventoryLot::where('product_id', $drug->id)->where('batch_number', $batch)->value('quantity');
        $this->assertSame(0, $lot('SOON'));
        $this->assertSame(8, $lot('LATER'));
        $this->assertSame(4, $lot('OLD'));
        // The product shows its next in-date batch, so the till still sells it.
        $this->assertSame(now()->addYear()->toDateString(), $drug->fresh()->expiry_date->toDateString());

        Livewire::test(InventoryAlertsComponent::class)->set('activeTab', 'expired')->assertSee('Timolol')->assertSee('OLD');

        // Taking the expired batch off the shelf empties that batch only.
        app(AttentionItems::class)->writeOff('inventory_lot', $expired->id);
        $this->assertSame(0, $lot('OLD'));
        $this->assertSame(8, $lot('LATER'));
    }

    public function test_reorder_levels_and_out_of_stock(): void
    {
        $this->ownerOn('clinic-pro');
        $drug = $this->product('Timolol', 'drug', ['quantity' => 50]);
        $empty = $this->product('Atropine', 'drug', ['quantity' => 0]);
        $this->assertFalse(Product::needsReorder()->whereKey($drug->id)->exists());

        Livewire::test(ProductsComponent::class)->call('editProduct', $drug->id)->set('state.reorder_level', 60)->call('updateProduct')->assertHasNoErrors();
        $this->assertSame(60, $drug->fresh()->reorderLevel());
        $this->assertTrue($drug->fresh()->isLowStock());
        $this->assertTrue(Product::needsReorder()->whereKey($drug->id)->exists());

        Livewire::test(InventoryAlertsComponent::class)->assertSee('Timolol')->assertSee('Out of stock');
        $this->assertSame(50, (int) $drug->fresh()->quantity);
    }

    public function test_an_inactive_category_is_not_sold_and_services_import_without_stock_details(): void
    {
        $this->ownerOn('clinic-pro');
        $frame = $this->product('Ray-Ban Frame', 'frame');
        $frame->category->update(['is_active' => false]);

        Livewire::test(PosProductGrid::class)->assertDontSee('Ray-Ban Frame');
        $pos = Livewire::test(POSComponent::class)->call('addToCart', $frame->id);
        $this->assertArrayNotHasKey($frame->id, $pos->get('cart'));

        Category::create(['name' => 'Services', 'type' => 'service', 'user_id' => auth()->id()]);
        $csv = "name,category,batch_number,quantity,cost_price,selling_price,manufacture_date,expiry_date\nEye Test Fee,Services,,,,50,,\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('products.csv', $csv);
        Livewire::test(ProductsComponent::class)->set('importFile', $file)->call('importCsv');
        $fee = Product::where('name', 'Eye Test Fee')->sole();
        $this->assertTrue($fee->made_to_order);
        $this->assertStringStartsWith('SVC-', $fee->batch_number);
    }
}
