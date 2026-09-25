<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceModeSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dual_access_admin_can_choose_platform_or_clinic_mode(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $user->forceFill(['is_platform_admin' => true])->save();
        $clinic = Clinic::create(['name' => 'Clinic', 'slug' => 'dual-clinic']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $clinic->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        $this->actingAs($user)->get(route('tenant.mode.select'))->assertOk()->assertSee('Platform Administration')->assertSee('Clinic Workspace');
        $this->post(route('tenant.mode.switch'), ['mode' => 'platform'])
            ->assertRedirect(route('platform.dashboard'))->assertSessionHas('workspace_mode', 'platform');
        $this->get(route('platform.dashboard'))->assertOk();

        $this->post(route('tenant.mode.switch'), ['mode' => 'clinic'])
            ->assertRedirect(route('tenant.clinic.select'))->assertSessionHas('workspace_mode', 'clinic');
    }

    public function test_dual_access_admin_cannot_enter_platform_without_selecting_platform_mode(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $user->forceFill(['is_platform_admin' => true])->save();
        $clinic = Clinic::create(['name' => 'Clinic', 'slug' => 'guarded-clinic']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $clinic->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        $this->actingAs($user)->get(route('platform.dashboard'))->assertRedirect(route('tenant.mode.select'));
    }

    public function test_dual_access_admin_can_sign_directly_into_platform_workspace(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $user->forceFill(['is_platform_admin' => true])->save();
        $clinic = Clinic::create(['name' => 'Clinic', 'slug' => 'direct-platform-clinic']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $clinic->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        $this->post(route('login'), [
            'login' => $user->email,
            'password' => 'password',
            'workspace' => 'platform',
        ])->assertRedirect(route('platform.dashboard'))
            ->assertSessionHas('workspace_mode', 'platform');
    }

    public function test_workspace_choice_can_be_remembered_for_future_logins(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $user->forceFill(['is_platform_admin' => true])->save();
        $clinic = Clinic::create(['name' => 'Clinic', 'slug' => 'remember-platform-clinic']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $clinic->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        $this->actingAs($user)->post(route('tenant.mode.switch'), [
            'mode' => 'platform',
            'remember_workspace' => '1',
        ])->assertRedirect(route('platform.dashboard'));

        $this->assertSame('platform', $user->fresh()->preferred_workspace);
        auth()->logout();

        $this->post(route('login'), [
            'login' => $user->email,
            'password' => 'password',
            'workspace' => 'auto',
        ])->assertRedirect(route('platform.dashboard'));
    }

    public function test_platform_navigation_tabs_have_bookmarkable_urls(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($user)
            ->get(route('platform.dashboard', ['tab' => 'plans']))
            ->assertOk()
            ->assertSee('Subscription Plans')
            ->assertSee('New plan') // opens the plan panel; its form is not rendered until then
            ->assertSee('tab=plans', false);

        $this->get(route('platform.dashboard', ['tab' => 'onboarding']))
            ->assertOk()
            ->assertSee('Clinic Onboarding')
            ->assertSee('Create clinic and Super Admin');
    }
}
