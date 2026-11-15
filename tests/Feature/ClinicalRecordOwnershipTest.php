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

    public function test_the_medical_record_and_consultation_report_show_what_the_doctor_recorded(): void
    {
        ['doctor' => $doctor] = $this->workspace();
        ['patient' => $patient, 'clearance' => $clearance, 'consultation' => $consultation] = $this->patient($doctor, 'Record Patient', '0200000021');
        $consultation->update(['chiefComplaint' => 'Blurry vision at far', 'vaOD6m' => '6/12', 'vaOS6m' => '6/18', 'IOPOD' => '18.00', 'IOPOS' => '26.00',
            'corneaOD' => 'Clear', 'cdrOS' => '0.6', 'others' => 'Hypertensive', 'odq_details' => [['eye' => 'OU', 'symptom' => 'Headache', 'severity' => '++']]]);
        $diagnosis = \App\Models\Diagnosis::create(['name' => 'Primary Open Angle Glaucoma']);
        $consultation->diagnoses()->attach($diagnosis->id);

        // The full medical record (its preview is the same template as the PDF).
        $this->get(route('doctor.medical-record.preview', ['patient' => $patient, 'clearance' => $clearance]))->assertOk()
            ->assertSee('Blurry vision at far')->assertSee('6/18')->assertSee('26.00')->assertSee('Normal: Cornea.')->assertSee('0.6')
            ->assertSee('Hypertensive')->assertSee('Headache (OU, ++)')->assertSee('Primary Open Angle Glaucoma')
            ->assertSee('Visit history')->assertSee('Above 21 mmHg')->assertDontSee('ago</div>', false);

        // The PDFs download (one visit fits one A4 page).
        $this->get(route('doctor.medical-record.pdf', ['patient' => $patient, 'clearance' => $clearance]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('doctor.consultation.pdf', $consultation))->assertOk()->assertHeader('content-type', 'application/pdf');

        // The single-visit consultation report.
        $html = view('doctor.consultation-pdf', ['patient' => $patient, 'consultation' => $consultation->fresh()->load(['user', 'addenda.user', 'diagnoses', 'cartItems.product']),
            'refraction' => null, 'generatedAt' => now(), 'generatedBy' => $doctor, 'clinicSettings' => \App\Models\Setting::getSettings()])->render();
        foreach (['Blurry vision at far', '6/12', '18.00', 'Primary Open Angle Glaucoma'] as $text) $this->assertStringContainsString($text, $html);
        $this->assertStringNotContainsString('Not specified', $html);
    }

    public function test_a_visit_card_folds_normal_findings_and_keeps_abnormal_ones_eye_by_eye(): void
    {
        $visit = new Consultations(['vaOD6m' => '6/9', 'IOPOS' => '24', 'lidsOD' => 'Normal', 'lidsOS' => 'Normal', 'corneaOD' => 'Clear',
            'corneaOS' => 'Superficial punctate keratitis', 'fundusOD' => 'Normal', 'odq' => ['Itching']]);
        $exam = \App\Support\Clinical\VisitCard::exam($visit);

        $this->assertSame(['VA (6m)', 'IOP', 'Cornea'], array_keys($exam['rows']));
        $this->assertSame(['Clear', 'Superficial punctate keratitis'], $exam['rows']['Cornea']);
        $this->assertSame(['Lids', 'Fundus'], $exam['normal']);
        $this->assertTrue(\App\Support\Clinical\VisitCard::iopHigh('24'));
        $this->assertFalse(\App\Support\Clinical\VisitCard::iopHigh('21'));
        $this->assertSame('Itching', \App\Support\Clinical\VisitCard::symptoms($visit));
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
