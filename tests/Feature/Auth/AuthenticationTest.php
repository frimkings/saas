<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\Clinic;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_multi_clinic_login_screen_shows_developer_details_not_a_clinic()
    {
        config()->set('tenancy.enabled', true);
        \App\Models\Setting::withoutGlobalScopes()->orderBy('id')->first()?->update(['clinic_name' => 'Some Single Clinic']);
        \App\Models\PlatformSetting::put(['support_name' => 'Acme Dev Studio', 'support_phone' => '0241112222', 'support_email' => 'dev@acme.test']);

        $this->get('/login')->assertOk()
            ->assertSee('Acme Dev Studio')->assertSee('0241112222')->assertSee('dev@acme.test')
            ->assertDontSee('Some Single Clinic');
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'login'    => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect();
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'login'    => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_user_can_login_and_login_is_audited_with_tenancy_enabled()
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $clinic = Clinic::create(['name' => 'Login Clinic', 'slug' => 'login-clinic']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $clinic->users()->attach($user, ['status' => 'active']);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        $this->post('/login', ['login' => $user->email, 'password' => 'password'])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('login_logs', [
            'user_id' => $user->id, 'clinic_id' => $clinic->id, 'branch_id' => $branch->id,
        ]);
    }

    public function test_platform_admin_can_logout_from_platform_dashboard()
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($user);
        session(['workspace_mode' => 'platform']);

        $response = $this->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
