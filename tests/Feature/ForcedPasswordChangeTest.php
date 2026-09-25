<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Clinic;
use App\Models\SubscriptionPlan;
use App\Livewire\Platform\PlatformDashboardComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_temporary_password_user_cannot_access_application_until_password_changes(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->forceFill([
            'is_platform_admin' => true,
            'must_change_password' => true,
        ])->save();

        $this->post(route('login'), [
            'login' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('password.force.edit'));

        $this->get(route('platform.dashboard'))
            ->assertRedirect(route('password.force.edit'));

        $this->get(route('password.force.edit'))
            ->assertOk()
            ->assertSee('Create your private password');

        $this->put(route('password.force.update'), [
            'current_password' => 'password',
            'password' => 'A-new-private-password-2026!',
            'password_confirmation' => 'A-new-private-password-2026!',
        ])->assertRedirect(route('platform.dashboard'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('A-new-private-password-2026!', $user->password));
        $this->assertNotNull($user->last_password_changed_at);
        $this->get(route('platform.dashboard'))->assertOk();
    }

    public function test_temporary_password_cannot_be_reused_as_the_new_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->forceFill(['must_change_password' => true])->save();

        $this->actingAs($user)->put(route('password.force.update'), [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_clinic_onboarding_creates_super_admin_with_one_time_password(): void
    {
        $developer = User::factory()->create();
        $developer->forceFill(['is_platform_admin' => true])->save();
        $plan = SubscriptionPlan::create([
            'name' => 'Starter',
            'code' => 'starter-force-password',
            'included_branches' => 1,
            'base_price' => 0,
            'annual_price' => 0,
            'additional_branch_price' => 0,
            'currency' => 'GHS',
            'trial_days' => 30,
            'grace_days' => 7,
            'tax_rate' => 0,
            'is_active' => true,
        ]);

        Livewire::actingAs($developer)->test(PlatformDashboardComponent::class)
            ->set('tab', 'onboarding')
            ->set('newClinicName', 'New Eye Clinic')
            ->set('newClinicSlug', 'new-eye-clinic')
            ->set('newClinicDeployment', 'hosted')
            ->set('newBranchCode', 'MAIN')
            ->set('newBranchName', 'Main Branch')
            ->set('newBranchTimezone', 'UTC')
            ->set('newAdminName', 'Clinic Administrator')
            ->set('newAdminEmail', 'clinic.admin@example.test')
            ->set('newPlanId', $plan->id)
            ->call('onboardClinic')
            ->assertHasNoErrors()
            ->assertSee('Clinic and Super Admin created successfully.');

        $admin = User::where('email', 'clinic.admin@example.test')->firstOrFail();
        $clinic = Clinic::where('slug', 'new-eye-clinic')->firstOrFail();
        $this->assertTrue($admin->must_change_password);
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertSame($admin->email, $clinic->billing_email);
    }
}
