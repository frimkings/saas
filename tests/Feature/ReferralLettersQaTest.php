<?php

namespace Tests\Feature;

use App\Livewire\Doctor\PatientRecordsComponent;
use App\Livewire\Doctor\ReferralComponent;
use App\Models\{Patient, Referral};
use App\Support\ClinicNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** The clinical letters walk-through (2026-10-25): referrals, medical reports and excuse duty letters. */
class ReferralLettersQaTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    private function patient(): Patient
    {
        return Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Esi Boateng', 'contact' => '0244000111', 'gender' => 'Female']);
    }

    private function referral(Patient $patient): Referral
    {
        return Referral::create(['letter_type' => 'referral', 'referred_by' => auth()->id(), 'patient_id' => $patient->id, 'patient_name' => $patient->name,
            'referral_to' => 'KATH Ophthalmology', 'referral_date' => today(), 'diagnosis' => json_encode(['Glaucoma suspect']), 'status' => 'pending']);
    }

    public function test_the_owner_menu_links_to_referrals(): void
    {
        $this->ownerOn('clinic-pro');

        // Any clinical page shows the owner's Clinical menu.
        $this->get(route('admin.diagnoses'))->assertOk()->assertSee(route('doctor.referrals'), false);
    }

    public function test_an_edit_keeps_the_doctor_as_author_is_audited_and_completed_letters_are_locked(): void
    {
        $this->ownerOn('clinic-pro');
        $doctor = $this->signIn($this->staff('Doctor'));
        $letter = $this->referral($this->patient());
        $owner = $this->signIn(\App\Models\User::role('Super Admin')->first());

        Livewire::test(ReferralComponent::class)->call('openEdit', $letter->id)
            ->set('reasonForReferral', 'Raised IOP')->call('save')->assertHasNoErrors();
        $letter->refresh();
        $this->assertSame($doctor->id, (int) $letter->referred_by);
        $this->assertSame($owner->id, (int) $letter->updated_by);
        $this->assertDatabaseHas('audit_trails', ['event' => 'referral.updated', 'auditable_id' => $letter->id]);

        Livewire::test(ReferralComponent::class)->call('updateStatus', $letter->id, 'nonsense')
            ->call('updateStatus', $letter->id, 'completed')
            ->call('openEdit', $letter->id)->assertSet('showModal', false);
        $this->assertSame('completed', $letter->fresh()->status);
    }

    public function test_the_printed_letter_shows_the_diagnosis_as_words_and_records_the_print(): void
    {
        $this->ownerOn('clinic-pro');
        $letter = $this->referral($this->patient());

        $this->get(route('doctor.referral.pdf', $letter))->assertOk()
            ->assertSee('Glaucoma suspect')->assertDontSee('["Glaucoma suspect"]', false)->assertDontSee('&amp;nbsp;', false);
        $this->assertNotNull($letter->fresh()->printed_at);
    }

    public function test_excuse_duty_starts_on_the_visit_and_lasts_at_most_the_limit_and_reports_say_something(): void
    {
        $this->ownerOn('clinic-pro');
        $patient = $this->patient();
        $page = Livewire::test(ReferralComponent::class)->call('openCreate', 'excuse_duty')->call('selectPatient', $patient->id)
            ->set('referralDate', today()->toDateString());

        $page->set('excuseFromDate', today()->subDays(5)->toDateString())->set('excuseToDate', today()->addDay()->toDateString())
            ->call('save')->assertHasErrors('excuseFromDate');
        $page->set('excuseFromDate', today()->toDateString())->set('excuseToDate', today()->addYears(2)->toDateString())
            ->call('save')->assertHasErrors('excuseToDate');
        $page->set('excuseToDate', today()->addDays(6)->toDateString())->call('save')->assertHasNoErrors();
        $this->assertSame(1, Referral::where('letter_type', 'excuse_duty')->count());

        Livewire::test(ReferralComponent::class)->call('openCreate', 'medical_report')->set('patientName', 'Walk-in Kwame')
            ->call('save')->assertHasErrors('clinicalFindings')
            ->set('clinicalFindings', 'Mild conjunctivitis OU')->call('save')->assertHasNoErrors();
    }

    public function test_the_urgent_referral_draft_saves_with_labelled_findings(): void
    {
        $this->ownerOn('clinic-pro');
        $records = new PatientRecordsComponent();
        $records->patient = $this->patient();
        $records->selectedDiagnoses = [];
        $records->state = ['IOPOD' => 40, 'IOPOS' => 16, 'corneaOD' => 'Oedema', 'fundusOS' => 'Normal'];

        $records->createUrgentReferralDraft();

        $draft = Referral::sole();
        $this->assertSame('pending', $draft->status);
        $this->assertSame('Cornea OD: Oedema', $draft->anterior_segment);
        $this->assertSame('Fundus OS: Normal', $draft->posterior_segment);
    }
}
