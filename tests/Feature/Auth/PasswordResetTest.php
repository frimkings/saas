<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\PasswordResetRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered()
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested()
    {
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('request_status', 'submitted');
        $this->assertDatabaseHas('password_reset_requests', ['email' => $user->email, 'status' => 'pending']);
    }

    public function test_reset_password_screen_can_be_rendered()
    {
        $user = User::factory()->create();
        PasswordResetRequest::create(['email' => $user->email, 'status' => 'approved']);
        $this->withSession(['offline_reset_email' => $user->email])->get('/reset-password')->assertOk();
    }

    public function test_password_can_be_reset_with_valid_token()
    {
        $user = User::factory()->create();
        $request = PasswordResetRequest::create(['email' => $user->email, 'status' => 'approved']);
        $this->withSession(['offline_reset_email' => $user->email])->post('/reset-password', [
            'password' => 'SecurePass10', 'password_confirmation' => 'SecurePass10',
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('SecurePass10', $user->fresh()->password));
        $this->assertSame('completed', $request->fresh()->status);
    }
}
