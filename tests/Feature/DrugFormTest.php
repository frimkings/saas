<?php

namespace Tests\Feature;

use App\Enums\DosageForm;
use App\Livewire\Admin\ProductsComponent;
use App\Livewire\Doctor\PatientRecordsComponent;
use App\Livewire\POSComponent;
use App\Models\{Cart, CashierPatientClearance, Category, Consultations, OutsidePrescription, Patient, Product, SaleItem, Sales};
use App\Support\Clinical\DrugCourse;
use App\Support\Clinical\VisitCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** Drug form (24 Nov 2026): drops ask the eye; tablets ask the dose and meal timing, and are sold by the strip. */
class DrugFormTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    private function drug(string $name, array $extra = []): Product
    {
        $category = Category::firstOrCreate(['name' => 'Drugs'], ['type' => 'drug', 'user_id' => auth()->id()]);

        return Product::create($extra + ['user_id' => auth()->id(), 'name' => $name, 'category_id' => $category->id, 'batch_number' => 'DRG-' . random_int(1000, 9999),
            'manufacture_date' => now()->subYear()->toDateString(), 'expiry_date' => now()->addYears(2)->toDateString(),
            'quantity' => 50, 'cost_price' => 20, 'selling_price' => 30]);
    }

    private function visit(): array
    {
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Esi Owusu', 'contact' => '0244998877', 'gender' => 'Female']);
        $clearance = CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = Consultations::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_id' => $clearance->id, 'chiefComplaint' => 'Raised IOP']);

        return [$patient, $clearance, $consultation];
    }

    public function test_the_form_is_guessed_from_the_name_and_the_course_is_counted_in_strips(): void
    {
        $this->assertSame(DosageForm::EYE_DROPS, DosageForm::guess('Timolol 0.5% eye drops'));
        $this->assertSame(DosageForm::EYE_DROPS, DosageForm::guess('BLuCipro (Ciprofloxacin 0.3%)'));
        $this->assertSame(DosageForm::EYE_DROPS, DosageForm::guess('Maxitrol ophthalmic suspension'));
        $this->assertSame(DosageForm::EYE_OINTMENT, DosageForm::guess('Epiciprin Oc'));
        $this->assertSame(DosageForm::TABLET, DosageForm::guess('Diamox 250mg tab'));
        $this->assertSame(DosageForm::TABLET, DosageForm::guess('Acetazolamide 250mg'));
        $this->assertSame(DosageForm::CAPSULE, DosageForm::guess('Amoxicillin caps 500mg'));
        $this->assertSame(DosageForm::SYRUP, DosageForm::guess('Paracetamol syrup'));
        $this->assertSame(DosageForm::INJECTION, DosageForm::guess('Avastin inj'));
        $this->assertNull(DosageForm::guess('Pred Forte'));

        $line = ['dosage_form' => 'tablet', 'dose' => 1, 'frequency' => 'Four Times Daily', 'duration_value' => 5, 'duration_unit' => 'days', 'meal_timing' => 'after_meals', 'eye' => 'Both Eyes'];
        $this->assertSame(20, DrugCourse::units($line));
        $this->assertSame(2, DrugCourse::strips($line, 10));
        $this->assertSame(3, DrugCourse::strips($line, 8), 'rounded up to whole strips');
        $this->assertNull(DrugCourse::strips($line, null));
        $this->assertSame('1 tablet · Four Times Daily · after meals · 5 days', DrugCourse::directions($line), 'no eye for a tablet');
        $this->assertSame(14, DrugCourse::units(['dosage_form' => 'tablet', 'dose' => 1, 'frequency' => 'Twice Daily', 'duration_value' => 1, 'duration_unit' => 'weeks']));
        $this->assertNull(DrugCourse::units(['dosage_form' => 'tablet', 'dose' => 1, 'frequency' => 'As Needed (PRN)', 'duration_value' => 5, 'duration_unit' => 'days']));
        $this->assertSame('Both Eyes · Twice Daily · 1 months', DrugCourse::directions(['dosage_form' => 'eye_drops', 'eye' => 'Both Eyes', 'frequency' => 'Twice Daily', 'duration_value' => 1, 'duration_unit' => 'months']));
    }

    public function test_the_products_page_sets_the_form_and_strip_size_and_lists_drugs_with_no_form(): void
    {
        $this->ownerOn('clinic-pro');
        $drugs = Category::create(['name' => 'Drugs', 'type' => 'drug', 'user_id' => auth()->id()]);
        $frames = Category::create(['name' => 'Frames', 'type' => 'product', 'user_id' => auth()->id()]);

        Livewire::test(ProductsComponent::class)->set('showForm', true)
            ->set('state.category_id', $drugs->id)->set('state.name', 'Diamox 250mg tab')
            ->assertSet('state.dosage_form', 'tablet')->assertSee('Tablets per strip')
            ->set('state.pack_size', 10)->set('state.batch_number', 'DMX001')->set('state.quantity', 30)
            ->set('state.manufacture_date', now()->subYear()->toDateString())->set('state.expiry_date', now()->addYear()->toDateString())
            ->set('state.cost_price', 20)->set('state.selling_price', 30)
            ->call('createProduct')->assertHasNoErrors();
        $diamox = Product::where('name', 'Diamox 250mg tab')->sole();
        $this->assertSame(DosageForm::TABLET, $diamox->dosage_form);
        $this->assertSame(10, $diamox->pack_size);

        // A frame has no form.
        Livewire::test(ProductsComponent::class)->set('showForm', true)->set('state.category_id', $frames->id)->assertDontSee('Tablets per strip')->assertDontSee('products-state-dosage-form');

        // Drugs whose names do not say their form are listed, and set in bulk.
        $blutimol = $this->drug('Blutimol');
        $epifenac = $this->drug('Epifenac');
        $this->assertNull($blutimol->dosage_form);
        Livewire::test(ProductsComponent::class)->set('activeTab', 'no-form')
            ->assertSee('Drugs with no form')->assertSee('Blutimol')->assertDontSee('Diamox 250mg tab')
            ->set('selectedProductIds', [$blutimol->id, $epifenac->id])
            ->call('setFormForSelected', 'eye_drops');
        $this->assertSame(DosageForm::EYE_DROPS, $blutimol->fresh()->dosage_form);
        $this->assertSame(DosageForm::EYE_DROPS, $epifenac->fresh()->dosage_form);
    }

    public function test_a_tablet_line_asks_no_eye_and_works_out_the_strips(): void
    {
        $this->ownerOn('clinic-starter');
        $this->signIn($this->staff('Doctor'));
        $diamox = $this->drug('Diamox 250mg tab', ['pack_size' => 10]);
        $timolol = $this->drug('Timolol 0.5% eye drops');
        [, $clearance, $consultation] = $this->visit();

        $file = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')
            ->call('selectProduct', $diamox->id)->call('selectProduct', $timolol->id)
            ->assertSet('productsList.0.eye', null)->assertSet('productsList.0.dose', 1.0)
            ->assertSet('productsList.1.eye', 'Both Eyes')
            ->assertSee('By mouth')
            ->call('updateFrequency', 0, 'Four Times Daily')->set('productsList.0.duration_value', 5)
            ->assertSet('productsList.0.quantity', 2)
            ->set('productsList.0.dose', 2)->assertSet('productsList.0.quantity', 4)
            ->set('productsList.0.meal_timing', 'after_meals');

        // A quantity the doctor types is kept.
        $file->call('updateProductQuantity', 0, 3)->set('productsList.0.duration_value', 10)->assertSet('productsList.0.quantity', 3);

        // A course needing more strips than in stock is limited to the stock.
        $file->call('updateFrequency', 1, 'Twice Daily')->set('productsList.1.duration_value', 1)->set('productsList.1.duration_unit', 'months')
            ->set('productsList.0.dose', '')->call('savePrescription');
        $this->assertSame(0, Cart::where('consultation_id', $consultation->id)->count(), 'a tablet needs its dose');

        $file->set('productsList.0.dose', 2)->call('savePrescription');
        $tablet = Cart::where('consultation_id', $consultation->id)->where('product_id', $diamox->id)->sole();
        $this->assertNull($tablet->eye);
        $this->assertSame('tablet', $tablet->dosage_form);
        $this->assertSame(2.0, (float) $tablet->dose);
        $this->assertSame('after_meals', $tablet->meal_timing);
        $this->assertSame(3, (int) $tablet->quantity);
        $drops = Cart::where('consultation_id', $consultation->id)->where('product_id', $timolol->id)->sole();
        $this->assertSame('Both Eyes', $drops->eye);
        $this->assertNull($drops->dose);

        $medicines = VisitCard::prescribed($consultation->fresh()->load('cartItems.product'))['medicines'];
        $this->assertStringContainsString('Diamox 250mg tab ×3 strips — 2 tablets · four times daily · after meals · 10 days', implode("\n", $medicines));
    }

    public function test_the_doctor_sets_the_form_of_a_drug_that_has_none_and_the_product_keeps_it(): void
    {
        $this->ownerOn('clinic-starter');
        $this->signIn($this->staff('Doctor'));
        $vit = $this->drug('Vitamin A', ['pack_size' => null]);
        [, $clearance] = $this->visit();

        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')
            ->call('selectProduct', $vit->id)->assertSet('productsList.0.eye', 'Both Eyes')->assertSee('Form?')
            ->call('setLineForm', 'products', 0, 'capsule')
            ->assertSet('productsList.0.eye', null)->assertSet('productsList.0.dose', 1.0)->assertSee('strip size not set');
        $this->assertSame(DosageForm::CAPSULE, $vit->fresh()->dosage_form);

        // A form the clinic set is not overwritten from a prescription.
        $vit->update(['dosage_form' => DosageForm::TABLET]);
        [, $clearance2] = [null, CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => Patient::first()->id,
            'clearance_date' => now()->addDay()->toDateString(), 'payment_status' => 'Paid'])];
        Consultations::create(['user_id' => auth()->id(), 'patient_id' => Patient::first()->id, 'clearance_id' => $clearance2->id, 'chiefComplaint' => 'Review']);
        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance2])->call('switchTab', 'prescription')
            ->call('selectProduct', $vit->id)->call('setLineForm', 'products', 0, 'capsule');
        $this->assertSame(DosageForm::TABLET, $vit->fresh()->dosage_form);
    }

    public function test_a_tablet_bought_outside_prints_its_dose_and_total_on_the_slip(): void
    {
        $this->ownerOn('clinic-starter');
        $this->signIn($this->staff('Doctor'));
        [$patient, $clearance, $consultation] = $this->visit();

        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')
            ->set('productSearch', 'Acetazolamide 250mg tablets')->call('addOutsideDrug')
            ->assertSet('outsideDrugs.0.dosage_form', 'tablet')->assertSet('outsideDrugs.0.eye', null)
            ->set('outsideDrugs.0.frequency', 'Four Times Daily')->set('outsideDrugs.0.duration_value', 5)
            ->set('outsideDrugs.0.meal_timing', 'after_meals')
            ->call('savePrescription');

        $rx = OutsidePrescription::sole();
        $this->assertSame('tablet', $rx->dosage_form);
        $this->assertNull($rx->eye);
        $html = view('pdf.slip.prescription-body', ['outsideItems' => OutsidePrescription::all(), 'consultation' => $consultation->load('user'),
            'patient' => $patient, 'settings' => \App\Models\Setting::getSettings(), 'doctor' => null, 'paper' => 'a5'])->render();
        $this->assertStringContainsString('1 tablet · four times daily · after meals · 5 days', $html);
        $this->assertStringContainsString('Total 20 tablets', $html);
    }

    public function test_the_guess_command_fills_only_drugs_whose_name_says_and_only_with_commit(): void
    {
        $this->ownerOn('clinic-starter');
        $diamox = $this->drug('Diamox 250mg tab');
        $blutimol = $this->drug('Blutimol');
        $diamox->forceFill(['dosage_form' => null])->save();
        $set = $this->drug('Pred Forte', ['dosage_form' => DosageForm::EYE_DROPS]);

        $this->artisan('products:guess-dosage-forms')->assertSuccessful();
        $this->assertNull($diamox->fresh()->dosage_form);

        $this->artisan('products:guess-dosage-forms', ['--commit' => true])->assertSuccessful();
        $this->assertSame(DosageForm::TABLET, $diamox->fresh()->dosage_form);
        $this->assertNull($blutimol->fresh()->dosage_form);
        $this->assertSame(DosageForm::EYE_DROPS, $set->fresh()->dosage_form);
    }

    public function test_the_dose_and_meal_timing_reach_the_counter_and_the_sale(): void
    {
        $this->ownerOn('clinic-starter');
        $this->signIn($this->staff('Cashier'));
        $diamox = $this->drug('Diamox 250mg tab', ['pack_size' => 10]);
        [$patient, , $consultation] = $this->visit();
        Cart::create(['patient_id' => $patient->id, 'dispensed_by' => auth()->id(), 'consultation_id' => $consultation->id, 'product_id' => $diamox->id,
            'quantity' => 2, 'price' => 30, 'total' => 60, 'eye' => null, 'frequency' => 'Four Times Daily', 'duration_value' => 5, 'duration_unit' => 'days',
            'dosage_form' => 'tablet', 'dose' => 1, 'meal_timing' => 'after_meals', 'status' => 'pending', 'is_dispensed' => false, 'purchased' => false]);

        $pos = Livewire::test(POSComponent::class)->call('selectPatient', $patient->id)->assertSee('1 tablet · After meals');
        $key = $pos->get('checkoutIdempotencyKey');
        $pos->set('payments', [['method' => 'cash', 'amount' => 60]])->call('checkout')->assertHasNoErrors();

        $item = SaleItem::where('sale_id', Sales::where('idempotency_key', $key)->value('id'))->sole();
        $this->assertSame('tablet', $item->dosage_form);
        $this->assertSame(1.0, (float) $item->dose);
        $this->assertSame('after_meals', $item->meal_timing);
    }
}
