<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

class EnsureClinicSettingsConfigured
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->environment('testing')) {
            return $next($request);
        }
        if (!$request->isMethod('GET') || !$request->user()?->hasRole('Super Admin')) {
            return $next($request);
        }
        if (\App\Support\OpticalMode::opticalOnly()) {
            return $next($request);
        }

        // Password replacement must finish before clinic setup. Redirecting its
        // form to settings sends temporary-password users straight back here.
        if ($request->routeIs('admin.settings', 'admin.license', 'admin.subscription*', 'password.force.*', 'logout')) {
            return $next($request);
        }

        try {
            if (!Schema::hasTable('settings')) {
                return $next($request);
            }

            $settings = Setting::getSettings();
        } catch (Throwable $e) {
            return $next($request);
        }

        if (!$settings->needsSetup()) {
            return $next($request);
        }

        return redirect()
            ->route('admin.settings')
            ->with('warning', 'Please complete the clinic settings before using the system.');
    }
}
