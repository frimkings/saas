<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantBackupAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        // Full database backups contain every clinic. They are installation-operator
        // tools and must never be exposed to a clinic administrator on hosted mode.
        abort_if(config('tenancy.enabled') && ! app()->environment('local'), 403,
            'Full installation backups are restricted to the hosting operator.');
        return $next($request);
    }
}
