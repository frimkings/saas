<?php

namespace Tests\Feature;

use App\Livewire\Admin\ProductsComponent;
use App\Livewire\Doctor\PatientRecordsComponent;
use App\Models\{Cart, CashierPatientClearance, Category, Consultations, OutsidePrescription, Patient, Product};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** Drugs prescribed for the patient to buy at a pharmacy: not stocked, or out of stock. On every plan. */
class OutsidePrescriptionTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    private function drug(string $name, int $quantity): Product
    {
        $category = Category::firstOrCreate(['name' => 'Drugs'], ['type' => 'drug', 'user_id' => auth()->id()]);

        return Product::create(['user_id' => auth()->id(), 'name' => $name, 'category_id' => $category->id, 'batch_number' => 'DRG-' . random_int(1000, 9999),
            'manufacture_date' => now()->subYear()->toDateString(), 'expiry_date' => now()->addYears(2)->toDateString(),
            'quantity' => $quantity, 'cost_price' => 20, 'selling_price' => 45]);
    }

    /** A paid visit with its consultation, opened by a doctor. */
    private function visit(): array
    {
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Ama Mensah', 'contact' => '0244556677', 'gender' => 'Female']);
        $clearance = CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = Consultations::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_id' => $clearance->id, 'chiefComplaint' => 'Red eye']);

        return [$patient, $clearance, $consultation];
    }

    public function test_a_doctor_prescribes_out_of_stock_and_unstocked_drugs_to_buy_outside_and_prints_the_slip(): void
    {
        $this->ownerOn('clinic-starter');
        $this->signIn($this->staff('Doctor'));
        $timolol = $this->drug('Timolol 0.5% drops', 0);
        $cipro = $this->drug('Ciprofloxacin drops', 10);
        [$patient, $clearance, $consultation] = $this->visit();

        $file = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')
            ->set('productSearch', 'timolol')
            ->assertSee('Out of stock')->assertSee('to buy at a pharmacy')
            ->call('addOutsideDrug', $timolol->id)
            ->set('productSearch', 'Vitamin A capsules')
            ->call('addOutsideDrug')
            ->call('selectProduct', $cipro->id);

        $this->assertSame(['Timolol 0.5% drops', 'Vitamin A capsules'], collect($file->get('outsideDrugs'))->pluck('name')->all());

        // Frequency and duration are needed; the eye is not (capsules).
        $file->call('savePrescription');
        $this->assertSame(0, OutsidePrescription::count());

        $file->set('outsideDrugs.0.frequency', 'Twice Daily')->set('outsideDrugs.0.duration_value', 1)->set('outsideDrugs.0.duration_unit', 'months')
            ->set('outsideDrugs.1.eye', '')->set('outsideDrugs.1.frequency', 'Once Daily')->set('outsideDrugs.1.duration_value', 30)
            ->set('productsList.0.frequency', 'Four Times Daily')->set('productsList.0.duration_value', 7)
            ->call('savePrescription')
            ->assertSet('activeTab', 'prescription')->assertSee('Print slip');

        $this->assertDatabaseHas('outside_prescriptions', ['consultation_id' => $consultation->id, 'patient_id' => $patient->id, 'product_id' => $timolol->id,
            'name' => 'Timolol 0.5% drops', 'eye' => 'Both Eyes', 'frequency' => 'Twice Daily', 'duration_value' => 1, 'duration_unit' => 'months', 'branch_id' => $this->branch->id]);
        $this->assertDatabaseHas('outside_prescriptions', ['name' => 'Vitamin A capsules', 'product_id' => null, 'eye' => null, 'duration_value' => 30]);
        // Only the stocked drug goes to the pharmacy counter; nothing bought outside is billed or taken from stock.
        $this->assertSame([$cipro->id], Cart::where('consultation_id', $consultation->id)->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(0, (int) $timolol->fresh()->quantity);

        // Reopening the visit shows them again; removing one and saving deletes it.
        $reopened = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')
            ->assertSee('To buy at a pharmacy')->assertSee('Vitamin A capsules');
        $reopened->call('removeOutsideDrug', 1)->call('savePrescription');
        $this->assertSame(['Timolol 0.5% drops'], OutsidePrescription::pluck('name')->all());
        $this->assertSame(1, Cart::where('consultation_id', $consultation->id)->count(), 'the cart item is kept');

        // The slip lists what to buy outside first, then what was given here.
        $this->get(route('doctor.prescription.print', $consultation))->assertOk();
        $html = view('pdf.prescription-slip', ['consultation' => $consultation, 'patient' => $patient, 'doctor' => auth()->user(), 'settings' => null,
            'items' => Cart::where('consultation_id', $consultation->id)->with('product.category')->get(),
            'outsideItems' => $consultation->outsidePrescriptions()->get()])->render();
        $this->assertMatchesRegularExpression('/To buy at a pharmacy.*Timolol 0\.5% drops.*1 Months.*Given at the clinic.*Ciprofloxacin drops/s', $html);
        $this->assertStringContainsString('RX-' . $consultation->id, $html);
    }

    public function test_items_saved_with_a_new_consultation_stay_when_the_prescription_is_saved_after(): void
    {
        $this->ownerOn('clinic-starter');
        $this->signIn($this->staff('Doctor'));
        $cipro = $this->drug('Ciprofloxacin drops', 10);
        $tears = $this->drug('Artificial tears', 10);
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Kwame Asante', 'contact' => '0245556677', 'gender' => 'Male']);
        $clearance = CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid', 'doctor_status' => false]);
        $diagnosis = \App\Models\Diagnosis::create(['name' => 'Conjunctivitis']);

        $file = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])
            ->call('switchTab', 'prescription')->call('selectProduct', $cipro->id)
            ->set('productsList.0.frequency', 'Four Times Daily')->set('productsList.0.duration_value', 7)
            ->call('switchTab', 'consultation')->set('state.chiefComplaint', 'Red eye')
            ->call('addDiagnosis', $diagnosis->id, $diagnosis->name)
            ->call('createConsultation')
            ->assertSet('activeTab', 'prescription');
        $consultation = Consultations::where('clearance_id', $clearance->id)->sole();

        // The tab shows what was just saved, so adding to it keeps it.
        $this->assertSame([$cipro->id], collect($file->get('productsList'))->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        $file->call('selectProduct', $tears->id)
            ->set('productsList.1.frequency', 'Three Times Daily')->set('productsList.1.duration_value', 14)
            ->call('savePrescription');

        $this->assertEqualsCanonicalizing([$cipro->id, $tears->id],
            Cart::where('consultation_id', $consultation->id)->pluck('product_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_drug_cannot_be_put_on_the_outside_list_twice_or_with_no_name(): void
    {
        $this->ownerOn('clinic-starter');
        [, $clearance] = $this->visit();

        $file = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')
            ->set('productSearch', 'x')->call('addOutsideDrug')
            ->set('productSearch', 'Vitamin A')->call('addOutsideDrug')
            ->set('productSearch', 'vitamin a')->call('addOutsideDrug');

        $this->assertSame(['Vitamin A'], collect($file->get('outsideDrugs'))->pluck('name')->all());
    }

    public function test_add_again_puts_an_outside_drug_back_or_dispenses_it_once_in_stock(): void
    {
        $this->ownerOn('clinic-starter');
        $timolol = $this->drug('Timolol 0.5% drops', 0);
        [$patient, $earlierClearance, $earlier] = $this->visit();
        $earlierClearance->update(['clearance_date' => now()->subWeek()->toDateString()]);  // one clearance a day per patient
        $old = OutsidePrescription::create(['patient_id' => $patient->id, 'consultation_id' => $earlier->id, 'product_id' => $timolol->id, 'name' => 'Timolol 0.5% drops',
            'eye' => 'OD (Right Eye)', 'frequency' => 'Twice Daily', 'duration_value' => 2, 'duration_unit' => 'weeks']);
        $clearance = CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => $patient->id, 'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);

        $file = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')
            ->assertSee('Previous medications')->assertSee('OD (Right Eye) · Twice Daily · 2 weeks')
            ->call('repeatMedication', 'outside', $old->id);
        $drug = collect($file->get('outsideDrugs'))->sole();
        $this->assertSame(['Timolol 0.5% drops', 'OD (Right Eye)', 'Twice Daily', 2, 'weeks'], [$drug['name'], $drug['eye'], $drug['frequency'], (int) $drug['duration_value'], $drug['duration_unit']]);
        $this->assertSame([], $file->get('productsList'));

        $timolol->update(['quantity' => 5]);
        $stocked = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'prescription')
            ->call('repeatMedication', 'outside', $old->id);
        $item = collect($stocked->get('productsList'))->sole();
        $this->assertSame([$timolol->id, 'OD (Right Eye)', 'Twice Daily'], [(int) $item['product_id'], $item['eye'], $item['frequency']]);
        $this->assertSame([], $stocked->get('outsideDrugs'));
    }

    public function test_the_products_page_lists_what_doctors_prescribed_outside_most_first(): void
    {
        $this->ownerOn('clinic-starter');
        $timolol = $this->drug('Timolol 0.5% drops', 0);
        [$patient, , $consultation] = $this->visit();
        foreach (['Vitamin A capsules', 'vitamin a capsules', 'Timolol 0.5% drops'] as $i => $name) {
            OutsidePrescription::create(['patient_id' => $patient->id, 'consultation_id' => $consultation->id, 'name' => $name,
                'product_id' => $name === 'Timolol 0.5% drops' ? $timolol->id : null, 'frequency' => 'Once Daily', 'duration_value' => 5, 'duration_unit' => 'days']);
        }
        OutsidePrescription::create(['patient_id' => $patient->id, 'consultation_id' => $consultation->id, 'name' => 'Old drug', 'frequency' => 'Once Daily'])
            ->forceFill(['created_at' => now()->subDays(120)])->save();

        $page = Livewire::test(ProductsComponent::class)->assertSee('Prescribed but not in stock')->assertDontSee('Old drug');
        $rows = $page->viewData('prescribedOutside');
        $this->assertSame([2, 1], $rows->pluck('times')->map(fn ($t) => (int) $t)->all(), 'grouped whatever the case, most prescribed first');
        $this->assertStringContainsStringIgnoringCase('vitamin a capsules', $rows->first()->name);
        $page->assertSee('Yes, out of stock')->assertSee('Not stocked');
    }
}
