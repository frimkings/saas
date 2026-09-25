<?php

namespace Tests\Feature;

use App\Http\Middleware\LogoutInactiveUsers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class LogoutInactiveUsersTest extends TestCase
{
    use DatabaseTransactions;
    public function test_it_logs_out_users_after_30_minutes_of_inactivity()
    {
        $user = User::factory()->create([
            'name' => 'Test Doctor',
            'email' => 'doctor@example.test',
        ]);

        Auth::login($user);

        $request = Request::create('/doctor/dashboard', 'GET');
        $session = new Store('test-session', app('session')->driver()->getHandler());
        $session->start();
        $session->put('auth.last_activity_at', time() - (31 * 60));
        $request->setLaravelSession($session);

        $response = (new LogoutInactiveUsers())->handle($request, fn () => response('ok'));

        $this->assertFalse(Auth::check());
        $this->assertTrue($response->isRedirect(route('login')));
    }

    public function test_it_allows_users_before_the_30_minute_inactivity_limit()
    {
        $user = User::factory()->create([
            'name' => 'Test Doctor',
            'email' => 'doctor@example.test',
        ]);

        Auth::login($user);

        $request = Request::create('/doctor/dashboard', 'GET');
        $session = new Store('test-session', app('session')->driver()->getHandler());
        $session->start();
        $session->put('auth.last_activity_at', time() - (29 * 60));
        $request->setLaravelSession($session);

        $response = (new LogoutInactiveUsers())->handle($request, fn () => response('ok'));

        $this->assertTrue(Auth::check());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertGreaterThan(time() - 5, $session->get('auth.last_activity_at'));
    }
}
