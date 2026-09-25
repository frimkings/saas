<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MakePlatformAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_fresh_platform_administrator(): void
    {
        $this->artisan('platform:create-admin', [
            'email' => 'developer@example.test',
            '--name' => 'Platform Developer',
        ])->assertSuccessful();

        $user = User::where('email', 'developer@example.test')->firstOrFail();

        $this->assertSame('Platform Developer', $user->name);
        $this->assertTrue($user->is_platform_admin);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_the_original_command_still_promotes_an_existing_user(): void
    {
        $user = User::factory()->create(['is_platform_admin' => false]);

        $this->artisan('platform:make-admin', ['email' => $user->email])->assertSuccessful();

        $this->assertTrue($user->fresh()->is_platform_admin);
    }
}
