<?php

namespace Tests\Feature;

use App\Livewire\Admin\RolePermissionManagerComponent;
use App\Models\Clinic;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * Roles and permissions are shared by every clinic. A clinic owner must not be able to change them
 * (that would change every clinic) nor give or take roles from another clinic's staff.
 */
class SharedRolesLockTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private User $owner;
    private User $ownStaff;
    private User $otherClinicStaff;
    private Role $cashier;
    private Permission $permission;
    private Clinic $clinicA;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('tenancy.enabled', true);
        $ownerRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $this->cashier = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $this->permission = Permission::firstOrCreate(['name' => 'view sales records', 'guard_name' => 'web']);
        $this->cashier->givePermissionTo($this->permission);

        $clinicA = $this->clinicA = $this->activeClinic(['name' => 'Clinic A', 'slug' => 'clinic-a']);
        $clinicB = $this->activeClinic(['name' => 'Clinic B', 'slug' => 'clinic-b']);

        $this->owner = $this->staff($clinicA, 'owner@a.test');
        $this->owner->assignRole($ownerRole);
        $this->ownStaff = $this->staff($clinicA, 'desk@a.test');
        $this->otherClinicStaff = $this->staff($clinicB, 'cashier@b.test');
        $this->otherClinicStaff->assignRole($this->cashier);
    }

    private function staff(Clinic $clinic, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $branch = $clinic->branches()->firstOrCreate(['code' => 'MAIN'], ['name' => $clinic->name.' Main', 'is_default' => true]);
        $clinic->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'status' => 'active', 'is_default' => true]);

        return $user;
    }

    private function asOwner()
    {
        $branch = $this->clinicA->branches()->where('code', 'MAIN')->firstOrFail();
        app(TenantContext::class)->set($this->owner, $this->clinicA, $branch, [$branch->id]);

        return Livewire::actingAs($this->owner)->test(RolePermissionManagerComponent::class);
    }

    public function test_clinic_owner_cannot_change_what_a_shared_role_can_do(): void
    {
        $this->asOwner()
            ->set('editingRoleId', $this->cashier->id)
            ->set('roleName', 'Cashier')
            ->set('selectedPermissions', [])
            ->call('saveRole')
            ->assertForbidden();

        $this->assertTrue($this->cashier->fresh()->hasPermissionTo('view sales records'));
    }

    public function test_clinic_owner_cannot_create_delete_or_rename_roles_and_permissions(): void
    {
        $spare = Role::create(['name' => 'Spare', 'guard_name' => 'web']);

        $this->asOwner()->set('roleName', 'Clinic A Role')->call('saveRole')->assertForbidden();
        $this->asOwner()->call('deleteRole', $spare->id)->assertForbidden();
        $this->asOwner()->set('permissionName', 'new power')->call('savePermission')->assertForbidden();
        $this->asOwner()->set('editingPermissionId', $this->permission->id)->set('permissionName', 'renamed')->call('savePermission')->assertForbidden();
        $this->asOwner()->call('deletePermission', $this->permission->id)->assertForbidden();
        $this->asOwner()->call('createPermissionPreset', 'Billing')->assertForbidden();
        $this->asOwner()->call('applyRoleTemplate', 'Cashier')->assertForbidden();
        $this->asOwner()->call('openEditRole', $this->cashier->id)->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'Clinic A Role']);
        $this->assertDatabaseHas('roles', ['name' => 'Spare']);
        $this->assertDatabaseMissing('permissions', ['name' => 'new power']);
        $this->assertDatabaseMissing('permissions', ['name' => 'manage billing']);
        $this->assertDatabaseHas('permissions', ['name' => 'view sales records']);
    }

    public function test_clinic_owner_can_still_give_roles_to_their_own_staff(): void
    {
        $this->asOwner()
            ->call('openRoleUsers', $this->cashier->id)
            ->set('userToAssign', $this->ownStaff->id)
            ->call('assignUserToManagedRole')
            ->assertOk();

        $this->assertTrue($this->ownStaff->fresh()->hasRole('Cashier'));
    }

    public function test_clinic_owner_cannot_touch_another_clinics_staff(): void
    {
        $refused = 0;
        $attempts = [
            fn () => $this->asOwner()->call('openRoleUsers', $this->cashier->id)
                ->set('userToAssign', $this->otherClinicStaff->id)->call('assignUserToManagedRole'),
            fn () => $this->asOwner()->call('openRoleUsers', $this->cashier->id)
                ->call('removeUserFromManagedRole', $this->otherClinicStaff->id),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (ModelNotFoundException) {
                $refused++;
            }
        }

        $this->assertSame(2, $refused);
        $this->assertTrue($this->otherClinicStaff->fresh()->hasRole('Cashier'));
        $this->assertFalse($this->otherClinicStaff->fresh()->hasRole('Super Admin'));
    }

    public function test_role_users_list_shows_only_this_clinics_staff(): void
    {
        $this->ownStaff->assignRole($this->cashier);

        $this->asOwner()
            ->call('openRoleUsers', $this->cashier->id)
            ->assertSee('desk@a.test')
            ->assertDontSee('cashier@b.test');
    }

    public function test_page_is_read_only_for_clinics(): void
    {
        $this->asOwner()
            ->assertSee('Roles are set by the Nexus team for now.')
            ->assertDontSeeHtml('wire:click="openCreateRole"')
            ->assertDontSeeHtml('wire:click="openCreatePermission"');
    }

    public function test_platform_admin_can_still_change_shared_roles(): void
    {
        $this->owner->forceFill(['is_platform_admin' => true])->save();

        $this->asOwner()
            ->call('openEditRole', $this->cashier->id)
            ->set('selectedPermissions', [])
            ->call('saveRole')
            ->assertOk();

        $this->assertFalse($this->cashier->fresh()->hasPermissionTo('view sales records'));
    }
}
