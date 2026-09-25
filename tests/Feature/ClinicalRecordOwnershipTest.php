<?php

namespace Tests\Feature;

use App\Livewire\Doctor\PatientRecordsComponent;
use App\Models\{CashierPatientClearance, Clinic, Consultations, Patient, User};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class ClinicalRecordOwnershipTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private function workspace(): array
    {
        config()->set('tenancy.enabled', true);
        $doctor = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Doctor', 'guard_name' => 'web']);
        $doctor->assignRole($role);
        $clinic = $this->activeClinic(['name' => 'Clinical Clinic', 'slug' => 'clinical-'.uniqid()]);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($doctor->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($doctor->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $doctor->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        app(TenantContext::class)->set($doctor, $clinic, $branch, [$branch->id]);
        $this->actingAs($doctor)->withSession([
            config('tenancy.session_keys.clinic') => $clinic->id,
            config('tenancy.session_keys.branch') => $branch->id,
        ]);

        return compact('doctor', 'clinic', 'branch');
    }

    private function patient(User $doctor, string $name, string $contact): array
    {
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $doctor->id, 'name' => $name, 'contact' => $contact, 'dob' => '1990-01-01', 'gender' => 'Male', 'address' => 'Accra']);
        $clearance = CashierPatientClearance::create(['user_id' => $doctor->id, 'patient_id' => $patient->id, 'payment_status' => 'Paid', 'doctor_status' => false, 'clearance_date' => now()->toDateString()]);
        $consultation = Consultations::create(['patient_id' => $patient->id, 'user_id' => $doctor->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Review']);
        return compact('patient', 'clearance', 'consultation');
    }

    public function test_patient_workspace_cannot_load_another_patients_consultation_in_same_branch(): void
    {
        ['doctor' => $doctor] = $this->workspace();
        ['clearance' => $firstClearance] = $this->patient($doctor, 'First Patient', '0200000001');
        ['consultation' => $foreignConsultation] = $this->patient($doctor, 'Second Patient', '0200000002');

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(PatientRecordsComponent::class, ['clearance' => $firstClearance])
            ->call('loadRefractionData', $foreignConsultation->id);
    }

    public function test_visit_summary_download_rejects_consultations_from_multiple_patients(): void
    {
        ['doctor' => $doctor] = $this->workspace();
        ['consultation' => $first] = $this->patient($doctor, 'First Patient', '0200000011');
        ['consultation' => $second] = $this->patient($doctor, 'Second Patient', '0200000012');

        $this->get(route('doctor.visit-summaries.download', ['ids' => $first->id.','.$second->id]))
            ->assertForbidden();
    }
}
