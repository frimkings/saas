<?php

namespace Tests\Feature;

use App\Livewire\Admin\ReceiptSettingsComponent;
use App\Livewire\Cashier\CashierPatientClearanceComponent;
use App\Livewire\OutstandingBalancesComponent;
use App\Livewire\POSComponent;
use App\Models\CashierPatientClearance;
use App\Models\Category;
use App\Models\Consultations;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Models\Setting;
use App\Models\User;
use App\Services\Visits\PatientVisits;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class VisitReceiptsTest extends TestCase
{
    use GivesClinicsAccess, DatabaseTransactions;

    private User $cashier;
    private Patient $patient;
    private Product $consult;
    private Product $drops;
    private Product $frame;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startOfflineTrial();
        foreach (['Cashier', 'Manager', 'Super Admin', 'Doctor'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole(['Cashier', 'Manager']);
        $this->actingAs($this->cashier);

        $this->patient = Patient::factory()->create(['user_id' => $this->cashier->id]);
        $services = Category::factory()->create(['user_id' => $this->cashier->id, 'name' => 'Clinical Services ' . uniqid(), 'type' => 'service']);
        $drugs = Category::factory()->create(['user_id' => $this->cashier->id, 'name' => 'Eye Drops ' . uniqid(), 'type' => 'drug']);
        $frames = Category::factory()->create(['user_id' => $this->cashier->id, 'name' => 'Frames ' . uniqid()]);
        $this->consult = $this->product($services, 200, 'Consultation');
        $this->drops = $this->product($drugs, 50, 'Tears Naturale');
        $this->frame = $this->product($frames, 300, 'Ray-Ban frame');
    }

    // ── Grouping ─────────────────────────────────────────────────────────

    public function test_clearance_and_its_prescription_sales_form_one_visit(): void
    {
        $this->setVisitReceipts(true);
        $clearance = $this->clear();
        $consultation = $this->consultationFor($clearance);

        // Doctor's drops plus the frame reception added to the same prescription cart, one checkout.
        $this->checkout([$this->drops, $this->frame], 350, $consultation->id);

        $visit = PatientVisit::where('clearance_id', $clearance->id)->firstOrFail();
        $this->assertSame(2, Sales::where('patient_visit_id', $visit->id)->count());
        $this->assertSame((int) $visit->id, (int) Sales::findOrFail($clearance->fresh()->sale_id)->patient_visit_id);
        $this->assertMatchesRegularExpression('/^V-\d{6}-\d{5}$/', $visit->visit_number);
    }

    public function test_pos_sale_without_a_clearance_is_its_own_visit(): void
    {
        $this->setVisitReceipts(true);
        $this->clear();

        $sale = $this->checkout([$this->drops], 50);

        $visit = PatientVisit::findOrFail($sale->patient_visit_id);
        $this->assertNull($visit->clearance_id);
        $this->assertSame(1, Sales::where('patient_visit_id', $visit->id)->count());
    }

    public function test_optical_sales_and_walk_ins_are_never_in_a_visit(): void
    {
        $optical = Sales::create(['user_id' => $this->cashier->id, 'patient_id' => $this->patient->id, 'business_line' => 'optical',
            'transaction_id' => 'OPT-' . uniqid(), 'total_amount' => 100, 'amount_paid' => 100, 'payment_status' => 'paid']);
        $walkIn = Sales::create(['user_id' => $this->cashier->id, 'business_line' => 'clinic',
            'transaction_id' => 'WLK-' . uniqid(), 'total_amount' => 100, 'amount_paid' => 100, 'payment_status' => 'paid']);

        $this->assertNull(app(PatientVisits::class)->attach($optical));
        $this->assertNull(app(PatientVisits::class)->attach($walkIn));
    }

    // ── Receipts ─────────────────────────────────────────────────────────

    public function test_with_the_setting_off_each_payment_still_gets_its_receipt(): void
    {
        $this->setVisitReceipts(false);

        $this->clearance()->call('createClearance', (string) $this->consult->id, json_encode([['method' => 'cash', 'amount' => 200]]))
            ->assertDispatched('show-clearance-receipt-modal');

        $this->posWithCart([$this->drops])->set('payments', [['method' => 'cash', 'amount' => 50]])
            ->call('checkout')
            ->assertDispatched('receipt-data-ready')
            ->assertSet('visitReceiptUrl', null);
    }

    public function test_with_the_setting_on_payments_print_nothing_until_the_visit_receipt(): void
    {
        $this->setVisitReceipts(true);

        $this->clearance()->call('createClearance', (string) $this->consult->id, json_encode([['method' => 'cash', 'amount' => 200]]))
            ->assertNotDispatched('show-clearance-receipt-modal')
            ->assertDispatched('notify');

        $component = $this->posWithCart([$this->drops])->set('payments', [['method' => 'cash', 'amount' => 50]])->call('checkout');
        $component->assertNotDispatched('receipt-data-ready');
        $this->assertStringContainsString('/cashier/visit-receipt/', (string) $component->get('visitReceiptUrl'));
    }

    public function test_visit_receipt_lists_every_item_and_payment_and_is_final_when_settled(): void
    {
        $this->setVisitReceipts(true);
        $clearance = $this->clear();
        $consultation = $this->consultationFor($clearance);
        $this->checkout([$this->drops, $this->frame], 350, $consultation->id);
        $visit = PatientVisit::where('clearance_id', $clearance->id)->firstOrFail();

        $this->get(route('cashier.visit-receipt.show', $visit))
            ->assertOk()
            ->assertSee($visit->visit_number)
            ->assertSee('VISIT RECEIPT · PAID IN FULL')
            ->assertSee('CONSULTATION &amp; SERVICES', false)
            ->assertSee('DRUGS')
            ->assertSee('FRAMES, LENSES &amp; OTHER ITEMS', false)
            ->assertSee('Ray-Ban frame')
            ->assertSee('550.00')
            ->assertDontSee('BALANCE DUE');
    }

    public function test_part_paid_visit_prints_an_interim_receipt_then_final_once_settled(): void
    {
        $this->setVisitReceipts(true);
        $sale = Sales::create(['user_id' => $this->cashier->id, 'patient_id' => $this->patient->id, 'business_line' => 'clinic',
            'transaction_id' => 'DEP-' . uniqid(), 'total_amount' => 300, 'amount_paid' => 100, 'payment_status' => 'partial', 'bill_status' => 'finalized']);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $this->frame->id, 'prescribed_quantity' => 1, 'dispensed_quantity' => 0,
            'selling_price' => 300, 'subtotal' => 300]);
        PaymentTransaction::create(['sale_id' => $sale->id, 'amount' => 100, 'payment_method' => 'momo', 'collected_by' => $this->cashier->id]);

        // Sales from before the setting join their visit on first print.
        $this->get(route('cashier.visit-receipt.sale', $sale->id))->assertRedirect();
        $visit = PatientVisit::findOrFail($sale->fresh()->patient_visit_id);
        $this->get(route('cashier.visit-receipt.show', $visit))
            ->assertSee('PART PAYMENT — BALANCE DUE')
            ->assertSee('(on hold)')
            ->assertSee('200.00');

        Livewire::test(OutstandingBalancesComponent::class)
            ->set('selectedSaleId', $sale->id)->set('collectAmount', 200)->set('paymentMethod', 'cash')
            ->call('collectPayment')
            ->assertDispatched('print-released-receipt', fn ($event, $params) => str_contains($params['url'], '/cashier/visit-receipt/sale/'));

        $this->get(route('cashier.visit-receipt.show', $visit))->assertSee('VISIT RECEIPT · PAID IN FULL');
    }

    public function test_settings_switch_and_closing_time_are_saved(): void
    {
        $this->cashier->assignRole('Super Admin');

        Livewire::test(ReceiptSettingsComponent::class)
            ->assertSet('visit_receipts_enabled', false)
            ->assertSet('closing_time', '17:00')
            ->set('visit_receipts_enabled', true)
            ->set('closing_time', '16:30')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(PatientVisits::enabled());
        $this->assertSame('16:30', Setting::getSettings()->fresh()->closing_time);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function setVisitReceipts(bool $on): void
    {
        Setting::getSettings()->update(['visit_receipts_enabled' => $on]);
    }

    /** The doctor's consultation for this clearance (its prescription cart carries it). */
    private function consultationFor(CashierPatientClearance $clearance): Consultations
    {
        return Consultations::create(['patient_id' => $this->patient->id, 'user_id' => $this->cashier->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Review']);
    }

    private function clearance()
    {
        return Livewire::test(CashierPatientClearanceComponent::class)
            ->set('patientClearanceId', $this->patient->id)
            ->set('patientName', $this->patient->name);
    }

    /** A paid clearance for the consultation service. */
    private function clear(): CashierPatientClearance
    {
        $this->clearance()->call('createClearance', (string) $this->consult->id, json_encode([['method' => 'cash', 'amount' => 200]]))
            ->assertHasNoErrors();

        return CashierPatientClearance::where('patient_id', $this->patient->id)->latest('id')->firstOrFail();
    }

    private function posWithCart(array $products, ?int $consultationId = null)
    {
        $cart = [];
        foreach ($products as $product) {
            $cart[$product->id] = ['product_id' => $product->id, 'quantity' => 1];
        }

        return Livewire::test(POSComponent::class)
            ->call('selectPatient', $this->patient->id)
            ->set('prescriptionConsultationId', $consultationId)
            ->set('cart', $cart);
    }

    private function checkout(array $products, float $pay, ?int $consultationId = null): Sales
    {
        $component = $this->posWithCart($products, $consultationId)->set('payments', [['method' => 'cash', 'amount' => $pay]]);
        $key = $component->get('checkoutIdempotencyKey');
        $component->call('checkout')->assertHasNoErrors();

        return Sales::where('idempotency_key', $key)->firstOrFail();
    }

    private function product(Category $category, float $price, string $name): Product
    {
        return Product::factory()->create([
            'user_id' => $this->cashier->id, 'category_id' => $category->id, 'name' => $name,
            'selling_price' => $price, 'cost_price' => round($price / 4, 2), 'quantity' => 20,
        ]);
    }
}
