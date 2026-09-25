<?php

namespace App\Http\Middleware;

use App\Services\ClinicAccessService;
use Closure;
use Illuminate\Http\Request;

class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): mixed
    {
        $access = app(ClinicAccessService::class)->access($feature);
        abort_unless($access['allowed'], 403, $access['reason']);
        $request->attributes->set('subscription_read_only', $access['read_only']);
        return $next($request);
    }
}
