<?php

namespace Tests\Feature;

use App\Livewire\Doctor\PatientRecordsComponent;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** The refraction form picks one lens; changing it swaps the prescription line instead of adding another. */
class RefractionLensChoiceTest extends TestCase
{
    use GivesClinicsAccess, DatabaseTransactions;

    private Product $single;
    private Product $progressive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startOfflineTrial();
        $doctor = User::factory()->create();
        $this->actingAs($doctor);
        $lenses = Category::factory()->create(['user_id' => $doctor->id, 'name' => 'Lenses']);
        $this->single = Product::factory()->create(['user_id' => $doctor->id, 'category_id' => $lenses->id,
            'name' => 'SV BLUE BLOCK', 'selling_price' => 650, 'quantity' => 20]);
        $this->progressive = Product::factory()->create(['user_id' => $doctor->id, 'category_id' => $lenses->id,
            'name' => 'PROGRESSIVE BLUE AR', 'selling_price' => 1300, 'quantity' => 20]);
    }

    private function lines(PatientRecordsComponent $component): array
    {
        return collect($component->productsList)->mapWithKeys(fn ($item) => [$item['name'] => $item['quantity']])->all();
    }

    public function test_changing_the_lens_replaces_the_earlier_choice(): void
    {
        $component = new PatientRecordsComponent;

        $component->selectLensProduct($this->progressive->id);
        $component->selectLensProduct($this->single->id);

        $this->assertSame(['SV BLUE BLOCK' => 1], $this->lines($component));
        $this->assertSame($this->single->id, $component->refractionLensProductId);

        $component->selectLensProduct($this->single->id);
        $this->assertSame(['SV BLUE BLOCK' => 1], $this->lines($component), 'Picking the same lens again adds nothing.');

        $component->selectLensProduct('');
        $this->assertSame([], $this->lines($component), 'Choosing no lens removes it.');
    }

    public function test_a_lens_also_added_by_hand_keeps_the_hand_added_unit(): void
    {
        $component = new PatientRecordsComponent;
        $component->selectProduct($this->progressive->id);

        $component->selectLensProduct($this->progressive->id);
        $this->assertSame(['PROGRESSIVE BLUE AR' => 2], $this->lines($component));

        $component->selectLensProduct($this->single->id);
        $this->assertSame(['PROGRESSIVE BLUE AR' => 1, 'SV BLUE BLOCK' => 1], $this->lines($component));
    }

    /** 20 Nov 2026: the picked lens lived only on screen, so a reload lost it and the cashier never saw it. */
    public function test_saving_the_refraction_puts_the_lens_on_the_visit_for_the_cashier(): void
    {
        $doctor = auth()->user();
        $patient = \App\Models\Patient::createWithGeneratedPxNumber(['user_id' => $doctor->id, 'name' => 'Ama Owusu', 'contact' => '0241113333', 'gender' => 'Female']);
        $clearance = \App\Models\CashierPatientClearance::create(['user_id' => $doctor->id, 'patient_id' => $patient->id, 'payment_status' => 'Paid', 'doctor_status' => false, 'clearance_date' => now()->toDateString()]);
        $consultation = \App\Models\Consultations::create(['user_id' => $doctor->id, 'patient_id' => $patient->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Blurred distance vision']);

        $refraction = fn ($lens) => \Livewire\Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])
            ->set('consultationID', $consultation->id)->call('switchTab', 'refraction')
            ->set('state.subjective_od_sphere', '-1.00')->set('state.subjective_os_sphere', '-1.25')
            ->set('state.subjective_od_bcva', '6/6')->set('state.subjective_os_bcva', '6/6')
            ->set('state.dispensing_required', true)->set('state.pd', '62')->set('state.lensType', 'SV AR')
            ->call('selectLensProduct', $lens);

        $refraction($this->progressive->id)->call('saveRefraction')->assertHasNoErrors();
        $carts = fn () => \App\Models\Cart::where('consultation_id', $consultation->id)->pluck('product_id')->all();
        $this->assertSame([$this->progressive->id], $carts(), 'The lens is stored on the visit, not only on screen.');

        // Saved again with the same lens: no second line.
        $refraction($this->progressive->id)->call('saveRefraction');
        $this->assertCount(1, $carts());
        $this->assertEquals(1, \App\Models\Cart::where('consultation_id', $consultation->id)->sum('quantity'), 'Reopening and re-picking the same lens adds no second unit.');

        // Swapping the lens on the same screen replaces the saved line.
        $refraction($this->progressive->id)->call('saveRefraction')
            ->call('selectLensProduct', $this->single->id)->call('saveRefraction');
        $this->assertSame([$this->single->id], $carts());
    }
}
