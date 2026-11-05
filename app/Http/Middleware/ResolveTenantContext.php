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

        // The public pricing page is the same for everyone, signed in or not.
        if ($request->routeIs('password.force.*', 'pricing', 'pricing.request')) {
            return $next($request);
        }

        if ($user->is_platform_admin && ($request->routeIs('platform.*') || $request->routeIs('tenant.mode.*'))) {
            return $next($request);
        }

        // Platform tours are kept outside any clinic (App\Support\Guides stores them under clinic 0).
        if ($user->is_platform_admin && $request->routeIs('guides.record') && str_starts_with((string) $request->route('guide'), 'platform.')) {
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

        [$clinic, $clinics] = $this->resolveClinic($request);
        abort_unless($clinic, 403, 'You do not have access to an active clinic.');

        [$branch, $branches] = $this->resolveBranch($request, $clinic);
        abort_unless($branch, 403, 'You do not have access to an active branch for this clinic.');

        if ($stale = $this->refuseStalePage($request, $clinic, $branch, $clinics, $branches)) {
            return $stale;
        }

        $this->context->set($user, $clinic, $branch, $branches->pluck('id')->map(static fn ($id) => (int) $id)->all());
        // The navbar's clinic/branch switcher lists these; save it querying them again.
        $this->context->setAvailable($clinics, $branches->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values());
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($user, $branch);
        $request->session()->put(config('tenancy.session_keys.clinic'), $clinic->id);
        $request->session()->put(config('tenancy.session_keys.branch'), $branch->id);

        return $next($request);
    }

    /**
     * Every tab shares one session. A page opened in one business or branch, then used after a switch in
     * another tab, would save into the new one; its stamp (layouts/partials/tenant-guard) says where it was
     * opened, and a change from a page that no longer matches is refused. Requests without a stamp (older
     * pages, scripts) and the switchers themselves pass.
     */
    private function refuseStalePage(Request $request, Clinic $clinic, Branch $branch, $clinics, $branches): ?Response
    {
        if ($request->isMethodSafe() || $request->routeIs('tenant.*', 'logout')) {
            return null;
        }
        $stamp = (string) ($request->header('X-Tenant-Context') ?: $request->input('_tenant_context', ''));
        if (! preg_match('/^(\d+):(\d+)$/', $stamp, $m) || ((int) $m[1] === (int) $clinic->id && (int) $m[2] === (int) $branch->id)) {
            return null;
        }

        // Name only places this user belongs to.
        if ((int) $m[1] !== (int) $clinic->id) {
            $was = $clinics->firstWhere('id', (int) $m[1])?->name ?? 'another business';
            $now = $clinic->name;
        } else {
            $was = 'the '.($branches->firstWhere('id', (int) $m[2])?->name ?? 'other').' branch';
            $now = 'the '.$branch->name.' branch';
        }
        $message = "This page was opened in {$was}, but you have since switched to {$now} in another tab. Nothing was saved. Reload to carry on in {$now}, or switch back first.";

        if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
            return response()->json(['message' => $message, 'stale_tenant' => true], 409);
        }

        return redirect()->back()->withInput($request->except(['password', 'password_confirmation', '_token', '_tenant_context']))->with('error', $message);
    }

    /** The selected (or default) clinic and all of the user's active clinics, default first. */
    private function resolveClinic(Request $request): array
    {
        $clinics = $request->user()->clinics()
            ->where('clinics.status', 'active')
            ->wherePivot('status', 'active')
            ->orderByDesc('clinic_user.is_default')
            ->orderBy('clinics.id')
            ->get();

        $requestedId = (int) $request->session()->get(config('tenancy.session_keys.clinic'), 0);

        return [$clinics->firstWhere('id', $requestedId) ?? $clinics->first(), $clinics];
    }

    /** The selected (or default) branch and all of the user's active branches in the clinic. */
    private function resolveBranch(Request $request, Clinic $clinic): array
    {
        $branches = $request->user()->branches()
            ->where('branches.clinic_id', $clinic->id)
            ->where('branches.is_active', true)
            ->wherePivot('status', 'active')
            ->orderByDesc('branch_user.is_default')
            ->orderByDesc('branches.is_default')
            ->orderBy('branches.id')
            ->get();

        $requestedId = (int) $request->session()->get(config('tenancy.session_keys.branch'), 0);

        return [$branches->firstWhere('id', $requestedId) ?? $branches->first(), $branches];
    }
}
