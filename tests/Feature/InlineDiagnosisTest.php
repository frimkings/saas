<?php

namespace Tests\Feature;

use App\Livewire\Doctor\PatientRecordsComponent;
use App\Models\{CashierPatientClearance, Consultations, Diagnosis, Patient};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** 25 Nov 2026: doctors add a missing diagnosis, or fix a misspelt one, from the consultation itself. */
class InlineDiagnosisTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    private function openVisit()
    {
        $this->ownerOn('clinic-starter');
        $this->signIn($this->staff('Doctor'));
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Esi Boateng', 'contact' => '0244000222', 'gender' => 'Female']);
        $clearance = CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        Consultations::create(['user_id' => auth()->id(), 'patient_id' => $patient->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Growth on eye']);

        return Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'consultation');
    }

    public function test_a_doctor_adds_a_missing_diagnosis_and_it_is_selected(): void
    {
        $file = $this->openVisit()
            ->set('diagnosisSearch', 'Pinguecula')
            ->assertSeeHtml('Add "Pinguecula" as a new diagnosis')
            ->call('createDiagnosis')
            ->assertSet('diagnosisSearch', '');

        $diagnosis = Diagnosis::where('name', 'Pinguecula')->firstOrFail();
        $this->assertSame([['id' => $diagnosis->id, 'name' => 'Pinguecula']], $file->get('selectedDiagnoses'));

        // Same name in another case picks the existing one instead of a duplicate.
        $file->set('diagnosisSearch', 'pinguecula')->call('createDiagnosis');
        $this->assertSame(1, Diagnosis::where('name', 'like', 'pinguecula')->count());
        $this->assertCount(1, $file->get('selectedDiagnoses'));
    }

    public function test_enter_picks_the_only_match_and_adds_when_nothing_matches(): void
    {
        $file = $this->openVisit();
        $refractive = Diagnosis::create(['name' => 'Refractive Error']);

        $file->set('diagnosisSearch', 'refra')->assertSeeHtml('Add "refra" as a new diagnosis')->call('pickOrCreateDiagnosis');
        $this->assertSame([$refractive->id], array_column($file->get('selectedDiagnoses'), 'id'));

        $file->set('diagnosisSearch', 'Pterygium')->call('pickOrCreateDiagnosis');
        $this->assertTrue(Diagnosis::where('name', 'Pterygium')->exists());
        $this->assertCount(2, $file->get('selectedDiagnoses'));

        $file->set('diagnosisSearch', 'ab')->call('createDiagnosis')->assertHasErrors('diagnosisSearch');
    }

    public function test_a_doctor_renames_a_misspelt_diagnosis_everywhere_but_not_onto_an_existing_name(): void
    {
        $file = $this->openVisit();
        $typo = Diagnosis::create(['name' => 'Refractive Errror']);
        Diagnosis::create(['name' => 'Glaucoma']);
        $file->call('addDiagnosis', $typo->id, $typo->name);

        $file->call('renameDiagnosis', $typo->id, 'Refractive Error')->assertReturned(true);
        $this->assertSame('Refractive Error', $typo->fresh()->name);
        $this->assertSame('Refractive Error', $file->get('selectedDiagnoses')[0]['name']);

        $file->call('renameDiagnosis', $typo->id, 'Glaucoma')->assertReturned(false);
        $this->assertSame('Refractive Error', $typo->fresh()->name);
    }
}
