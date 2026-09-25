<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\User;
use App\Support\Tenancy\ClinicMembershipManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;

class DefaultClinicResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_active_clinic_is_automatically_made_default_and_resolved(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $clinic = Clinic::create(['name' => 'Only Clinic', 'slug' => 'only-clinic']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $clinic->users()->attach($user, ['status' => 'active', 'is_default' => false]);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        $context = app(TenantContext::class)->resolveFor($user);

        $this->assertTrue($context->clinic()->is($clinic));
        $this->assertDatabaseHas('clinic_user', ['user_id' => $user->id, 'clinic_id' => $clinic->id, 'is_default' => true]);
    }

    public function test_active_default_clinic_wins_over_lower_id_membership(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $first = Clinic::create(['name' => 'First', 'slug' => 'first']);
        $preferred = Clinic::create(['name' => 'Preferred', 'slug' => 'preferred']);
        $firstBranch = $first->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $preferredBranch = $preferred->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $first->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $preferred->users()->attach($user, ['status' => 'active']);
        $firstBranch->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $preferredBranch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        app(ClinicMembershipManager::class)->setDefault($user, $preferred);
        $context = app(TenantContext::class)->resolveFor($user);

        $this->assertTrue($context->clinic()->is($preferred));
        $this->assertDatabaseHas('clinic_user', ['user_id' => $user->id, 'clinic_id' => $first->id, 'is_default' => false]);
        $this->assertDatabaseHas('clinic_user', ['user_id' => $user->id, 'clinic_id' => $preferred->id, 'is_default' => true]);
    }

    public function test_multi_clinic_user_can_select_clinic_and_is_redirected_by_that_branch_role(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $doctor = Role::firstOrCreate(['name' => 'Doctor', 'guard_name' => 'web']);
        $cashier = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $one = Clinic::create(['name' => 'Clinic One', 'slug' => 'clinic-one']);
        $two = Clinic::create(['name' => 'Clinic Two', 'slug' => 'clinic-two']);
        $oneBranch = $one->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $twoBranch = $two->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $twoOtherBranch = $two->branches()->create(['code' => 'EAST', 'name' => 'East']);
        $one->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $two->users()->attach($user, ['status' => 'active']);
        $oneBranch->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $twoBranch->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $twoOtherBranch->users()->attach($user, ['status' => 'active', 'is_default' => false]);
        foreach ([[$oneBranch, $doctor], [$twoBranch, $cashier]] as [$branch, $role]) {
            DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($user)->get(route('tenant.clinic.select'))->assertOk()->assertSee('Clinic One')->assertSee('Clinic Two');
        $user->unsetRelation('roles');
        session()->put('pos_cart', ['foreign-context-item']);
        $this->post(route('tenant.clinic.switch'), ['clinic_id' => $two->id, 'remember' => 1])
            ->assertRedirect(route('cashier.seller-desk'))
            ->assertSessionHas(config('tenancy.session_keys.clinic'), $two->id)
            ->assertSessionHas(config('tenancy.session_keys.branch'), $twoBranch->id);
        $this->assertFalse(session()->has('pos_cart'));
        $this->assertDatabaseHas('clinic_user', ['user_id' => $user->id, 'clinic_id' => $two->id, 'is_default' => true]);
    }

    public function test_user_cannot_select_an_unassigned_clinic(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $owned = Clinic::create(['name' => 'Owned', 'slug' => 'owned']);
        $foreign = Clinic::create(['name' => 'Foreign', 'slug' => 'foreign-choice']);
        $branch = $owned->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $owned->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        $this->actingAs($user)->post(route('tenant.clinic.switch'), ['clinic_id' => $foreign->id])->assertForbidden();
    }
}
