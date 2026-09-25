<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;
use Livewire\Livewire;
use App\Livewire\Admin\BranchManagementComponent;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Route;

class BranchSwitchingTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    public function test_user_can_switch_only_to_an_authorized_active_branch(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $clinic = $this->activeClinic(['name' => 'Clinic', 'slug' => 'clinic']);
        $one = $clinic->branches()->create(['code' => 'ONE', 'name' => 'One', 'is_default' => true]);
        $two = $clinic->branches()->create(['code' => 'TWO', 'name' => 'Two']);
        $clinic->users()->attach($user, ['status' => 'active']);
        $one->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $two->users()->attach($user, ['status' => 'active']);

        $this->actingAs($user)->withSession(['pos_cart' => ['x']])
            ->post(route('tenant.branch.switch'), ['branch_id' => $two->id])
            ->assertRedirect()
            ->assertSessionHas(config('tenancy.session_keys.branch'), $two->id)
            ->assertSessionMissing('pos_cart');

        $foreign = $this->activeClinic(['name' => 'Foreign', 'slug' => 'foreign'])->branches()->create(['code' => 'X', 'name' => 'X']);
        $this->actingAs($user)->post(route('tenant.branch.switch'), ['branch_id' => $foreign->id])->assertNotFound();
    }

    public function test_branch_endpoint_cannot_be_used_to_bypass_clinic_selection(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $one = $this->activeClinic(['name' => 'One', 'slug' => 'one-clinic']);
        $two = $this->activeClinic(['name' => 'Two', 'slug' => 'two-clinic']);
        $oneBranch = $one->branches()->create(['code' => 'MAIN', 'name' => 'One Main', 'is_default' => true]);
        $twoBranch = $two->branches()->create(['code' => 'MAIN', 'name' => 'Two Main', 'is_default' => true]);
        $one->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $two->users()->attach($user, ['status' => 'active']);
        $oneBranch->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $twoBranch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        $this->actingAs($user)->withSession([
            config('tenancy.session_keys.clinic') => $one->id,
            config('tenancy.session_keys.branch') => $oneBranch->id,
        ])->post(route('tenant.branch.switch'), ['branch_id' => $twoBranch->id])->assertNotFound();
    }

    public function test_switching_branch_redirects_using_only_the_new_branch_roles(): void
    {
        config()->set('tenancy.enabled', true);
        $doctor = Role::firstOrCreate(['name' => 'Doctor', 'guard_name' => 'web']);
        $cashier = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->syncRoles([$doctor, $cashier]);
        $clinic = $this->activeClinic(['name' => 'Role Clinic', 'slug' => 'role-clinic']);
        $clinical = $clinic->branches()->create(['code' => 'CLINICAL', 'name' => 'Clinical', 'is_default' => true]);
        $sales = $clinic->branches()->create(['code' => 'SALES', 'name' => 'Sales']);
        $clinic->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $clinical->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $sales->users()->attach($user, ['status' => 'active']);
        foreach ([[$clinical, $doctor], [$sales, $cashier]] as [$branch, $role]) {
            DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($user)->withSession([
            config('tenancy.session_keys.clinic') => $clinic->id,
            config('tenancy.session_keys.branch') => $clinical->id,
        ])->post(route('tenant.branch.switch'), ['branch_id' => $sales->id])
            ->assertRedirect(route('cashier.seller-desk'))
            ->assertSessionHas(config('tenancy.session_keys.branch'), $sales->id);
    }

    public function test_branch_management_renders_during_disabled_cutover_mode(): void
    {
        config()->set('tenancy.enabled', false);
        $user = User::factory()->create();
        $clinic = $this->activeClinic(['name' => 'Cutover Clinic', 'slug' => 'cutover-clinic']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $clinic->users()->attach($user, ['status' => 'active']);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        Livewire::actingAs($user)->test(BranchManagementComponent::class)
            ->assertOk()->assertSee('Cutover Clinic')->assertSee('Main');
    }

    public function test_route_permissions_follow_the_selected_branch_roles(): void
    {
        config()->set('tenancy.enabled', true);
        Route::middleware(['web', 'auth', 'role:Doctor'])->get('/_acceptance/doctor-area', fn () => 'doctor');
        Route::middleware(['web', 'auth', 'role:Cashier'])->get('/_acceptance/cashier-area', fn () => 'cashier');

        $doctor = Role::firstOrCreate(['name' => 'Doctor', 'guard_name' => 'web']);
        $cashier = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $user = User::factory()->create();
        // Global roles remain for backwards-compatible administration, but
        // must not grant access in a branch where they are not assigned.
        $user->syncRoles([$doctor, $cashier]);
        $clinic = $this->activeClinic(['name' => 'Access Clinic', 'slug' => 'access-clinic']);
        $clinical = $clinic->branches()->create(['code' => 'CLIN', 'name' => 'Clinical', 'is_default' => true]);
        $sales = $clinic->branches()->create(['code' => 'SALE', 'name' => 'Sales']);
        $clinic->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $clinical->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $sales->users()->attach($user, ['status' => 'active']);
        DB::table('branch_user_role')->insert([
            ['branch_id' => $clinical->id, 'user_id' => $user->id, 'role_id' => $doctor->id, 'created_at' => now(), 'updated_at' => now()],
            ['branch_id' => $sales->id, 'user_id' => $user->id, 'role_id' => $cashier->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $session = [
            config('tenancy.session_keys.clinic') => $clinic->id,
            config('tenancy.session_keys.branch') => $clinical->id,
        ];
        $this->actingAs($user)->withSession($session)->get('/_acceptance/doctor-area')->assertOk();
        $this->actingAs($user)->withSession($session)->get('/_acceptance/cashier-area')->assertForbidden();

        $this->flushSession();
        $session[config('tenancy.session_keys.branch')] = $sales->id;
        $this->actingAs($user)->withSession($session)->get('/_acceptance/cashier-area')->assertOk();
        $this->get('/_acceptance/doctor-area')->assertForbidden();
    }
}
