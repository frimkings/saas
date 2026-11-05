<?php

namespace Tests\Feature;

use App\Livewire\Admin\SettingsComponent;
use App\Models\{Clinic, Patient};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** Patient numbers: clinic initials, year and month, then that month's count (VS-2611-042). */
class PatientNumberFormatTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    private function register(string $name = 'Ama Mensah'): Patient
    {
        return Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => $name, 'contact' => '0244556677', 'gender' => 'Female']);
    }

    public function test_initials_are_suggested_from_the_clinic_name(): void
    {
        $this->assertSame('VS', Clinic::suggestPatientPrefix('Vision Space'));
        $this->assertSame('VYO', Clinic::suggestPatientPrefix('V.Y. Odei Eyecare Center'));
        $this->assertSame('BE', Clinic::suggestPatientPrefix('Bright Eyes'));
        $this->assertSame('NE', Clinic::suggestPatientPrefix('Nexus'));
        $this->assertSame('VEC', Clinic::suggestPatientPrefix('Vision Eye Clinic'));
        $this->assertSame('PX', Clinic::suggestPatientPrefix('123'));
    }

    public function test_numbers_count_up_within_the_month_start_again_next_month_and_skip_taken_ones(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 5));
        $this->ownerOn('clinic-starter'); // clinic "Bright Eyes"
        $period = '2611';

        $this->assertSame("BE-{$period}-001", $this->register()->pxnumber);
        $this->assertSame('BE', $this->clinic->fresh()->patient_prefix, 'the initials used are kept');

        // A number brought from other software that matches the next one is skipped, not reused.
        Patient::create(['user_id' => auth()->id(), 'name' => 'Imported', 'contact' => '0200000000', 'gender' => 'Male', 'pxnumber' => "BE-{$period}-002"]);
        $this->assertSame("BE-{$period}-003", $this->register('Kofi')->pxnumber);

        // Renaming the clinic does not change the numbers.
        $this->clinic->update(['name' => 'Sunrise Optical']);
        $this->assertSame("BE-{$period}-004", $this->register('Efua')->pxnumber);

        $this->travelTo(now()->setDate(2026, 12, 1));
        $this->assertSame('BE-2612-001', $this->register('Yaw')->pxnumber);

        // Each clinic has its own count.
        $this->assertSame(1, DB::table('patient_number_counters')->where('clinic_id', $this->clinic->id)->where('period', '2612')->value('last_number'));
    }

    public function test_the_owner_sets_the_initials_until_the_first_number_is_given_out(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 5));
        $this->ownerOn('clinic-starter');

        $page = Livewire::test(SettingsComponent::class)
            ->assertSet('patient_prefix', 'BE')->assertSee('BE-2611-001')->assertSeeHtml('data-tour="settings-patient-prefix"')
            ->set('patient_prefix', 'b1')->call('updateSettings')->assertHasErrors('patient_prefix')
            ->set('patient_prefix', 'bec')->call('updateSettings')->assertHasNoErrors();
        $this->assertSame('BEC', $this->clinic->fresh()->patient_prefix);

        $this->assertSame('BEC-2611-001', $this->register()->pxnumber);

        Livewire::test(SettingsComponent::class)->assertSee("can't change now", false)
            ->set('patient_prefix', 'ZZ')->call('updateSettings');
        $this->assertSame('BEC', $this->clinic->fresh()->patient_prefix, 'fixed once numbers are given out');
    }
}
