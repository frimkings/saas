<?php

namespace Tests\Feature;

use App\Http\Middleware\LimitTemporaryUploadSize;
use App\Livewire\Doctor\PatientRecordsComponent;
use App\Models\{CashierPatientClearance, Patient, PatientDocument, User};
use App\Support\Tenancy\TenantContext;
use App\Support\UploadLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** Clinic uploads are held to 3 MB per file, in the browser, at Livewire's upload step and per form. */
class UploadLimitsTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    public function test_the_clinic_limit_is_3_mb(): void
    {
        $this->assertSame(3072, UploadLimits::CLINIC_KB);
        $this->assertSame('3 MB', UploadLimits::label());
        $this->assertSame(['required', 'file', 'max:3072'], config('livewire.temporary_file_upload.rules'));
    }

    public function test_only_platform_admins_may_send_large_files_to_the_temporary_upload_step(): void
    {
        $rulesFor = function (?User $user): array {
            $request = Request::create('/livewire/upload-file', 'POST');
            $request->setUserResolver(fn () => $user);
            (new LimitTemporaryUploadSize())->handle($request, fn () => new Response());

            return config('livewire.temporary_file_upload.rules');
        };

        $staff = User::factory()->create();
        $platform = User::factory()->create();
        $platform->forceFill(['is_platform_admin' => true])->save();

        $this->assertContains('max:3072', $rulesFor($staff));
        $this->assertContains('max:3072', $rulesFor(null));
        $this->assertContains('max:204800', $rulesFor($platform));
        $this->assertContains(LimitTemporaryUploadSize::class, config('livewire.temporary_file_upload.middleware'));
    }

    public function test_a_patient_document_over_3_mb_is_refused_and_the_picker_shows_the_limit(): void
    {
        config()->set('tenancy.enabled', true);
        Storage::fake('local');
        $doctor = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Doctor', 'guard_name' => 'web']);
        $doctor->assignRole($role);
        $clinic = $this->activeClinic(['name' => 'Limit Clinic', 'slug' => 'limit-clinic']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($doctor->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($doctor->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $doctor->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        app(TenantContext::class)->set($doctor, $clinic, $branch, [$branch->id]);
        $this->actingAs($doctor);
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $doctor->id, 'name' => 'Kofi Limit', 'contact' => '0200000011', 'dob' => '1990-01-01', 'gender' => 'Male', 'address' => 'Accra']);
        $clearance = CashierPatientClearance::create(['user_id' => $doctor->id, 'patient_id' => $patient->id, 'payment_status' => 'Paid', 'doctor_status' => false, 'clearance_date' => now()->toDateString()]);

        $page = Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance]);
        $page->set('documentFiles', [UploadedFile::fake()->create('big-scan.pdf', 3500, 'application/pdf')])
            ->set('documentType', 'oct')->call('uploadPatientDocument')
            // Refused at Livewire's temporary upload step, so it never reaches the form.
            ->assertSet('documentFiles', [])->assertHasErrors('documentFiles');
        $this->assertSame(0, PatientDocument::count());

        $page->set('documentFiles', [UploadedFile::fake()->create('ok-scan.pdf', 2900, 'application/pdf')])
            ->set('documentType', 'oct')->call('uploadPatientDocument')->assertHasNoErrors();
        $this->assertSame(1, PatientDocument::count());
    }

    public function test_every_clinic_file_picker_carries_the_browser_limit(): void
    {
        $pickers = collect(glob(resource_path('views/livewire/**/*.blade.php')) ?: [])
            ->merge(glob(resource_path('views/livewire/*.blade.php')))
            ->merge(glob(resource_path('views/livewire/*/partials/*.blade.php')))
            ->reject(fn ($path) => str_contains(str_replace('\\', '/', $path), '/platform/'))
            ->flatMap(fn ($path) => collect(preg_split('/\R/', file_get_contents($path)))
                ->filter(fn ($line) => str_contains($line, 'type="file"'))
                ->map(fn ($line) => [basename($path), $line]));

        $this->assertNotEmpty($pickers);
        foreach ($pickers as [$file, $line]) {
            $this->assertStringContainsString('data-max-kb=', $line, "$file has a file picker without data-max-kb.");
        }
    }
}
