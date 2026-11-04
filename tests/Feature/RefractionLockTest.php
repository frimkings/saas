<?php

namespace Tests\Feature;

use App\Livewire\Doctor\PatientRecordsComponent;
use App\Models\{CashierPatientClearance, Consultations, Patient, RefractionCorrection, Refractions, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/**
 * Who may change a refraction: anyone adds one where there is none (under their name); its author edits
 * it freely for 24 hours; after that, or for anyone else, a change is a correction with a reason that
 * keeps the old values.
 */
class RefractionLockTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    private function visitBy(User $doctor): array
    {
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $doctor->id, 'name' => 'Ama Mensah', 'contact' => '0244556677', 'gender' => 'Female']);
        $clearance = CashierPatientClearance::create(['user_id' => $doctor->id, 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = Consultations::create(['user_id' => $doctor->id, 'patient_id' => $patient->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Blurred vision']);

        return [$clearance, $consultation];
    }

    private function refraction(Consultations $consultation, User $by, array $extra = []): Refractions
    {
        // The older text columns too, as the app's own save fills them.
        return Refractions::create(array_merge(['consultation_id' => $consultation->id, 'user_id' => $by->id,
            'subjective_od_sphere' => -1.00, 'subjective_od_bcva' => '6/6', 'subjective_os_sphere' => -0.50, 'subjective_os_bcva' => '6/6',
            'refractionOD' => '-1.00 DS', 'refractionOS' => '-0.50 DS', 'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6'], $extra));
    }

    private function named(string $role, string $name): User
    {
        $user = $this->staff($role);
        $user->forceFill(['name' => $name])->save();

        return $user;
    }

    public function test_any_doctor_adds_the_refraction_when_there_is_none_under_their_own_name(): void
    {
        $this->ownerOn('clinic-starter');
        $author = $this->named('Doctor', 'Dr Kofi Owusu');
        [$clearance, $consultation] = $this->visitBy($author);
        $optometrist = $this->signIn($this->named('Doctor', 'Dr Esi Boateng'));

        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'refraction')
            ->assertDontSee('View only.')->assertDontSee('saved as a correction')->assertSee('Save Refraction')
            ->set('state.subjective_od_sphere', -1.25)->set('state.subjective_od_bcva', '6/6')
            ->set('state.subjective_os_sphere', -0.75)->set('state.subjective_os_bcva', '6/6')
            ->call('saveRefraction')->assertHasNoErrors();

        $refraction = Refractions::where('consultation_id', $consultation->id)->sole();
        $this->assertSame($optometrist->id, (int) $refraction->user_id);
        $this->assertSame(0, RefractionCorrection::count());
    }

    public function test_the_author_edits_freely_within_a_day_and_stays_the_author(): void
    {
        $this->ownerOn('clinic-starter');
        $doctor = $this->signIn($this->staff('Doctor'));
        [$clearance, $consultation] = $this->visitBy($doctor);
        $this->refraction($consultation, $doctor);

        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'refraction')
            ->assertDontSee('saved as a correction')->assertSee('Save Refraction')
            ->set('state.subjective_od_sphere', -1.25)->call('saveRefraction')->assertHasNoErrors();

        $this->assertSame('-1.25', Refractions::sole()->subjective_od_sphere);
        $this->assertSame(0, RefractionCorrection::count());
    }

    public function test_after_a_day_the_author_corrects_with_a_reason_and_the_old_values_are_kept(): void
    {
        // Pro has the audit trail; the correction history itself is kept on every plan.
        $this->ownerOn('clinic-pro');
        $doctor = $this->signIn($this->named('Doctor', 'Dr Kofi Owusu'));
        [$clearance, $consultation] = $this->visitBy($doctor);
        $consultation->forceFill(['created_at' => now()->subDays(3)])->save();
        $refraction = $this->refraction($consultation, $doctor, ['subjective_od_cylinder' => -0.50, 'subjective_od_axis' => 90]);
        $refraction->forceFill(['created_at' => now()->subDays(3)])->save();

        $page = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'refraction')
            ->assertSee('Changes are saved as a correction.')->assertSee('Saved by Dr Kofi Owusu')->assertSee('Save correction')
            ->set('state.subjective_od_cylinder', -0.75)
            ->call('saveRefraction')->assertHasErrors('refractionCorrectionReason');
        $this->assertSame('-0.50', $refraction->fresh()->subjective_od_cylinder, 'not saved without a reason');

        $page->set('refractionCorrectionReason', 'OD cylinder entered as -0.50, measured -0.75')->call('saveRefraction')->assertHasNoErrors();

        $correction = RefractionCorrection::sole();
        $this->assertSame(['subjective_od_cylinder' => '-0.50'], $correction->old_values);
        $this->assertSame(['subjective_od_cylinder' => '-0.75'], $correction->new_values);
        $this->assertSame($doctor->id, (int) $correction->user_id);
        $this->assertSame(['OD cylinder: -0.50 → -0.75'], $correction->changes());
        $this->assertSame('-0.75', $refraction->fresh()->subjective_od_cylinder);
        $this->assertDatabaseHas('audit_trails', ['event' => 'refraction.corrected', 'auditable_id' => $refraction->id]);

        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'refraction')
            ->assertSee('Correction history')->assertSee('OD cylinder entered as -0.50, measured -0.75')->assertSee('OD cylinder: -0.50 → -0.75');
    }

    public function test_another_doctor_corrects_someone_elses_refraction_and_the_author_is_kept(): void
    {
        $this->ownerOn('clinic-starter');
        $author = $this->named('Doctor', 'Dr Kofi Owusu');
        [$clearance, $consultation] = $this->visitBy($author);
        $refraction = $this->refraction($consultation, $author);
        $other = $this->signIn($this->staff('Doctor'));

        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'refraction')
            ->assertSee('Changes are saved as a correction.')->assertSee('Saved by Dr Kofi Owusu')
            ->set('state.subjective_os_sphere', -0.75)->set('refractionCorrectionReason', 'Rechecked OS at the follow-up')
            ->call('saveRefraction')->assertHasNoErrors();

        $this->assertSame($author->id, (int) $refraction->fresh()->user_id);
        $this->assertSame($other->id, (int) RefractionCorrection::sole()->user_id);
    }

    public function test_a_correction_that_changes_nothing_is_not_saved(): void
    {
        $this->ownerOn('clinic-starter');
        $author = $this->staff('Doctor');
        [$clearance, $consultation] = $this->visitBy($author);
        $this->refraction($consultation, $author);
        $this->signIn($this->staff('Doctor'));

        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])->call('switchTab', 'refraction')
            ->set('refractionCorrectionReason', 'Just checking')->call('saveRefraction')
            ->assertDispatched('notify', type: 'info', message: 'Nothing was changed, so no correction was saved.');
        $this->assertSame(0, RefractionCorrection::count());
    }
}
