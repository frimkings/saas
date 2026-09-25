<?php

namespace Tests\Feature;

use App\Livewire\Admin\BranchManagementComponent;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SubscriptionBranchLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_plan_enforces_branch_limit_and_allows_reactivation_after_capacity_is_freed(): void
    {
        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole($role);
        $clinic = Clinic::create(['name' => 'Limited Clinic', 'slug' => 'limited-clinic']);
        $main = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $clinic->users()->attach($admin, ['status' => 'active']);
        $main->users()->attach($admin, ['status' => 'active', 'is_default' => true]);
        $plan = SubscriptionPlan::create(['name' => 'One Branch', 'code' => 'one-branch', 'included_branches' => 1, 'base_price' => 100, 'additional_branch_price' => 25, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active']);

        Livewire::actingAs($admin)->test(BranchManagementComponent::class)
            ->set('code', 'EAST')->set('name', 'East')->call('save')->assertHasErrors(['code']);
        $this->assertDatabaseMissing('branches', ['clinic_id' => $clinic->id, 'code' => 'EAST']);

        $main->update(['is_active' => false, 'is_default' => false]);
        Livewire::actingAs($admin)->test(BranchManagementComponent::class)
            ->set('code', 'EAST')->set('name', 'East')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('branches', ['clinic_id' => $clinic->id, 'code' => 'EAST', 'is_active' => true]);
    }

    public function test_platform_dashboard_requires_platform_admin_flag(): void
    {
        config()->set('tenancy.enabled', true);
        $ordinary = User::factory()->create();
        $this->actingAs($ordinary)->get(route('platform.dashboard'))->assertForbidden();

        $platform = User::factory()->create();
        $platform->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($platform)->get(route('platform.dashboard'))->assertOk();
    }
}
