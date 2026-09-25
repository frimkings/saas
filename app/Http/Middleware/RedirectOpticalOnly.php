<?php

namespace App\Http\Middleware;

use App\Support\OpticalMode;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Clinic-only finance pages have nothing to show an optical-only subscriber,
 * so send them to the optical page that does the same job.
 */
class RedirectOpticalOnly
{
    /** Clinic route (and its sub-routes) => the optical page that does the same job. */
    public const TARGETS = [
        'admin.reports' => 'optical.reports',
        'reports.export.pdf' => 'optical.reports',
        'admin.income-statement' => 'optical.profit',
        'admin.expenses' => 'optical.expenses',
    ];

    /** Where an optical-only subscriber should go instead of this request, if anywhere. */
    public static function targetFor(Request $request): ?string
    {
        if (! $request->route() || ! OpticalMode::opticalOnly()) return null;
        foreach (self::TARGETS as $clinicRoute => $opticalRoute) {
            if ($request->routeIs($clinicRoute, $clinicRoute.'.*')) return route($opticalRoute);
        }

        return null;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        // A plain response: inside Livewire requests the redirect() helper returns Livewire's redirector.
        $target = self::targetFor($request);

        return $target ? new RedirectResponse($target) : $next($request);
    }
}
