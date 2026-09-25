<?php

namespace Tests\Feature;

use App\Livewire\Secretary\PatientsComponent;
use App\Models\{Branch, Clinic, Insurer, Patient, User};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class PatientRegistryWorkflowTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private function context(User $user, string $slug): array
    {
        $clinic = $this->activeClinic(['name' => $slug, 'slug' => $slug]);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);
        return compact('clinic', 'branch');
    }

    private function secretary(): User
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Secretary', 'guard_name' => 'web']));
        $this->actingAs($user);
        return $user;
    }

    public function test_registration_assigns_active_clinic_branch_and_generated_patient_number(): void
    {
        $user = $this->secretary();
        ['clinic' => $clinic, 'branch' => $branch] = $this->context($user, 'registry-create');

        Livewire::test(PatientsComponent::class)
            ->set('nameSearch', 'Ama Registry')
            ->set('dobDisplay', '15/06/95')
            ->set('state.contact', '0244000111')
            ->set('state.email', 'ama@example.test')
            ->set('state.gender', 'Female')
            ->set('state.address', 'Kumasi')
            ->call('saveEntry')
            ->assertHasNoErrors();

        $patient = Patient::where('contact', '0244000111')->firstOrFail();
        $this->assertSame($clinic->id, $patient->clinic_id);
        $this->assertSame($branch->id, $patient->home_branch_id);
        $this->assertStringStartsWith('PX-', $patient->pxnumber);
    }

    public function test_forged_cross_clinic_patient_id_cannot_be_edited(): void
    {
        $user = $this->secretary();
        ['clinic' => $first, 'branch' => $firstBranch] = $this->context($user, 'registry-first');
        ['clinic' => $second] = $this->context($user, 'registry-second');
        $foreign = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Foreign Patient', 'contact' => '0200000000', 'dob' => '1990-01-01', 'gender' => 'Male', 'address' => 'Accra']);
        app(TenantContext::class)->set($user, $first, $firstBranch, [$firstBranch->id]);

        Livewire::test(PatientsComponent::class)
            ->call('edit', $foreign->id)
            ->assertSet('isEditing', false)
            ->assertSet('formMessage', 'This patient record is no longer available. Refresh the list and try again.');
    }

    public function test_insurer_id_must_belong_to_active_clinic(): void
    {
        $user = $this->secretary();
        ['clinic' => $first, 'branch' => $firstBranch] = $this->context($user, 'registry-insurer-first');
        $this->context($user, 'registry-insurer-second');
        $foreignInsurer = Insurer::create(['name' => 'Foreign Cover', 'code' => 'FOREIGN', 'active' => true]);
        app(TenantContext::class)->set($user, $first, $firstBranch, [$firstBranch->id]);

        Livewire::test(PatientsComponent::class)
            ->set('paymentType', 'insurance')
            ->set('nameSearch', 'Covered Patient')
            ->set('dobDisplay', '15/06/95')
            ->set('state.contact', '0244000222')
            ->set('state.gender', 'Female')
            ->set('state.address', 'Kumasi')
            ->set('state.insurer_id', $foreignInsurer->id)
            ->set('state.insurance_member_id', 'MEM-1')
            ->call('saveEntry')
            ->assertHasErrors(['insurer_id']);

        $this->assertDatabaseMissing('patients', ['clinic_id' => $first->id, 'contact' => '0244000222']);
    }
}
