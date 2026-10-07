<?php

namespace Tests\Feature;

use App\Livewire\Doctor\PatientRecordsComponent;
use App\Models\{CashierPatientClearance, Patient, PatientDocument, User};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * A hosted server's own disk is wiped on every Laravel Cloud deploy, so patient documents go to
 * the private "documents" bucket once Cloud has attached it; offline installs keep the local disk.
 */
class PatientDocumentStorageTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private function doctorWithPatient(): array
    {
        config()->set('tenancy.enabled', true);
        $doctor = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Doctor', 'guard_name' => 'web']);
        $doctor->assignRole($role);
        $clinic = $this->activeClinic(['name' => 'Docs Clinic', 'slug' => 'docs-clinic']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($doctor->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($doctor->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $doctor->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        app(TenantContext::class)->set($doctor, $clinic, $branch, [$branch->id]);
        $this->actingAs($doctor)->withSession([config('tenancy.session_keys.clinic') => $clinic->id, config('tenancy.session_keys.branch') => $branch->id]);

        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $doctor->id, 'name' => 'Ama Doc', 'contact' => '0200000009', 'dob' => '1990-01-01', 'gender' => 'Female', 'address' => 'Accra']);
        $clearance = CashierPatientClearance::create(['user_id' => $doctor->id, 'patient_id' => $patient->id, 'payment_status' => 'Paid', 'doctor_status' => false, 'clearance_date' => now()->toDateString()]);

        return [$clinic, $patient, $clearance];
    }

    private function upload(CashierPatientClearance $clearance): PatientDocument
    {
        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])
            ->set('documentFiles', [UploadedFile::fake()->create('fundus.pdf', 120, 'application/pdf')])
            ->set('documentType', 'fundus_photo')
            ->call('uploadPatientDocument')->assertHasNoErrors();

        return PatientDocument::latest('id')->firstOrFail();
    }

    public function test_hosted_uploads_go_to_the_attached_documents_bucket(): void
    {
        // What Laravel Cloud sets up when a bucket is attached with the disk name "documents".
        config()->set('filesystems.disks.documents', ['driver' => 's3', 'bucket' => 'clinic-documents', 'key' => 'k', 'secret' => 's', 'region' => 'auto']);
        Storage::fake('documents');
        Storage::fake('local');
        [, , $clearance] = $this->doctorWithPatient();

        $document = $this->upload($clearance);

        $this->assertSame('documents', $document->storage_disk);
        Storage::disk('documents')->assertExists($document->file_path);
        Storage::disk('local')->assertMissing($document->file_path);
        $this->get(route('patient-documents.show', $document))->assertOk();
    }

    public function test_a_failed_upload_saves_no_record_and_tells_the_doctor(): void
    {
        config()->set('filesystems.disks.documents', ['driver' => 's3', 'bucket' => 'clinic-documents', 'key' => 'k', 'secret' => 's', 'region' => 'auto']);
        // The bucket refuses the file (Laravel returns false rather than throwing by default).
        $broken = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $broken->shouldReceive('putFileAs')->andReturn(false);
        Storage::set('documents', $broken);
        [, , $clearance] = $this->doctorWithPatient();

        Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance])
            ->set('documentFiles', [UploadedFile::fake()->create('oct-scan.pdf', 50, 'application/pdf')])
            ->set('documentType', 'oct')
            ->call('uploadPatientDocument')
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? '') === 'error' && str_contains($params['message'] ?? '', 'oct-scan.pdf'));

        $this->assertSame(0, PatientDocument::count());
    }

    public function test_making_the_bucket_the_default_disk_leaves_upload_temp_files_on_the_server(): void
    {
        // What Cloud does when the documents bucket is the environment's default disk.
        config()->set('filesystems.default', 'documents');

        $this->assertSame('local', config('livewire.temporary_file_upload.disk'));
    }

    public function test_without_a_bucket_uploads_stay_local_and_readiness_flags_it(): void
    {
        Storage::fake('local');
        [, , $clearance] = $this->doctorWithPatient();

        $this->assertSame('local', $this->upload($clearance)->storage_disk);

        $check = app(\App\Services\DeploymentReadinessService::class)->run(false)['checks']['patient_documents'];
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('"documents"', $check['remediation']);

        config()->set('filesystems.disks.documents', ['driver' => 's3', 'bucket' => 'clinic-documents']);
        $this->assertTrue(app(\App\Services\DeploymentReadinessService::class)->run(false)['checks']['patient_documents']['passed']);
    }

    public function test_a_lost_file_gets_a_clear_message_and_is_listed(): void
    {
        Storage::fake('local');
        [, , $clearance] = $this->doctorWithPatient();
        $document = $this->upload($clearance);
        Storage::disk('local')->delete($document->file_path); // as after a deploy

        $this->get(route('patient-documents.show', $document))->assertNotFound()->assertSee('Please upload it again');
        $this->artisan('documents:missing')->expectsOutputToContain('1 document(s) need uploading again.')->assertSuccessful();
    }
}
