<?php

namespace Tests\Feature;

use App\Livewire\Doctor\PatientRecordsComponent;
use App\Livewire\POSComponent;
use App\Models\{Cart, CashierPatientClearance, Category, Consultations, OutsidePrescription, Patient, Product, SaleItem, Sales};
use App\Support\Clinical\DrugInstructions;
use App\Support\Clinical\VisitCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** A note on each prescribed drug and a Taper tick (24 Nov 2026), carried to the counter, the sale and the slips. */
class DrugInstructionsTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    private function drug(string $name, int $quantity = 10): Product
    {
        $category = Category::firstOrCreate(['name' => 'Drugs'], ['type' => 'drug', 'user_id' => auth()->id()]);

        return Product::create(['user_id' => auth()->id(), 'name' => $name, 'category_id' => $category->id, 'batch_number' => 'DRG-' . random_int(1000, 9999),
            'manufacture_date' => now()->subYear()->toDateString(), 'expiry_date' => now()->addYears(2)->toDateString(),
            'quantity' => $quantity, 'cost_price' => 20, 'selling_price' => 45]);
    }

    private function visit(): array
    {
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Kofi Boateng', 'contact' => '0244112233', 'gender' => 'Male']);
        $clearance = CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = Consultations::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_id' => $clearance->id, 'chiefComplaint' => 'Post-op review']);

        return [$patient, $clearance, $consultation];
    }

    public function test_the_helper_cleans_appends_and_prints(): void
    {
        $this->assertNull(DrugInstructions::clean("   \n "));
        $this->assertSame('Shake well', DrugInstructions::clean("  Shake \n well "));
        $this->assertSame(DrugInstructions::MAX, mb_strlen(DrugInstructions::clean(str_repeat('a', 900))));
        $this->assertSame('Reduce weekly. Do not stop suddenly', DrugInstructions::append('Reduce weekly.', 'Do not stop suddenly'));
        $this->assertSame('Do not stop suddenly', DrugInstructions::append('Do not stop suddenly', 'Do not stop suddenly'));
        $this->assertSame('TAPER · Reduce weekly', DrugInstructions::line('Reduce weekly', true));
        $this->assertSame('TAPER', DrugInstructions::line(null, 1));
        $this->assertNull(DrugInstructions::line('', false));
    }

    public function test_a_doctor_marks_a_drug_for_tapering_with_its_steps_and_a_note_on_a_drug_bought_outside(): void
    {
        $this->ownerOn('clinic-starter');
        $this->signIn($this->staff('Doctor'));
        $pred = $this->drug('Prednisolone acetate 1% drops');
        [$patient, $clearance, $consultation] = $this->visit();

        $file = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')
            ->call('selectProduct', $pred->id)
            ->set('productSearch', 'Acetazolamide 250mg tablets')->call('addOutsideDrug')
            ->set('productsList.0.frequency', 'Four Times Daily')->set('productsList.0.duration_value', 4)->set('productsList.0.duration_unit', 'weeks')
            ->set('outsideDrugs.0.eye', '')->set('outsideDrugs.0.frequency', 'Four Times Daily')->set('outsideDrugs.0.duration_value', 5)
            ->assertSee('Quick notes')
            ->call('addInstructionChip', 'outside', 0, 4) // "Take after meals"
            ->set('productsList.0.taper', true);

        // Taper ticked with no steps written: not saved.
        $file->call('savePrescription');
        $this->assertSame(0, Cart::where('consultation_id', $consultation->id)->count());

        // The "Taper as directed" pick fills the note and ticks Taper; the doctor adds the steps.
        $file->set('productsList.0.taper', false)->call('addInstructionChip', 'products', 0, 0)
            ->assertSet('productsList.0.taper', true)
            ->set('productsList.0.instructions', "Taper as directed:  QDS 1 week, TDS 1 week,\n BD 1 week, OD 1 week, then stop")
            ->call('savePrescription');

        $cart = Cart::where('consultation_id', $consultation->id)->firstOrFail();
        $this->assertTrue($cart->taper);
        $this->assertSame('Taper as directed: QDS 1 week, TDS 1 week, BD 1 week, OD 1 week, then stop', $cart->instructions);
        $outside = OutsidePrescription::firstOrFail();
        $this->assertSame('Take after meals', $outside->instructions);
        $this->assertFalse($outside->taper);

        // Reopened, the note is back; the visit card prints it.
        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')
            ->assertSet('productsList.0.taper', true)->assertSee('TAPER')->assertSee('Take after meals');
        $medicines = VisitCard::prescribed($consultation->fresh()->load('cartItems.product'))['medicines'];
        $this->assertStringContainsString('TAPER · Taper as directed: QDS 1 week', $medicines[0]);

        // The drug slip for the pharmacy carries the outside drug's note.
        $html = view('pdf.slip.prescription-body', ['outsideItems' => OutsidePrescription::all(), 'consultation' => $consultation->load('user'),
            'patient' => $patient, 'settings' => \App\Models\Setting::getSettings(), 'doctor' => null, 'paper' => 'a5'])->render();
        $this->assertStringContainsString('Take after meals', $html);
    }

    public function test_add_again_brings_back_the_note_and_taper(): void
    {
        $this->ownerOn('clinic-starter');
        $this->signIn($this->staff('Doctor'));
        $pred = $this->drug('Prednisolone acetate 1% drops');
        [$patient, $clearance, $consultation] = $this->visit();
        $old = Cart::create(['patient_id' => $patient->id, 'dispensed_by' => auth()->id(), 'consultation_id' => $consultation->id, 'product_id' => $pred->id,
            'quantity' => 1, 'price' => 45, 'total' => 45, 'eye' => 'Right Eye', 'frequency' => 'Four Times Daily', 'duration_value' => 4, 'duration_unit' => 'weeks',
            'instructions' => 'Reduce by one drop a week', 'taper' => true, 'status' => 'completed', 'is_dispensed' => true, 'purchased' => true]);
        $old->forceFill(['created_at' => now()->subMonth()])->save();
        // A later visit by the same patient.
        $clearance->update(['clearance_date' => now()->subMonth()->toDateString()]);
        $clearance2 = CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        Consultations::create(['user_id' => auth()->id(), 'patient_id' => $patient->id, 'clearance_id' => $clearance2->id, 'chiefComplaint' => 'Review']);

        $file = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance2])->call('switchTab', 'prescription');
        $this->assertStringContainsString('TAPER · Reduce by one drop a week', $file->instance()->previousMedications->firstWhere('id', $old->id)['directions']);
        $file->call('repeatMedication', 'cart', $old->id)
            ->assertSet('productsList.0.instructions', 'Reduce by one drop a week')
            ->assertSet('productsList.0.taper', true);
    }

    public function test_the_note_reaches_the_pharmacy_counter_and_the_sale(): void
    {
        $this->ownerOn('clinic-starter');
        $this->signIn($this->staff('Cashier'));
        $pred = $this->drug('Prednisolone acetate 1% drops');
        [$patient, , $consultation] = $this->visit();
        Cart::create(['patient_id' => $patient->id, 'dispensed_by' => auth()->id(), 'consultation_id' => $consultation->id, 'product_id' => $pred->id,
            'quantity' => 1, 'price' => 45, 'total' => 45, 'eye' => 'Right Eye', 'frequency' => 'Four Times Daily', 'duration_value' => 4, 'duration_unit' => 'weeks',
            'instructions' => 'QDS 1 week, TDS 1 week, BD 1 week, OD 1 week', 'taper' => true, 'status' => 'pending', 'is_dispensed' => false, 'purchased' => false]);

        $pos = Livewire::test(POSComponent::class)->call('selectPatient', $patient->id)
            ->assertSee('TAPER · QDS 1 week, TDS 1 week');
        $key = $pos->get('checkoutIdempotencyKey');
        $pos->set('payments', [['method' => 'cash', 'amount' => 45]])->call('checkout')->assertHasNoErrors();

        $sale = Sales::where('idempotency_key', $key)->firstOrFail();
        $item = SaleItem::where('sale_id', $sale->id)->firstOrFail();
        $this->assertTrue($item->taper);
        $this->assertSame('QDS 1 week, TDS 1 week, BD 1 week, OD 1 week', $item->instructions);
    }
}
