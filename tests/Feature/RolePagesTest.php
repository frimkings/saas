<?php

namespace Tests\Feature;

use App\Livewire\Admin\RoleGuideComponent;
use App\Livewire\Platform\RolesComponent;
use App\Models\User;
use App\Support\Access\Access;
use App\Support\Access\PersonalAccess;
use App\Support\Access\RoleGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * Roles phase 5: roles are read-only everywhere (changed in code by Nexus), and the old database
 * permissions clinics added to shared roles became per-person switches.
 */
class RolePagesTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (RoleGuide::ROLES as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function person(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_clinics_see_what_each_role_can_do_but_cannot_change_it(): void
    {
        $this->actingAs($this->person('Super Admin'));
        $page = Livewire::test(RoleGuideComponent::class)
            ->assertSee('What each role can do')->assertSee('Owner')->assertSee('Staff (attendance only)')
            ->assertSee('Approve refunds')->assertDontSeeText('Super Admin');
        // Read-only: the page has nothing to call but loading and drawing itself.
        $actions = collect((new \ReflectionClass(RoleGuideComponent::class))->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->filter(fn ($method) => $method->class === RoleGuideComponent::class)->pluck('name')->sort()->values()->all();
        $this->assertSame(['mount', 'render'], $actions);
        $this->assertStringNotContainsString('wire:submit', $page->html());

        $page->set('role', 'Cashier')->assertSee('Refund log')->assertDontSee('Approve refunds');
        // Optical roles show their optical screens, not a column of dashes.
        $page->set('role', 'Lab Technician')->assertSee('Optical lab: workbench')->assertSee('Optical stock and stock counts')
            ->assertDontSee('Optical sales desk');
        $page->set('role', 'Optical Assistant')->assertSee('Optical sales desk');
        $page->set('role', 'Secretary')->assertSee('Optical sales desk');

        $this->actingAs($this->person('Manager'));
        Livewire::test(RoleGuideComponent::class)->assertOk();

        $this->actingAs($this->person('Cashier'));
        Livewire::test(RoleGuideComponent::class)->assertForbidden();
    }

    public function test_the_platform_sees_roles_holders_and_switches(): void
    {
        $clinic = $this->activeClinic(['name' => 'Roles Clinic', 'slug' => 'roles-clinic']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $cashier = User::factory()->create();
        DB::table('clinic_user')->insert(['clinic_id' => $clinic->id, 'user_id' => $cashier->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $cashier->id, 'role_id' => Role::findByName('Cashier')->id]);
        DB::table('clinic_user_permissions')->insert(['clinic_id' => $clinic->id, 'user_id' => $cashier->id, 'permission' => 'expenses.manage',
            'granted' => true, 'created_at' => now(), 'updated_at' => now()]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin);

        Livewire::test(RolesComponent::class)
            ->assertSee('Permissions by role')->assertSee('Roles Clinic')->assertSee('1 on · 0 off')->assertSee('+ Expenses');
    }

    public function test_old_role_edits_become_switches_for_the_people_who_relied_on_them(): void
    {
        $clinic = $this->activeClinic(['name' => 'Legacy Clinic', 'slug' => 'legacy-clinic']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $insurer = Permission::firstOrCreate(['name' => 'record insurer payments', 'guard_name' => 'web']);
        $users = Permission::firstOrCreate(['name' => 'manage users', 'guard_name' => 'web']);
        $optical = Permission::firstOrCreate(['name' => 'optical sales', 'guard_name' => 'web']);
        Role::findByName('Secretary')->givePermissionTo([$insurer, $optical]);
        Role::findByName('Manager')->givePermissionTo([$insurer, $users]);

        $people = [];
        foreach (['Secretary', 'Manager', 'Super Admin'] as $role) {
            $user = User::factory()->create();
            DB::table('clinic_user')->insert(['clinic_id' => $clinic->id, 'user_id' => $user->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => Role::findByName($role)->id]);
            $people[$role] = $user;
        }
        $direct = $this->person('Cashier');
        DB::table('clinic_user')->insert(['clinic_id' => $clinic->id, 'user_id' => $direct->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $direct->givePermissionTo($insurer);

        (require database_path('migrations/2026_11_19_000003_move_legacy_role_permissions_to_switches.php'))->up();

        $switches = DB::table('clinic_user_permissions')->where('clinic_id', $clinic->id)->get(['user_id', 'permission'])
            ->map(fn ($row) => $row->user_id.':'.$row->permission)->sort()->values()->all();
        $this->assertSame(collect([$people['Secretary']->id.':insurance.payments', $direct->id.':insurance.payments'])->sort()->values()->all(), $switches,
            'Managers and the Owner get these from their role; only the Secretary and the directly-given Cashier need switches.');

        $this->assertFalse(DB::table('role_has_permissions')->whereIn('permission_id', [$insurer->id, $users->id])->exists());
        $this->assertFalse(DB::table('model_has_permissions')->where('permission_id', $insurer->id)->exists());
        $this->assertTrue(Role::findByName('Secretary')->fresh()->hasPermissionTo('optical sales'), 'optical permissions are untouched');
    }

    public function test_managers_hold_staff_and_insurer_payments_through_their_role(): void
    {
        $manager = $this->person('Manager');
        $this->assertTrue(Access::allows($manager, 'staff.manage'));
        $this->assertTrue(Access::allows($manager, 'insurance.payments'));
        $this->assertTrue(Access::allows($manager, 'roles.view'));
        $this->assertFalse(PersonalAccess::fromRole($this->person('Secretary'), 'insurance.payments'));
    }
}
