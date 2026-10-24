<?php

namespace App\Http\Middleware;

use App\Services\ClinicAccessService;
use App\Support\PlanLeftovers;
use Closure;
use Illuminate\Http\Request;

/**
 * Like `feature`, but the page also opens while the clinic still has work on the feature from before a
 * downgrade (App\Support\PlanLeftovers), so it can be finished. Starting new work is stopped by the page.
 */
class EnsureFeatureOrLeftover
{
    public function handle(Request $request, Closure $next, string $feature): mixed
    {
        $access = app(ClinicAccessService::class)->access($feature);
        if (! $access['allowed'] && ! PlanLeftovers::has($feature)) {
            abort(403, $access['reason']);
        }
        $request->attributes->set('subscription_read_only', $access['read_only']);

        return $next($request);
    }
}
