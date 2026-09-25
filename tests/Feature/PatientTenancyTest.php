<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\PatientDocument;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class PatientTenancyTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    public function test_existing_patient_schema_is_fully_backfilled(): void
    {
        $this->assertSame(0, DB::table('patients')->whereNull('clinic_id')->count());
        $this->assertSame(0, DB::table('patients')->whereNull('home_branch_id')->count());
    }

    public function test_patient_numbers_are_unique_per_clinic_and_queries_are_isolated(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        [$clinicA, $branchA] = $this->membership($user, 'Clinic A', 'clinic-a');
        [$clinicB, $branchB] = $this->membership($user, 'Clinic B', 'clinic-b');
        $context = app(TenantContext::class);

        $context->set($user, $clinicA, $branchA, [$branchA->id]);
        $patientA = Patient::factory()->create(['pxnumber' => 'PX-SHARED-26']);

        $context->set($user, $clinicB, $branchB, [$branchB->id]);
        $patientB = Patient::factory()->create(['pxnumber' => 'PX-SHARED-26']);

        $this->assertSame($clinicA->id, $patientA->clinic_id);
        $this->assertSame($branchA->id, $patientA->home_branch_id);
        $this->assertSame($clinicB->id, $patientB->clinic_id);
        $this->assertSame([$patientB->id], Patient::query()->pluck('id')->all());

        $context->set($user, $clinicA, $branchA, [$branchA->id]);
        $this->assertSame([$patientA->id], Patient::query()->pluck('id')->all());
    }

    public function test_generated_patient_number_is_assigned_to_current_clinic_and_branch(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        [$clinic, $branch] = $this->membership($user, 'Clinic A', 'clinic-a');
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);

        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id,
            'name' => 'Patient One',
            'contact' => '0200000000',
            'gender' => 'Other',
            'dob' => '2000-01-01',
            'address' => 'Test',
        ]);

        $this->assertSame($clinic->id, $patient->clinic_id);
        $this->assertSame($branch->id, $patient->home_branch_id);
        $this->assertStringStartsWith('PX-', $patient->pxnumber);
    }

    public function test_http_document_route_cannot_resolve_a_record_from_another_clinic(): void
    {
        config()->set('tenancy.enabled', true);
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        [$clinicA, $branchA] = $this->membership($userA, 'Clinic A', 'http-clinic-a');
        [$clinicB, $branchB] = $this->membership($userB, 'Clinic B', 'http-clinic-b');

        app(TenantContext::class)->set($userB, $clinicB, $branchB, [$branchB->id]);
        $patientB = Patient::factory()->create();
        $documentB = PatientDocument::create([
            'patient_id' => $patientB->id,
            'uploaded_by' => $userB->id,
            'document_type' => 'scan',
            'title' => 'Private clinic B scan',
            'file_path' => 'private/clinic-b-scan.pdf',
            'original_name' => 'scan.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($userA)->withSession([
            config('tenancy.session_keys.clinic') => $clinicA->id,
            config('tenancy.session_keys.branch') => $branchA->id,
        ])->get(route('patient-documents.show', $documentB->id))->assertNotFound();
    }

    private function membership(User $user, string $name, string $slug): array
    {
        $clinic = $this->activeClinic(['name' => $name, 'slug' => $slug]);
        $branch = $clinic->branches()->create([
            'code' => 'MAIN', 'name' => 'Main Branch', 'is_default' => true,
        ]);
        $clinic->users()->attach($user, ['status' => 'active']);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        return [$clinic, $branch];
    }
}
