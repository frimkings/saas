<?php

namespace Tests\Feature;

use App\Livewire\Admin\ExpensesComponent;
use App\Livewire\Admin\PurchaseOrderComponent;
use App\Livewire\Admin\QuotationComponent;
use App\Livewire\Doctor\PatientRecordsComponent;
use App\Livewire\POSComponent;
use App\Livewire\Secretary\SpectaclesComponent;
use App\Models\{Cart, CashierPatientClearance, Category, Consultations, DayClose, ExpenseCategory, LensOrder, Patient, Product, PurchaseOrder,
    PurchaseOrderItem, Refractions, RefundLog, SaleItem, Sales};
use App\Services\Finance\ClinicStatementService;
use App\Services\Finance\RefundProcessor;
use App\Support\Optical\ClinicSpectacleBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** The second page-by-page check (2026-10-25): spectacle orders, the doctor's file, money and stock pages. */
class ClinicQaRound2Test extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    private function product(string $name, string $type, array $extra = []): Product
    {
        $category = Category::firstOrCreate(['name' => ucfirst($type).'s'], ['type' => $type, 'user_id' => auth()->id()]);

        return Product::create(array_merge(['user_id' => auth()->id(), 'name' => $name, 'category_id' => $category->id,
            'batch_number' => strtoupper(substr($type, 0, 3)).'-'.random_int(1000, 9999), 'manufacture_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->addYears(3)->toDateString(), 'quantity' => 10, 'cost_price' => 20, 'selling_price' => 50], $extra));
    }

    private function patient(): Patient
    {
        return Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Kofi Mensah', 'contact' => '0244556677', 'gender' => 'Male']);
    }

    /** A paid visit with its consultation and a refraction that needs glasses. */
    private function visit(?Patient $patient = null): array
    {
        $patient ??= $this->patient();
        $clearance = CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = Consultations::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_id' => $clearance->id, 'chiefComplaint' => 'Blurred vision']);
        $refraction = Refractions::create(['user_id' => auth()->id(), 'consultation_id' => $consultation->id,
            'refractionOD' => '-1.25', 'refractionOS' => '-1.00', 'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6',
            'dispensing_required' => true, 'pd' => 63, 'lensType' => 'Progressive AR']);

        return [$patient, $clearance, $consultation, $refraction];
    }

    public function test_the_till_sells_frames_to_a_patient_without_a_prescription_but_not_drugs_or_lenses(): void
    {
        $this->ownerOn('clinic-pro');
        $frame = $this->product('Ray-Ban Frame', 'frame');
        $drug = $this->product('Timolol', 'drug');
        $lens = $this->product('Progressive Lens', 'lens', ['quantity' => 0, 'made_to_order' => true]);
        $patient = $this->patient();

        $pos = Livewire::test(POSComponent::class)->call('selectPatient', $patient->id)
            ->call('addToCart', $frame->id)
            ->call('addToCart', $drug->id)
            ->call('addToCart', $lens->id);

        // The frame is on the till (it used to fail saving a cart row with no consultation).
        $cart = $pos->get('cart');
        $this->assertArrayHasKey($frame->id, $cart);
        $this->assertArrayNotHasKey($drug->id, $cart);
        $this->assertArrayNotHasKey($lens->id, $cart);
        $this->assertSame(0, Cart::count());
        $pos->assertDispatched('notify', fn ($name, $params) => str_contains($params['message'] ?? '', 'needs a doctor\'s prescription'));
    }

    public function test_held_spectacles_are_valued_and_cannot_be_collected_until_paid(): void
    {
        $this->ownerOn('clinic-pro');
        [$patient, , $consultation, $refraction] = $this->visit();
        $frame = $this->product('Ray-Ban Frame', 'frame', ['selling_price' => 400, 'cost_price' => 150]);
        $lens = $this->product('Progressive Lens', 'lens', ['quantity' => 0, 'made_to_order' => true, 'selling_price' => 600]);
        // A part-paid bill: the frame and lens are held (not dispensed, no subtotal yet).
        $sale = Sales::create(['user_id' => auth()->id(), 'patient_id' => $patient->id, 'consultation_id' => $consultation->id,
            'transaction_id' => 'TXN-HOLD-1', 'business_line' => 'clinic', 'total_amount' => 1000, 'amount_paid' => 500,
            'payment_status' => 'partial', 'bill_status' => 'open']);
        foreach ([$frame, $lens] as $product) {
            SaleItem::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'prescribed_quantity' => 1, 'dispensed_quantity' => 0,
                'selling_price' => $product->selling_price, 'subtotal' => 0]);
        }

        $bill = ClinicSpectacleBilling::summary($refraction->fresh());
        $this->assertSame(1000.0, (float) $bill['amount']);
        $this->assertSame('partial', $bill['status']);
        $this->assertSame(500.0, (float) $bill['bill_balance']);

        // The order takes the real prices.
        Livewire::test(SpectaclesComponent::class)->call('openOrderModal', $refraction->id)->call('createOrder');
        $order = LensOrder::where('refraction_id', $refraction->id)->sole();
        $this->assertSame(400.0, (float) $order->frame_price);
        $this->assertSame(600.0, (float) $order->lens_price);

        // Through the lab, but not out of the door with GH₵500 owed.
        $order->update(['status' => 'Ready']);
        Livewire::test(SpectaclesComponent::class)->call('updateStatus', $order->id, 'Collected');
        $this->assertSame('Ready', $order->fresh()->status);

        $sale->update(['amount_paid' => 1000, 'payment_status' => 'paid']);
        Livewire::test(SpectaclesComponent::class)->call('updateStatus', $order->id, 'Collected');
        $this->assertSame('Collected', $order->fresh()->status);
    }

    public function test_spectacle_orders_open_on_the_refractions_waiting(): void
    {
        $this->ownerOn('clinic-pro');
        $this->visit();
        Livewire::test(SpectaclesComponent::class)->assertSet('recordType', 'refractions');
    }

    public function test_lab_work_is_a_cost_of_sales(): void
    {
        $this->ownerOn('clinic-pro');
        [, , , $refraction] = $this->visit();
        LensOrder::create(['user_id' => auth()->id(), 'refraction_id' => $refraction->id, 'order_id' => 'ORD-LAB-1', 'status' => 'In Lab',
            'frame_price' => 400, 'lens_price' => 600, 'lab_cost' => 150, 'pickUpDate' => now()->addWeek()->toDateString()]);

        $lines = app(ClinicStatementService::class)->costLines(now()->startOfMonth(), now());
        $lab = $lines->firstWhere('name', ClinicStatementService::LAB_WORK);
        $this->assertNotNull($lab);
        $this->assertSame(150.0, (float) $lab->amount);
    }

    public function test_a_refund_is_not_paid_out_on_a_closed_day(): void
    {
        $this->ownerOn('clinic-pro');
        $sale = $this->paidSale();
        $refund = RefundLog::create(['sale_id' => $sale->id, 'sale_item_ids' => $sale->items->pluck('id')->all(), 'status' => RefundLog::STATUS_APPROVED,
            'initiated_by' => auth()->id(), 'approved_by' => auth()->id(), 'approved_at' => now(), 'reason' => 'Customer returned the drops']);
        DayClose::create(['business_date' => today(), 'status' => DayClose::CLOSED, 'methods' => [], 'cashiers' => [], 'closed_by' => auth()->id(), 'closed_at' => now()]);

        try {
            app(RefundProcessor::class)->process($refund);
            $this->fail('A refund was paid out on a closed day.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Today has been closed', $e->getMessage());
        }
        $this->assertSame(RefundLog::STATUS_APPROVED, $refund->fresh()->status);
    }

    public function test_reopening_a_file_picks_up_its_consultation_and_keeps_typed_refraction(): void
    {
        $this->ownerOn('clinic-pro');
        [, $clearance, $consultation] = $this->visit();
        $consultation->refraction()->delete();

        $file = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])
            ->assertSet('consultationID', $consultation->id)
            ->assertSet('activeTab', 'history');

        // Typed but not saved, then away and back: still there.
        $file->call('switchTab', 'refraction')
            ->set('state.subjective_od_sphere', '-1.25')
            ->call('switchTab', 'consultation')
            ->call('switchTab', 'refraction')
            ->assertSet('state.subjective_od_sphere', '-1.25');

        // Glasses needed without P.D. or lens type: the errors say so in words.
        $file->set('state.dispensing_required', true)->set('state.subjective_od_bcva', '6/6')
            ->set('state.subjective_os_sphere', '-1.00')->set('state.subjective_os_bcva', '6/6')
            ->call('saveRefraction')
            ->assertHasErrors(['state.pd', 'state.lensType']);
        $this->assertSame('Enter the P.D. when glasses are to be dispensed.', $file->errors()->first('state.pd'));
    }

    public function test_a_quotation_line_keeps_its_description_when_quantity_changes(): void
    {
        $this->ownerOn('clinic-pro');
        Livewire::test(QuotationComponent::class)->call('openCreate')
            ->call('searchProducts', 0, 'Progressive lenses + frame')
            ->set('items.0.quantity', 2)
            ->set('items.0.unit_price', 600)
            ->assertSet('items.0.description', 'Progressive lenses + frame');
    }

    public function test_receiving_a_purchase_order_takes_the_latest_cost(): void
    {
        $this->ownerOn('clinic-pro');
        $drug = $this->product('Timolol', 'drug', ['cost_price' => 20, 'selling_price' => 35]);
        $po = PurchaseOrder::create(['po_number' => 'PO-TEST-1', 'status' => 'ordered', 'order_date' => today(), 'total_amount' => 360, 'created_by' => auth()->id()]);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $drug->id, 'description' => 'Timolol',
            'quantity_ordered' => 20, 'quantity_received' => 0, 'unit_cost' => 18, 'subtotal' => 360]);

        Livewire::test(PurchaseOrderComponent::class)->call('openGrn', $po->id)
            ->set('grnLines.0.receive_qty', 20)->set('grnLines.0.batch_number', 'TIM-2502')->set('grnLines.0.expiry_date', now()->addYears(2)->toDateString())
            ->call('receiveGoods')->assertHasNoErrors();

        $this->assertSame(30, (int) $drug->fresh()->quantity);
        $this->assertSame(18.0, (float) $drug->fresh()->cost_price);
    }

    public function test_a_clinic_with_no_expense_categories_can_add_the_common_ones(): void
    {
        $this->ownerOn('clinic-pro');
        Livewire::test(ExpensesComponent::class)->assertSee('Add common categories')->call('addDefaultCategories');
        $this->assertTrue(ExpenseCategory::where('name', 'Medical consumables')->exists());
    }
}
