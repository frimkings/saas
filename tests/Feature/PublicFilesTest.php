<?php

namespace Tests\Feature;

use App\Livewire\Admin\SettingsComponent;
use App\Models\Clinic;
use App\Models\Setting;
use App\Models\User;
use App\Support\PublicFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Logos, profile pictures and expense receipts are saved wherever the install can keep them,
 * and shown from /storage/... On the hosted app there was no public bucket, so the disk
 * could not be built and saving a logo ended in "This page has expired".
 */
class PublicFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_disk_works_without_a_public_bucket_and_uses_the_attached_bucket_on_cloud(): void
    {
        // No PUBLIC_AWS_BUCKET: the server's own storage, not an S3 disk without a bucket.
        $this->assertSame('local', config('filesystems.disks.public.driver'));

        // Laravel Cloud attached the documents bucket: public files go into it, in their own folder.
        config(['filesystems.disks.documents' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'bucket' => 'patient-documents',
            'url' => null, 'endpoint' => 'https://example.r2.cloudflarestorage.com', 'region' => 'auto', 'use_path_style_endpoint' => false]]);
        PublicFiles::configure();
        $public = config('filesystems.disks.public');
        $this->assertSame(['s3', 'patient-documents', PublicFiles::BUCKET_FOLDER, 'private'], [$public['driver'], $public['bucket'], $public['root'], $public['visibility']]);
        Storage::forgetDisk('public');
        $this->assertInstanceOf(\Illuminate\Filesystem\AwsS3V3Adapter::class, Storage::disk('public'));

        // A public bucket that was configured is left alone.
        config(['filesystems.disks.public' => ['driver' => 's3', 'bucket' => 'own-public-bucket']]);
        PublicFiles::configure();
        $this->assertSame('own-public-bucket', config('filesystems.disks.public.bucket'));
    }

    public function test_a_saved_logo_is_served_from_storage_and_nothing_else_is(): void
    {
        Storage::fake('public');
        Clinic::create(['name' => 'Vision Space', 'slug' => 'vision-space', 'status' => 'active']);
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $this->actingAs($admin);

        Livewire::test(SettingsComponent::class)->set('newLogo', UploadedFile::fake()->image('vs-full-color-logo.jpg', 300, 120))
            ->call('updateSettings')->assertHasNoErrors();
        $path = Setting::getSettings()->clinic_logo;
        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);

        // Shown to anyone with the link (receipts, booking pages), with no sign-in or session.
        auth()->logout();
        $response = $this->get('/storage/'.$path)->assertOk();
        $this->assertStringContainsString('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=86400', $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('Set-Cookie'));

        // Only branding and profile pictures: not receipts, other folders or missing files.
        Storage::disk('public')->put('clinics/abc/branches/def/expense-receipts/r.pdf', 'pdf');
        Storage::disk('public')->put('avatars/me.jpg', 'jpg');
        $this->get('/storage/clinics/abc/branches/def/expense-receipts/r.pdf')->assertNotFound();
        $this->get('/storage/clinics/abc/branding/../../secret.txt')->assertNotFound();
        $this->get('/storage/clinics/abc/branding/missing.png')->assertNotFound();
        $this->get('/storage/avatars/me.jpg')->assertOk();
    }
}
