<?php

namespace Tests\Feature;

use App\Livewire\Platform\PlatformDashboardComponent;
use App\Models\Clinic;
use App\Models\LegacyImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class PlatformClinicDeletionTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->developer = User::factory()->create();
        $this->developer->forceFill(['is_platform_admin' => true])->save();
    }

    private function clinicWithAdmin(string $slug, string $email): array
    {
        $clinic = $this->activeClinic(['name' => strtoupper($slug), 'slug' => $slug]);
        $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $admin = User::factory()->create(['email' => $email]);
        $clinic->users()->attach($admin, ['status' => 'active', 'clinic_role' => 'Super Admin', 'is_default' => true]);

        return [$clinic, $admin];
    }

    public function test_empty_duplicate_is_deleted_with_its_clinic_only_accounts(): void
    {
        [$clinic, $admin] = $this->clinicWithAdmin('dup-clinic', 'dup-admin@example.test');
        [$other] = $this->clinicWithAdmin('other-clinic', 'other-admin@example.test');
        $shared = User::factory()->create(['email' => 'shared@example.test']);
        $clinic->users()->attach($shared, ['status' => 'active', 'clinic_role' => 'Staff']);
        $other->users()->attach($shared, ['status' => 'active', 'clinic_role' => 'Staff']);

        Livewire::actingAs($this->developer)->test(PlatformDashboardComponent::class)
            ->call('selectClinic', $clinic->id)->call('setPanelTab', 'danger')
            ->assertSee('DELETE dup-clinic')->assertSee('dup-admin@example.test')
            ->set('deleteClinicConfirmation', 'DELETE wrong')->call('deleteClinic', $clinic->id)->assertHasErrors('deleteClinic')
            ->set('deleteClinicConfirmation', 'DELETE dup-clinic')->set('deleteClinicAccounts', true)
            ->call('deleteClinic', $clinic->id)->assertHasNoErrors();

        $this->assertDatabaseMissing('clinics', ['id' => $clinic->id]);
        $this->assertDatabaseMissing('branches', ['clinic_id' => $clinic->id]);
        $this->assertDatabaseMissing('clinic_subscriptions', ['clinic_id' => $clinic->id]);
        $this->assertDatabaseMissing('users', ['id' => $admin->id]);
        // Someone who also works elsewhere keeps their account and other clinic.
        $this->assertDatabaseHas('clinic_user', ['clinic_id' => $other->id, 'user_id' => $shared->id]);
        $this->assertDatabaseHas('clinics', ['id' => $other->id]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'clinic.deleted', 'user_id' => $this->developer->id]);
    }

    public function test_accounts_are_kept_unless_explicitly_selected(): void
    {
        [$clinic, $admin] = $this->clinicWithAdmin('keep-accounts', 'keep-admin@example.test');

        Livewire::actingAs($this->developer)->test(PlatformDashboardComponent::class)
            ->call('selectClinic', $clinic->id)->call('setPanelTab', 'danger')->set('deleteClinicConfirmation', 'DELETE keep-accounts')
            ->call('deleteClinic', $clinic->id)->assertHasNoErrors();

        $this->assertDatabaseMissing('clinics', ['id' => $clinic->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_clinics_with_data_or_an_import_cannot_be_deleted(): void
    {
        [$withPatients, $patientsAdmin] = $this->clinicWithAdmin('has-patients', 'patients-admin@example.test');
        DB::table('patients')->insert(['uuid' => (string) Str::uuid(), 'clinic_id' => $withPatients->id, 'user_id' => $patientsAdmin->id, 'name' => 'Real Patient', 'pxnumber' => 'PX-1', 'gender' => 'Female', 'contact' => '0240000000', 'created_at' => now(), 'updated_at' => now()]);
        [$imported] = $this->clinicWithAdmin('imported', 'imported-admin@example.test');
        LegacyImportBatch::create(['uuid' => Str::uuid(), 'created_by' => $this->developer->id, 'original_filename' => 'x.sql', 'stored_path' => 'legacy-imports/x.sql', 'checksum' => str_repeat('a', 64), 'status' => 'committed', 'clinic_id' => $imported->id, 'committed_at' => now(), 'analysis' => []]);

        $component = Livewire::actingAs($this->developer)->test(PlatformDashboardComponent::class);
        $component->call('selectClinic', $withPatients->id)->call('setPanelTab', 'danger')->assertSee('Use Cancellation')->assertDontSee('Delete clinic permanently')
            ->set('deleteClinicConfirmation', 'DELETE has-patients')->call('deleteClinic', $withPatients->id)->assertHasErrors('deleteClinic');
        $component->call('selectClinic', $imported->id)->call('setPanelTab', 'danger')->assertSee('Roll the import back')
            ->set('deleteClinicConfirmation', 'DELETE imported')->call('deleteClinic', $imported->id)->assertHasErrors('deleteClinic');

        $this->assertDatabaseHas('clinics', ['id' => $withPatients->id]);
        $this->assertDatabaseHas('clinics', ['id' => $imported->id]);
    }
}
