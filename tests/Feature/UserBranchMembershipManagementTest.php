<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserRoleManagerComponent;
use App\Models\Clinic;
use App\Models\User;
use App\Support\Tenancy\BranchRoleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class UserBranchMembershipManagementTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    public function test_super_admin_creates_staff_with_clinic_and_branch_memberships(): void
    {
        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Secretary', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole($adminRole);
        $clinic = $this->activeClinic(['name' => 'Clinic', 'slug' => 'clinic']);
        $main = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $east = $clinic->branches()->create(['code' => 'EAST', 'name' => 'East']);
        $clinic->users()->attach($admin, ['status' => 'active', 'clinic_role' => 'Super Admin']);
        $main->users()->attach($admin, ['status' => 'active', 'is_default' => true]);

        Livewire::actingAs($admin)->test(UserRoleManagerComponent::class)
            ->set('name', 'Branch Secretary')
            ->set('email', 'branch.secretary@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->set('selectedRoles', [$role->name])
            ->set('selectedBranchIds', [$main->id, $east->id])
            ->set('defaultBranchId', $east->id)
            ->set('membershipStatus', 'active')
            ->set('staff_id', 'CL-SEC-01')
            ->call('store')
            ->assertHasNoErrors();

        $staff = User::where('email', 'branch.secretary@example.test')->firstOrFail();
        $this->assertDatabaseHas('clinic_user', [
            'clinic_id' => $clinic->id, 'user_id' => $staff->id,
            'status' => 'active', 'clinic_role' => 'Secretary', 'staff_identifier' => 'CL-SEC-01',
        ]);
        $this->assertDatabaseHas('branch_user', ['branch_id' => $main->id, 'user_id' => $staff->id, 'is_default' => false]);
        $this->assertDatabaseHas('branch_user', ['branch_id' => $east->id, 'user_id' => $staff->id, 'is_default' => true]);
    }

    public function test_default_branch_must_be_selected(): void
    {
        config()->set('tenancy.enabled', true);
        $adminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole($adminRole);
        $clinic = $this->activeClinic(['name' => 'Clinic', 'slug' => 'clinic']);
        $main = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $other = $clinic->branches()->create(['code' => 'OTHER', 'name' => 'Other']);
        $clinic->users()->attach($admin, ['status' => 'active']);
        $main->users()->attach($admin, ['status' => 'active', 'is_default' => true]);

        Livewire::actingAs($admin)->test(UserRoleManagerComponent::class)
            ->set('name', 'Cashier User')->set('email', 'cashier@example.test')
            ->set('password', 'password123')->set('password_confirmation', 'password123')
            ->set('selectedRoles', ['Cashier'])->set('selectedBranchIds', [$main->id])
            ->set('defaultBranchId', $other->id)->call('store')
            ->assertHasErrors(['defaultBranchId']);

        $this->assertDatabaseMissing('users', ['email' => 'cashier@example.test']);
    }

    public function test_shared_mode_assigns_multiple_roles_to_every_allowed_branch(): void
    {
        [$admin, $clinic, $main, $east] = $this->tenantFixture();
        $doctor = Role::firstOrCreate(['name' => 'Doctor', 'guard_name' => 'web']);
        $secretary = Role::firstOrCreate(['name' => 'Secretary', 'guard_name' => 'web']);

        Livewire::actingAs($admin)->test(UserRoleManagerComponent::class)
            ->set('name', 'Shared Roles')->set('email', 'shared@example.test')
            ->set('password', 'password123')->set('password_confirmation', 'password123')
            ->set('roleAssignmentMode', 'shared')->set('selectedRoles', ['Doctor', 'Secretary'])
            ->set('selectedBranchIds', [$main->id, $east->id])->set('defaultBranchId', $main->id)
            ->call('store')->assertHasNoErrors();

        $staff = User::where('email', 'shared@example.test')->firstOrFail();
        foreach ([$main, $east] as $branch) {
            $this->assertDatabaseHas('branch_user_role', ['branch_id' => $branch->id, 'user_id' => $staff->id, 'role_id' => $doctor->id]);
            $this->assertDatabaseHas('branch_user_role', ['branch_id' => $branch->id, 'user_id' => $staff->id, 'role_id' => $secretary->id]);
        }
    }

    public function test_custom_mode_assigns_different_multiple_roles_per_branch(): void
    {
        [$admin, $clinic, $main, $east] = $this->tenantFixture();
        $doctor = Role::firstOrCreate(['name' => 'Doctor', 'guard_name' => 'web']);
        $secretary = Role::firstOrCreate(['name' => 'Secretary', 'guard_name' => 'web']);
        $cashier = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);

        Livewire::actingAs($admin)->test(UserRoleManagerComponent::class)
            ->set('name', 'Custom Roles')->set('email', 'custom@example.test')
            ->set('password', 'password123')->set('password_confirmation', 'password123')
            ->set('roleAssignmentMode', 'custom')->set('selectedBranchIds', [$main->id, $east->id])
            ->set('defaultBranchId', $main->id)
            ->set("branchRoles.{$main->id}", ['Doctor'])
            ->set("branchRoles.{$east->id}", ['Secretary', 'Cashier'])
            ->call('store')->assertHasNoErrors();

        $staff = User::where('email', 'custom@example.test')->firstOrFail();
        $manager = app(BranchRoleManager::class);
        $manager->hydrate($staff, $main);
        $this->assertTrue($staff->hasRole('Doctor'));
        $this->assertFalse($staff->hasRole('Cashier'));
        $manager->hydrate($staff, $east);
        $this->assertTrue($staff->hasAllRoles(['Secretary', 'Cashier']));
        $this->assertFalse($staff->hasRole('Doctor'));
        $this->assertDatabaseMissing('branch_user_role', ['branch_id' => $main->id, 'user_id' => $staff->id, 'role_id' => $cashier->id]);
        $this->assertDatabaseHas('branch_user_role', ['branch_id' => $east->id, 'user_id' => $staff->id, 'role_id' => $secretary->id]);
        $this->assertDatabaseHas('branch_user_role', ['branch_id' => $east->id, 'user_id' => $staff->id, 'role_id' => $cashier->id]);
        $this->assertDatabaseHas('branch_user_role', ['branch_id' => $main->id, 'user_id' => $staff->id, 'role_id' => $doctor->id]);
    }

    private function tenantFixture(): array
    {
        config()->set('tenancy.enabled', true);
        $adminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole($adminRole);
        $clinic = $this->activeClinic(['name' => 'Clinic', 'slug' => 'clinic-'.uniqid()]);
        $main = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $east = $clinic->branches()->create(['code' => 'EAST', 'name' => 'East']);
        $clinic->users()->attach($admin, ['status' => 'active', 'clinic_role' => 'Super Admin']);
        $main->users()->attach($admin, ['status' => 'active', 'is_default' => true]);
        return [$admin, $clinic, $main, $east];
    }
}
