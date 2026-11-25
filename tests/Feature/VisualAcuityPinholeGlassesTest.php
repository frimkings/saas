<?php

namespace Tests\Feature;

use App\Livewire\Doctor\PatientRecordsComponent;
use App\Models\{CashierPatientClearance, Consultations, Patient};
use App\Support\Clinical\VisitCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** 25 Nov 2026: VA is taken unaided, with a pinhole and with the patient's own glasses, per eye. */
class VisualAcuityPinholeGlassesTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    public function test_the_exam_shows_the_unaided_pinhole_glasses_grid_and_saves_all_six_values(): void
    {
        $this->ownerOn('clinic-starter');
        $this->signIn($this->staff('Doctor'));
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Kofi Asare', 'contact' => '0244000111', 'gender' => 'Male']);
        $clearance = CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = Consultations::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_id' => $clearance->id, 'chiefComplaint' => 'Blurred vision']);

        $diagnosis = \App\Models\Diagnosis::create(['name' => 'Myopia']);

        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'consultation')
            ->assertSeeHtml('class="exam-va-grid"')
            ->assertSeeInOrder(['Unaided', 'Pinhole', 'Glasses'])
            ->assertSeeHtml("exam['vaODph']")
            ->assertSeeHtml("exam['vaOScc']")
            ->set('state.vaOD6m', '6/18')->set('state.vaOS6m', '6/12')
            ->set('state.vaODph', '6/9')->set('state.vaOSph', '6/6')
            ->set('state.vaODcc', '6/12')->set('state.vaOScc', '6/9')
            ->set('selectedDiagnoses', [['id' => $diagnosis->id, 'name' => $diagnosis->name]])
            ->call('updateConsultation')
            ->assertHasNoErrors();

        $saved = $consultation->fresh();
        $this->assertSame(['6/18', '6/12', '6/9', '6/6', '6/12', '6/9'],
            [$saved->vaOD6m, $saved->vaOS6m, $saved->vaODph, $saved->vaOSph, $saved->vaODcc, $saved->vaOScc]);

        // The visit card lists each way the vision was taken.
        $rows = VisitCard::exam($saved)['rows'];
        $this->assertSame(['6/9', '6/6'], $rows['VA pinhole']);
        $this->assertSame(['6/12', '6/9'], $rows['VA with glasses']);
    }

    public function test_pinhole_and_glasses_rows_stay_off_the_visit_card_when_not_taken(): void
    {
        $this->ownerOn('clinic-starter');
        $consultation = new Consultations(['vaOD6m' => '6/6', 'vaOS6m' => '6/9']);

        $this->assertSame(['VA unaided'], array_keys(VisitCard::exam($consultation)['rows']));
    }
}
