<?php

namespace Tests\Feature;

use App\Livewire\Cashier\CashierPatientClearanceComponent;
use App\Livewire\Doctor\PatientRecordsComponent;
use App\Models\{CashierPatientClearance, Patient, Vital};
use App\Support\Feature;
use App\Services\LicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Patient vitals (BP, blood sugar, IOP) taken at check-in, from Standard up (23 Nov 2026):
 * reception records them, the doctor sees them in the consultation, and the IOP pre-fills the exam.
 */
class PatientVitalsTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\ClinicOnPlan;

    private function patient(): Patient
    {
        return Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Kofi Mensah', 'contact' => '0241110000', 'gender' => 'Male']);
    }

    private function clearance(Patient $patient): CashierPatientClearance
    {
        return CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'payment_status' => 'Paid', 'doctor_status' => false, 'clearance_date' => now()->toDateString()]);
    }

    public function test_starter_has_no_vitals_feature(): void
    {
        $this->ownerOn('clinic-starter');
        $this->assertFalse(LicenseService::has(Feature::VITALS));
        $patient = $this->patient();
        Livewire::test(CashierPatientClearanceComponent::class)->call('openVitals', $patient->id)->assertForbidden();
    }

    public function test_reception_records_vitals_and_they_flag_when_high(): void
    {
        $this->ownerOn('clinic-standard');
        $this->assertTrue(LicenseService::has(Feature::VITALS));
        $patient = $this->patient();
        $this->clearance($patient);

        Livewire::test(CashierPatientClearanceComponent::class)
            ->call('openVitals', $patient->id)
            ->set('vBpSys', 150)->set('vBpDia', 95)
            ->set('vSugar', 220)->set('vSugarType', 'random')->set('vSugarUnit', 'mg/dL')
            ->set('vIopOd', 18)->set('vIopOs', 24)
            ->call('saveVitals')->assertHasNoErrors()->assertDispatched('hide-vitals-modal');

        $vital = Vital::where('patient_id', $patient->id)->sole();
        $this->assertSame([150, 95, '220.00'], [$vital->bp_systolic, $vital->bp_diastolic, (string) $vital->blood_sugar]);
        $this->assertSame($this->clinic->id, $vital->clinic_id);
        $this->assertTrue($vital->bpHigh(), 'BP 150/95 is high');
        $this->assertTrue($vital->sugarHigh(), 'random sugar 220 is high');

        // A second open edits the same day's reading instead of adding another.
        Livewire::test(CashierPatientClearanceComponent::class)->call('openVitals', $patient->id)
            ->assertSet('vBpSys', 150)->set('vBpSys', 130)->call('saveVitals')->assertHasNoErrors();
        $this->assertSame(1, Vital::where('patient_id', $patient->id)->count());
        $this->assertSame(130, Vital::where('patient_id', $patient->id)->sole()->bp_systolic);
    }

    public function test_at_least_one_reading_is_required_and_mmol_guard(): void
    {
        $this->ownerOn('clinic-standard');
        $patient = $this->patient();

        Livewire::test(CashierPatientClearanceComponent::class)->call('openVitals', $patient->id)
            ->call('saveVitals')->assertHasErrors('vBpSys');
        $this->assertSame(0, Vital::count());

        // 220 "mmol/L" is really mg/dL: it is caught, not stored.
        Livewire::test(CashierPatientClearanceComponent::class)->call('openVitals', $patient->id)
            ->set('vSugar', 220)->set('vSugarUnit', 'mmol/L')->call('saveVitals')->assertHasErrors('vSugar');
        $this->assertSame(0, Vital::count());
    }

    public function test_iop_taken_at_check_in_prefills_a_fresh_consultation(): void
    {
        $this->ownerOn('clinic-standard');
        $patient = $this->patient();
        $clearance = $this->clearance($patient);
        Vital::create(['clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => $patient->id,
            'clearance_id' => $clearance->id, 'recorded_by' => auth()->id(), 'iop_od' => 16, 'iop_os' => 21, 'recorded_at' => now()]);

        $record = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance]);
        $this->assertSame('16', $record->get('state.IOPOD'));
        $this->assertSame('21', $record->get('state.IOPOS'));
    }
}
