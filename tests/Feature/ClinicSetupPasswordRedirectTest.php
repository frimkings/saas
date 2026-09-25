<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureClinicSettingsConfigured;
use App\Http\Middleware\RequirePasswordChange;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClinicSetupPasswordRedirectTest extends TestCase
{
    public function test_password_form_is_not_redirected_to_clinic_setup_in_local_environment(): void
    {
        // Setup middleware intentionally skips testing environments; exercise
        // the real local path without touching clinic data.
        $this->app['env'] = 'local';
        Schema::shouldReceive('hasTable')->never();
        $request = Request::create('/change-temporary-password', 'GET');
        $request->setRouteResolver(fn () => (new Route('GET', '/change-temporary-password', []))
            ->name('password.force.edit'));
        $request->setUserResolver(fn () => new class {
            public bool $must_change_password = true;
            public function hasRole($role): bool { return $role === 'Super Admin'; }
        });

        try {
            $response = (new RequirePasswordChange)->handle($request, fn ($request) =>
                (new EnsureClinicSettingsConfigured)->handle($request, fn () => response('Password form', 200))
            );
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('Password form', $response->getContent());
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
