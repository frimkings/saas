<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Clinic;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('tenancy.enabled')) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($request->routeIs('password.force.*')) {
            return $next($request);
        }

        if ($user->is_platform_admin && ($request->routeIs('platform.*') || $request->routeIs('tenant.mode.*'))) {
            return $next($request);
        }

        if ($user->is_platform_admin && $request->session()->get('workspace_mode') === 'platform') {
            // Livewire actions use their own update route. Redirecting that POST
            // returns HTML where Livewire requires JSON and breaks every action.
            if ($request->routeIs('livewire.*', '*.livewire.*', 'logout')) {
                return $next($request);
            }

            return redirect()->route('platform.dashboard');
        }

        $clinic = $this->resolveClinic($request);
        abort_unless($clinic, 403, 'You do not have access to an active clinic.');

        [$branch, $authorizedBranchIds] = $this->resolveBranch($request, $clinic);
        abort_unless($branch, 403, 'You do not have access to an active branch for this clinic.');

        $this->context->set($user, $clinic, $branch, $authorizedBranchIds);
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($user, $branch);
        $request->session()->put(config('tenancy.session_keys.clinic'), $clinic->id);
        $request->session()->put(config('tenancy.session_keys.branch'), $branch->id);

        return $next($request);
    }

    private function resolveClinic(Request $request): ?Clinic
    {
        $query = $request->user()->clinics()
            ->where('clinics.status', 'active')
            ->wherePivot('status', 'active');

        $requestedId = (int) $request->session()->get(config('tenancy.session_keys.clinic'), 0);
        if ($requestedId > 0) {
            $selected = (clone $query)->whereKey($requestedId)->first();
            if ($selected) {
                return $selected;
            }
        }

        return $query->orderByDesc('clinic_user.is_default')->orderBy('clinics.id')->first();
    }

    private function resolveBranch(Request $request, Clinic $clinic): array
    {
        $query = $request->user()->branches()
            ->where('branches.clinic_id', $clinic->id)
            ->where('branches.is_active', true)
            ->wherePivot('status', 'active');

        $authorizedBranchIds = (clone $query)
            ->pluck('branches.id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if ($authorizedBranchIds === []) {
            return [null, []];
        }

        $requestedId = (int) $request->session()->get(config('tenancy.session_keys.branch'), 0);
        if ($requestedId > 0 && in_array($requestedId, $authorizedBranchIds, true)) {
            return [(clone $query)->whereKey($requestedId)->first(), $authorizedBranchIds];
        }

        $branch = (clone $query)
            ->orderByDesc('branch_user.is_default')
            ->orderByDesc('branches.is_default')
            ->orderBy('branches.id')
            ->first();

        return [$branch, $authorizedBranchIds];
    }
}
