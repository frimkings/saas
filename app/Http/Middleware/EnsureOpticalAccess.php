<?php

namespace App\Http\Middleware;

use App\Support\OpticalAccess;
use Closure;
use Illuminate\Http\Request;

/** Optical screens by role (App\Support\OpticalAccess): staff open only the screens their role covers. */
class EnsureOpticalAccess
{
    public function handle(Request $request, Closure $next): mixed
    {
        $route = $request->route()?->getName();
        if ($route && str_starts_with($route, 'optical.')) {
            abort_unless(OpticalAccess::can($request->user(), $route), 403, 'Your role does not include this optical screen. Ask a manager if you need it.');
        }

        return $next($request);
    }
}
