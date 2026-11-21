<?php

namespace Tests\Feature;

use App\Livewire\Secretary\SpectaclesComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SpectaclesPageTest extends TestCase
{
    use DatabaseTransactions;

    private User $secretary;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Secretary', 'guard_name' => 'web']);
        $this->secretary = User::factory()->create();
        $this->secretary->assignRole('Secretary');
        $this->actingAs($this->secretary);
    }

    public function test_secretary_can_render_spectacle_orders_page(): void
    {
        $this->get(route('secretary.spectacles'))
            ->assertOk()
            ->assertSee('Spectacle Orders')
            ->assertSee('To order');
    }

    public function test_record_type_controls_switch_without_livewire_errors(): void
    {
        Livewire::test(SpectaclesComponent::class)
            ->assertSet('recordType', 'orders')
            ->call('setRecordType', 'refractions')
            ->assertSet('recordType', 'refractions')
            ->assertSet('statusFilter', '')
            ->call('setStatusFilter', 'In Lab')
            ->assertSet('recordType', 'orders')
            ->assertSet('statusFilter', 'In Lab')
            ->set('searchTerm', 'PX-TEST')
            ->assertSet('searchTerm', 'PX-TEST')
            ->assertStatus(200);
    }

    /** One status bar (20 Nov 2026): To order, the Orders group by status, and the two warnings; no duplicate dropdown, tabs or chips. */
    public function test_one_status_bar_filters_the_page(): void
    {
        $page = Livewire::test(SpectaclesComponent::class)->call('setRecordType', 'orders');
        $page->assertSeeInOrder(['To order', 'Orders', 'Pending', 'In lab', 'Ready', 'Collected', 'Cancelled', 'Pickup overdue', 'Renewal due'])
            ->assertDontSeeHtml('wire:model.live="statusFilter"')->assertDontSee('Refractions Needing Order')
            ->assertDontSeeHtml("setQuickFilter('today')")->assertDontSee('Awaiting order');

        // "Pending" is an order's status now, not "no order yet"; that list is To order.
        $page->call('setStatusFilter', 'Pending')->assertSet('recordType', 'orders')->assertSet('statusFilter', 'Pending')
            ->call('setStatusFilter', 'Ordered')->assertSet('statusFilter', '')
            ->call('setQuickFilter', 'overdue')->assertSet('quickFilter', 'overdue')->assertSet('recordType', 'orders')
            ->call('setQuickFilter', 'overdue')->assertSet('quickFilter', '')
            ->call('setQuickFilter', 'today')->assertSet('quickFilter', '')
            ->call('setRecordType', 'refractions')->assertSet('recordType', 'refractions')->assertSet('statusFilter', '');
    }

    /** A refraction for glasses; $sold: its frame and lens on a part-paid POS bill. */
    private function refractionFor(string $name, bool $sold): \App\Models\Refractions
    {
        $patient = \App\Models\Patient::createWithGeneratedPxNumber(['user_id' => $this->secretary->id, 'name' => $name, 'contact' => '0240001111', 'gender' => 'Male']);
        $clearance = \App\Models\CashierPatientClearance::create(['user_id' => $this->secretary->id, 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = \App\Models\Consultations::create(['user_id' => $this->secretary->id, 'patient_id' => $patient->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Blur']);
        if ($sold) {
            $sale = \App\Models\Sales::create(['user_id' => $this->secretary->id, 'patient_id' => $patient->id, 'consultation_id' => $consultation->id,
                'transaction_id' => 'TXN-BULK-'.$consultation->id, 'business_line' => 'clinic', 'total_amount' => 1000, 'amount_paid' => 500,
                'payment_status' => 'partial', 'bill_status' => 'open']);
            foreach (['Bulk Frames' => 400, 'Bulk Lenses' => 600] as $categoryName => $price) {
                $category = \App\Models\Category::firstOrCreate(['name' => $categoryName], ['user_id' => $this->secretary->id]);
                $product = \App\Models\Product::firstOrCreate(['name' => $categoryName.' item'], ['user_id' => $this->secretary->id, 'category_id' => $category->id,
                    'quantity' => 5, 'cost_price' => 100, 'selling_price' => $price]);
                \App\Models\SaleItem::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'prescribed_quantity' => 1, 'dispensed_quantity' => 0,
                    'selling_price' => $price, 'subtotal' => 0]);
            }
        }

        return \App\Models\Refractions::create(['user_id' => $this->secretary->id, 'consultation_id' => $consultation->id,
            'refractionOD' => '-1.00', 'refractionOS' => '-1.00', 'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6',
            'pd' => 62, 'lensType' => 'Single vision', 'dispensing_required' => true, 'dispensing_authorized_at' => now()]);
    }

    /** 20 Nov 2026: tick rows; To order creates orders in one go (frame and lens from POS), orders move status together. */
    public function test_ticked_rows_create_orders_and_move_status_together(): void
    {
        $ready = $this->refractionFor('Bulk Ready', true);
        $alsoReady = $this->refractionFor('Bulk Also', true);
        $unpaid = $this->refractionFor('Bulk Unpaid', false);

        $page = Livewire::test(SpectaclesComponent::class)->call('setRecordType', 'refractions')
            ->assertSeeHtml('wire:click="togglePickPage"')
            ->call('togglePick', $ready->id)->call('togglePick', $alsoReady->id)->call('togglePick', $unpaid->id)
            ->assertSet('picked', [$ready->id, $alsoReady->id, $unpaid->id])->assertSee('3 ticked')->assertSee('Create orders')
            ->set('bulkPickUpDate', now()->subDay()->toDateString())->call('bulkCreateOrders')->assertHasErrors(['bulkPickUpDate'])
            ->set('bulkPickUpDate', now()->addDays(5)->toDateString())->call('bulkCreateOrders')->assertHasNoErrors()
            ->assertSet('picked', [])->assertSee('Bulk Unpaid: no payment at POS yet');

        foreach ([$ready, $alsoReady] as $refraction) {
            $order = \App\Models\LensOrder::where('refraction_id', $refraction->id)->sole();
            $this->assertSame('Pending', $order->status);
            $this->assertSame(now()->addDays(5)->toDateString(), \Carbon\Carbon::parse($order->pickUpDate)->toDateString());
            $this->assertTrue((bool) $order->frame_stock_from_sale);
            $this->assertTrue((bool) $order->lens_stock_from_sale);
        }
        $this->assertFalse(\App\Models\LensOrder::where('refraction_id', $unpaid->id)->exists());

        // On the orders list: tick one and move it; the other stays.
        $page->call('setRecordType', 'orders')->call('togglePick', $ready->id)->assertSee('Move to status')->assertDontSee('Create orders')
            ->set('bulkStatus', 'In Lab')->call('bulkUpdateStatus');
        $this->assertSame('In Lab', \App\Models\LensOrder::where('refraction_id', $ready->id)->value('status'));
        $this->assertSame('Pending', \App\Models\LensOrder::where('refraction_id', $alsoReady->id)->value('status'));

        // The header box ticks the whole page; switching lists clears it.
        $page->call('togglePickPage')->assertCount('picked', 2)->call('togglePickPage')->assertCount('picked', 0)
            ->call('togglePick', $ready->id)->call('setRecordType', 'refractions')->assertSet('picked', []);
    }

    /** 20 Nov 2026: a cancelled order stays as history and the patient is back in To order, to order again or send out. */
    public function test_a_cancelled_order_puts_the_patient_back_to_order_again(): void
    {
        $refraction = $this->refractionFor('Reorder Patient', true);
        $page = Livewire::test(SpectaclesComponent::class)->call('setRecordType', 'refractions')
            ->call('togglePick', $refraction->id)->set('bulkPickUpDate', now()->addDays(3)->toDateString())->call('bulkCreateOrders');
        $first = \App\Models\LensOrder::where('refraction_id', $refraction->id)->sole();
        $this->assertFalse(\App\Models\Refractions::awaitingSpectacleOrder()->whereKey($refraction->id)->exists());

        // Cancel it (with a reason): it stays as history, and the patient is back in To order.
        // 21 Nov 2026: the POS sale, its lines and payments are left exactly as they were (a refund is done at the POS).
        $sale = \App\Models\Sales::where('consultation_id', $refraction->consultation_id)->sole();
        $before = [$sale->total_amount, $sale->amount_paid, $sale->payment_status, $sale->items()->count()];
        $page->call('openCancelConfirm', $first->id)->assertSeeHtml('data-cancel-pos-note')->set('cancelReason', 'Patient changed frame')->call('confirmCancelOrder')->assertHasNoErrors();
        $this->assertSame('Cancelled', $first->fresh()->status);
        $sale->refresh();
        $this->assertEquals($before, [$sale->total_amount, $sale->amount_paid, $sale->payment_status, $sale->items()->count()]);
        $this->assertTrue(\App\Models\Refractions::awaitingSpectacleOrder()->whereKey($refraction->id)->exists());
        $page->call('setRecordType', 'refractions')->assertSee('Reorder Patient')->assertSee("Order {$first->order_id} was cancelled")
            ->assertSeeHtml("openOrderModal({$refraction->id})");

        // And can be ordered again; the new order is the one the page shows.
        $page->call('openOrderModal', $refraction->id)->assertSet('showOrderModal', true)->call('closeOrderModal')
            ->call('togglePick', $refraction->id)->call('bulkCreateOrders')->assertSet('bulkSkipped', []);
        $orders = \App\Models\LensOrder::where('refraction_id', $refraction->id)->orderBy('id')->get();
        $this->assertSame(['Cancelled', 'Pending'], $orders->pluck('status')->all());
        $this->assertSame($orders->last()->id, $refraction->fresh()->lensOrder->id);
        $this->assertFalse(\App\Models\Refractions::awaitingSpectacleOrder()->whereKey($refraction->id)->exists());

        // Not a third time while that one is going.
        $page->call('togglePick', $refraction->id)->call('bulkCreateOrders')->assertSet('bulkSkipped', [['name' => 'Reorder Patient', 'reason' => 'already has an order']]);
    }

    /** The case opens as a side drawer (like Partner Clinics) only when a row is clicked, and closes again. */
    public function test_a_clicked_row_opens_the_case_drawer(): void
    {
        $patient = \App\Models\Patient::createWithGeneratedPxNumber(['user_id' => $this->secretary->id, 'name' => 'Drawer Patient', 'contact' => '0240009999', 'gender' => 'Female']);
        $clearance = \App\Models\CashierPatientClearance::create(['user_id' => $this->secretary->id, 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = \App\Models\Consultations::create(['user_id' => $this->secretary->id, 'patient_id' => $patient->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Blurred vision']);
        $refraction = \App\Models\Refractions::create(['user_id' => $this->secretary->id, 'consultation_id' => $consultation->id,
            'refractionOD' => '-1.00', 'refractionOS' => '-1.00', 'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6', 'pd' => 62, 'lensType' => 'Single vision', 'dispensing_required' => true]);

        Livewire::test(SpectaclesComponent::class)->call('setRecordType', 'refractions')
            // Doctor (and Lab) filters are dropdowns you can type into, filtering as soon as one is picked.
            ->assertSeeHtml('id="so-doctor-filter"')->assertSeeHtml("entangle('doctorFilter').live")->assertDontSeeHtml('wire:model.live="doctorFilter"')
            ->set('doctorFilter', (string) $this->secretary->id)->assertSee('Drawer Patient')->set('doctorFilter', '')
            ->assertSee('Drawer Patient')->assertDontSeeHtml('class="oo-drawer so-case-drawer"')
            ->call('selectRefraction', $refraction->id)
            ->assertSeeHtml('class="oo-drawer so-case-drawer"')->assertSeeHtml('id="so-case-title"')->assertSeeHtml('POS & Payment')
            ->set('activeRefractionId', null)
            ->assertDontSeeHtml('class="oo-drawer so-case-drawer"');

        // The job card print buttons open the preview first (20 Nov 2026); printing is from there.
        $order = \App\Models\LensOrder::create(['user_id' => $this->secretary->id, 'refraction_id' => $refraction->id, 'order_id' => 'ORD-PREVIEW1',
            'status' => 'Pending', 'frame_model_number' => 'Test frame', 'frame_price' => 100, 'lens_price' => 200, 'pickUpDate' => now()->addWeek()->toDateString()]);
        Livewire::test(SpectaclesComponent::class)->call('setRecordType', 'orders')
            ->assertSeeHtml("openPrintPreview({$order->id})")->assertDontSeeHtml('directPrint(')
            ->call('openPrintPreview', $order->id)->assertSet('showPrintPreview', true)->assertSet('autoPrint', false)
            ->assertSee('Preview before printing')->assertSeeHtml('onclick="printThermalJobCard()"')
            // Laid out like the POS receipt: clinic header, dashed dividers, no black banners; printed with its stylesheet.
            ->assertSeeHtml('<div class="doc-title">Lab Job Order</div>')->assertSeeHtml('class="divider"')->assertDontSeeHtml('class="th-header"')
            ->assertSeeHtml('class="so-print-dialog"')->assertSeeHtml('2px dashed #000');
    }

    public function test_client_cannot_inline_edit_protected_order_fields(): void
    {
        Livewire::test(SpectaclesComponent::class)
            ->set('editField.status', 'Collected')
            ->call('saveEdit', 999999, 'status')
            ->assertDispatched('notify');
    }
}
