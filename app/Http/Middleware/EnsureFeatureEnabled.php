<?php

namespace App\Http\Middleware;

use App\Services\ClinicAccessService;
use Closure;
use Illuminate\Http\Request;

class EnsureFeatureEnabled
{
    /** feature:a,b lets the page open with any one of the features. */
    public function handle(Request $request, Closure $next, string ...$features): mixed
    {
        $service = app(ClinicAccessService::class);
        $access = $service->access($features[0]);
        foreach (array_slice($features, 1) as $feature) {
            if ($access['allowed']) break;
            $access = $service->access($feature);
        }
        abort_unless($access['allowed'], 403, $access['reason']);
        $request->attributes->set('subscription_read_only', $access['read_only']);
        return $next($request);
    }
}
