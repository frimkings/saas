<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Support\Tenancy\RoleDashboardResolver;
use App\Support\Tenancy\TenantContext;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     *
     * @param  \App\Http\Requests\Auth\LoginRequest  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(LoginRequest $request)
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = Auth::user();

        if ($user->must_change_password) {
            return redirect()->route('password.force.edit');
        }

        $activeClinics = $user->clinics()
            ->where('clinics.status', 'active')
            ->wherePivot('status', 'active');
        $clinicCount = (clone $activeClinics)->count();
        $requestedWorkspace = $request->validated('workspace', 'auto');

        if ($user->is_platform_admin && $requestedWorkspace === 'platform') {
            if ($request->boolean('remember_workspace')) {
                $user->forceFill(['preferred_workspace' => 'platform'])->save();
            }

            $request->session()->put('workspace_mode', 'platform');
            app(TenantContext::class)->clear();
            $request->session()->forget([
                config('tenancy.session_keys.clinic'),
                config('tenancy.session_keys.branch'),
            ]);

            return redirect()->route('platform.dashboard');
        }

        if ($user->is_platform_admin && $clinicCount > 0 && $requestedWorkspace === 'clinic') {
            if ($request->boolean('remember_workspace')) {
                $user->forceFill(['preferred_workspace' => 'clinic'])->save();
            }

            $request->session()->put('workspace_mode', 'clinic');

            return $clinicCount > 1
                ? redirect()->route('tenant.clinic.select')
                : redirect()->to(app(RoleDashboardResolver::class)->url($user));
        }

        if ($user->is_platform_admin && $clinicCount > 0) {
            if ($user->preferred_workspace === 'platform') {
                $request->session()->put('workspace_mode', 'platform');
                app(TenantContext::class)->clear();

                return redirect()->route('platform.dashboard');
            }

            if ($user->preferred_workspace === 'clinic') {
                $request->session()->put('workspace_mode', 'clinic');

                return $clinicCount > 1
                    ? redirect()->route('tenant.clinic.select')
                    : redirect()->to(app(RoleDashboardResolver::class)->url($user));
            }

            return redirect()->route('tenant.mode.select');
        }

        if ($user->is_platform_admin && $clinicCount === 0) {
            $request->session()->put('workspace_mode', 'platform');
            return redirect()->intended(route('platform.dashboard'));
        }

        if ($clinicCount > 1) {
            return redirect()->route('tenant.clinic.select');
        }

        return redirect()->to(app(RoleDashboardResolver::class)->url($user));
    }

    /**
     * Destroy an authenticated session.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request)
    {
        if (app()->bound(TenantContext::class)) {
            app(TenantContext::class)->clear();
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
