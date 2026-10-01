<?php

namespace Tests\Feature;

use App\Livewire\POSComponent;
use App\Models\Cart;
use App\Models\CashierPatientClearance;
use App\Models\Category;
use App\Models\Consultations;
use App\Models\Patient;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** Frames and other items reception adds to a prescription at the POS must leave the prescription once sold. */
class ReceptionAddedItemsTest extends TestCase
{
    use GivesClinicsAccess, DatabaseTransactions;

    private User $cashier;
    private Patient $patient;
    private Consultations $consultation;
    private Product $frame;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startOfflineTrial();
        Role::findOrCreate('Cashier', 'web');
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('Cashier');
        $this->actingAs($this->cashier);

        $this->patient = Patient::factory()->create(['user_id' => $this->cashier->id]);
        $clearance = CashierPatientClearance::create(['patient_id' => $this->patient->id, 'user_id' => $this->cashier->id,
            'payment_status' => 'Paid', 'clearance_date' => today()]);
        $this->consultation = Consultations::create(['patient_id' => $this->patient->id, 'user_id' => $this->cashier->id,
            'clearance_id' => $clearance->id, 'chiefComplaint' => 'Review']);
        $frames = Category::factory()->create(['user_id' => $this->cashier->id, 'name' => 'Frames ' . uniqid()]);
        $this->frame = Product::factory()->create(['user_id' => $this->cashier->id, 'category_id' => $frames->id,
            'name' => 'DIOR', 'selling_price' => 850, 'cost_price' => 300, 'quantity' => 5]);
    }

    public function test_frame_added_at_the_pos_is_marked_sold_with_the_sale(): void
    {
        $component = Livewire::test(POSComponent::class)
            ->call('selectPatient', $this->patient->id)
            ->set('prescriptionConsultationId', $this->consultation->id)
            ->call('addFrameProduct', $this->frame->id);

        $row = Cart::where('patient_id', $this->patient->id)->where('product_id', $this->frame->id)->firstOrFail();
        $this->assertSame($row->id, $component->get('cart')[$this->frame->id]['cart_id']);

        $key = $component->get('checkoutIdempotencyKey');
        $component->set('payments', [['method' => 'cash', 'amount' => 850]])->call('checkout')->assertHasNoErrors();

        $row->refresh();
        $this->assertTrue((bool) $row->purchased);
        $this->assertSame('completed', $row->status);
        $sale = Sales::where('idempotency_key', $key)->firstOrFail();
        $this->assertSame($row->id, (int) SaleItem::where('sale_id', $sale->id)->value('cart_id'));
    }

    public function test_repair_settles_rows_left_pending_by_earlier_sales_only(): void
    {
        // Left pending by the old POS: sold on a sale line added after the row.
        $stuck = Cart::create(['patient_id' => $this->patient->id, 'dispensed_by' => $this->cashier->id, 'consultation_id' => $this->consultation->id,
            'product_id' => $this->frame->id, 'quantity' => 1, 'price' => 850, 'total' => 850, 'status' => 'pending', 'purchased' => false]);
        $sale = Sales::create(['user_id' => $this->cashier->id, 'patient_id' => $this->patient->id, 'consultation_id' => $this->consultation->id,
            'business_line' => 'clinic', 'transaction_id' => 'FIX-' . uniqid(), 'total_amount' => 850, 'amount_paid' => 850, 'payment_status' => 'paid']);
        $line = SaleItem::create(['sale_id' => $sale->id, 'product_id' => $this->frame->id, 'prescribed_quantity' => 0, 'dispensed_quantity' => 1,
            'selling_price' => 850, 'subtotal' => 850]);

        // Prescribed again afterwards and not yet bought: must stay pending.
        $this->travel(1)->minutes();
        $newPrescription = Cart::create(['patient_id' => $this->patient->id, 'dispensed_by' => $this->cashier->id, 'consultation_id' => $this->consultation->id,
            'product_id' => $this->frame->id, 'quantity' => 1, 'price' => 850, 'total' => 850, 'status' => 'pending', 'purchased' => false]);

        (require database_path('migrations/2026_10_02_000003_settle_sold_cart_rows_left_pending.php'))->up();

        $this->assertTrue((bool) $stuck->fresh()->purchased);
        $this->assertSame($stuck->id, (int) $line->fresh()->cart_id);
        $this->assertFalse((bool) $newPrescription->fresh()->purchased);
    }
}
