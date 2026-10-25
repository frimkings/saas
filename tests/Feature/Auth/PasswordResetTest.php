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

    private function resetWith(User $user, string $code)
    {
        return $this->withSession(['offline_reset_email' => $user->email])->post('/reset-password', [
            'code' => $code, 'password' => 'SecurePass10', 'password_confirmation' => 'SecurePass10',
        ]);
    }

    public function test_password_can_be_reset_with_the_managers_code()
    {
        $user = User::factory()->create();
        $request = PasswordResetRequest::create(['email' => $user->email, 'status' => 'approved']);
        $code = $request->issueCode();
        $this->resetWith($user, substr($code, 0, 3).' '.substr($code, 3))->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('SecurePass10', $user->fresh()->password));
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertNull($request->fresh()->code_hash);
    }

    public function test_an_approval_alone_does_not_let_anyone_set_the_password()
    {
        $user = User::factory()->create();
        $before = $user->password;
        $request = PasswordResetRequest::create(['email' => $user->email, 'status' => 'approved']);

        // Approved before codes existed: a code is needed first.
        $this->resetWith($user, '123456')->assertSessionHasErrors('code');
        $code = $request->issueCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->resetWith($user, $wrong)->assertSessionHasErrors('code');
        $this->assertSame($before, $user->fresh()->password);

        // An expired code doesn't work either.
        $request->update(['code_expires_at' => now()->subMinute()]);
        $this->resetWith($user, $code)->assertSessionHasErrors('code');
        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_too_many_wrong_codes_close_the_request()
    {
        $user = User::factory()->create();
        $request = PasswordResetRequest::create(['email' => $user->email, 'status' => 'approved']);
        $code = $request->issueCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 1; $i < PasswordResetRequest::CODE_TRIES; $i++) $this->resetWith($user, $wrong);
        $this->resetWith($user, $wrong)->assertRedirect(route('password.request'));

        $this->assertSame('rejected', $request->fresh()->status);
        $this->resetWith($user, $code)->assertRedirect(route('password.request'));
        $this->assertFalse(Hash::check('SecurePass10', $user->fresh()->password));
    }
}
