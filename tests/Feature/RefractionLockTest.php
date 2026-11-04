<?php

namespace Tests\Feature;

use App\Livewire\Doctor\PatientRecordsComponent;
use App\Models\{CashierPatientClearance, Consultations, Patient};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** A locked refraction says why before anything is typed, and the refusal on save gives the same reason. */
class RefractionLockTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    private function visitBy(int $doctorId): CashierPatientClearance
    {
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $doctorId, 'name' => 'Ama Mensah', 'contact' => '0244556677', 'gender' => 'Female']);
        $clearance = CashierPatientClearance::create(['user_id' => $doctorId, 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        Consultations::create(['user_id' => $doctorId, 'patient_id' => $patient->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Blurred vision']);

        return $clearance;
    }

    public function test_another_doctors_consultation_is_view_only_and_names_its_doctor(): void
    {
        $this->ownerOn('clinic-starter');
        $author = $this->staff('Doctor');
        $author->forceFill(['name' => 'Dr Kofi Owusu'])->save();
        $clearance = $this->visitBy($author->id);
        $this->signIn($this->staff('Doctor'));

        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'refraction')
            ->assertSee('View only.')->assertSee('Only the doctor who created this consultation (Dr Kofi Owusu) can edit its refraction.')
            ->assertSee('Refraction locked')->assertDontSee('Save Refraction')
            ->call('saveRefraction')
            ->assertDispatched('notify', type: 'error', message: 'Only the doctor who created this consultation (Dr Kofi Owusu) can edit its refraction.');
    }

    public function test_own_consultation_older_than_a_day_says_it_is_locked_by_age(): void
    {
        $this->ownerOn('clinic-starter');
        $doctor = $this->signIn($this->staff('Doctor'));
        $clearance = $this->visitBy($doctor->id);
        Consultations::where('clearance_id', $clearance->id)->sole()->forceFill(['created_at' => now()->subDays(2)])->save();

        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'refraction')
            ->assertSee('more than 24 hours old')->assertDontSee('Only the doctor who created')
            ->call('saveRefraction')
            ->assertDispatched('notify', type: 'error', message: 'This consultation is more than 24 hours old, so its refraction is locked. Start a new visit to record a new refraction.');
    }

    public function test_own_fresh_consultation_has_no_lock(): void
    {
        $this->ownerOn('clinic-starter');
        $doctor = $this->signIn($this->staff('Doctor'));
        $clearance = $this->visitBy($doctor->id);

        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'refraction')
            ->assertDontSee('View only.')->assertSee('Save Refraction');
    }
}
