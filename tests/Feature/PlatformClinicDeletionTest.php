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

    public function test_a_clinic_that_has_ceased_operation_is_erased_with_its_records_and_a_reason(): void
    {
        [$closed, $admin] = $this->clinicWithAdmin('has-patients', 'patients-admin@example.test');
        $branch = $closed->branches()->firstOrFail();
        DB::table('patients')->insert(['uuid' => (string) Str::uuid(), 'clinic_id' => $closed->id, 'user_id' => $admin->id, 'name' => 'Real Patient', 'pxnumber' => 'PX-1', 'gender' => 'Female', 'contact' => '0240000000', 'created_at' => now(), 'updated_at' => now()]);
        \App\Models\PlatformInvoice::create(['number' => 'INV-OWED', 'clinic_id' => $closed->id, 'period_start' => now()->subMonths(2), 'period_end' => now()->subMonth(), 'due_date' => now()->subMonth(), 'subtotal' => 300, 'tax' => 0, 'total' => 300, 'amount_paid' => 100, 'currency' => 'GHS', 'status' => 'partial']);
        [$open, $openAdmin] = $this->clinicWithAdmin('still-open', 'open-admin@example.test');
        DB::table('patients')->insert(['uuid' => (string) Str::uuid(), 'clinic_id' => $open->id, 'user_id' => $openAdmin->id, 'name' => 'Other Patient', 'pxnumber' => 'PX-2', 'gender' => 'Male', 'contact' => '0240000001', 'created_at' => now(), 'updated_at' => now()]);

        // The developer is shown what will be lost and what is still owed, and must say why.
        $panel = Livewire::actingAs($this->developer)->test(PlatformDashboardComponent::class)
            ->call('selectClinic', $closed->id)->call('setPanelTab', 'danger')
            ->assertSee('This clinic has records: 1 patients')->assertSee('Erase clinic and all its records')->assertSee('200.00')->assertSee('is still owed')
            ->set('deleteClinicConfirmation', 'DELETE has-patients')->call('deleteClinic', $closed->id)->assertHasErrors('deleteClinicReason');
        $this->assertDatabaseHas('clinics', ['id' => $closed->id]);
        $panel->set('deleteClinicReason', 'short')->call('deleteClinic', $closed->id)->assertHasErrors('deleteClinicReason');
        $panel->set('deleteClinicReason', 'Ceased operation in September; owner confirmed by phone')->set('deleteClinicAccounts', true)
            ->call('deleteClinic', $closed->id)->assertHasNoErrors();

        // Everything of the closed clinic is gone, and nothing of the other clinic is touched.
        $this->assertDatabaseMissing('clinics', ['id' => $closed->id]);
        $this->assertSame(0, DB::table('patients')->where('clinic_id', $closed->id)->count());
        $this->assertSame(0, DB::table('branches')->where('id', $branch->id)->count());
        $this->assertSame(0, DB::table('platform_invoices')->where('clinic_id', $closed->id)->count());
        $this->assertDatabaseMissing('users', ['id' => $admin->id]);
        $this->assertDatabaseHas('clinics', ['id' => $open->id]);
        $this->assertSame(1, DB::table('patients')->where('clinic_id', $open->id)->count());
        $this->assertDatabaseHas('users', ['id' => $openAdmin->id]);

        // What remains is one audit entry: who, why, what it held and what it had been billed.
        $entry = \App\Models\PlatformAuditLog::where('action', 'clinic.deleted')->latest('id')->firstOrFail();
        $this->assertSame($this->developer->id, $entry->user_id);
        $this->assertSame('Ceased operation in September; owner confirmed by phone', $entry->reason);
        $this->assertSame(['patients' => 1], $entry->old_values['records']);
        $this->assertEquals(['invoices' => 1, 'billed' => 300, 'paid' => 100, 'outstanding' => 200], $entry->old_values['billing']);
        $this->assertSame('HAS-PATIENTS', $entry->old_values['clinic_name']);
    }

    public function test_an_imported_clinic_is_rolled_back_not_deleted(): void
    {
        [$imported] = $this->clinicWithAdmin('imported', 'imported-admin@example.test');
        LegacyImportBatch::create(['uuid' => Str::uuid(), 'created_by' => $this->developer->id, 'original_filename' => 'x.sql', 'stored_path' => 'legacy-imports/x.sql', 'checksum' => str_repeat('a', 64), 'status' => 'committed', 'clinic_id' => $imported->id]);

        Livewire::actingAs($this->developer)->test(PlatformDashboardComponent::class)
            ->call('selectClinic', $imported->id)->call('setPanelTab', 'danger')->assertSee('Roll the import back')->assertDontSee('Erase clinic and all its records')
            ->set('deleteClinicConfirmation', 'DELETE imported')->set('deleteClinicReason', 'Ceased operation, confirmed by the owner')
            ->call('deleteClinic', $imported->id)->assertHasErrors('deleteClinic');
        $this->assertDatabaseHas('clinics', ['id' => $imported->id]);

        // The service itself still refuses a clinic with records unless told to erase them.
        [$withRecords, $admin] = $this->clinicWithAdmin('guarded', 'guarded-admin@example.test');
        DB::table('patients')->insert(['uuid' => (string) Str::uuid(), 'clinic_id' => $withRecords->id, 'user_id' => $admin->id, 'name' => 'Kept Patient', 'pxnumber' => 'PX-3', 'gender' => 'Female', 'contact' => '0240000002', 'created_at' => now(), 'updated_at' => now()]);
        $service = app(\App\Services\LegacyClinicImportService::class);
        $this->assertStringContainsString('has patients', (string) $service->clinicDeletionBlocker($withRecords->id));
        $this->assertNull($service->clinicDeletionBlocker($withRecords->id, true));
    }
}
