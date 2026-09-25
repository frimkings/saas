<?php
namespace App\Http\Middleware;
use App\Services\SubscriptionService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
class EnforceSubscriptionWriteAccess
{
    public function handle(Request $request,Closure $next): mixed
    {
        if(!auth()->check()||$request->routeIs('logout','tenant.*','platform.*','password.force.*'))return $next($request);
        $context=app(TenantContext::class);if(!$context->resolved() && config('tenancy.enabled'))return $next($request);
        $service=app(\App\Services\ClinicAccessService::class);
        if ($request->routeIs('livewire.update')) {
            foreach ((array) $request->input('components', []) as $component) {
                $snapshot = json_decode($component['snapshot'] ?? '', true);
                $name = $snapshot['memo']['name'] ?? '';
                if (str_starts_with($name, 'optical.')) {
                    abort_unless($service->access('optical')['allowed'], 403);
                }
                if (\App\Support\OpticalMode::opticalOnly() && (
                    str_starts_with($name, 'doctor.') || str_starts_with($name, 'secretary.')
                    || str_starts_with($name, 'cashier.') || str_starts_with($name, 'cart.')
                )) abort(403, 'Clinical workflows are not included in this subscription.');
            }
        }
        // Optical-only subscribers are sent to the optical page before any plan check on the clinic page.
        if ($request->isMethodSafe() && ($target = RedirectOpticalOnly::targetFor($request))) return new \Illuminate\Http\RedirectResponse($target);
        // A page from the module the plan doesn't include: send the user to their own dashboard.
        if ($request->isMethodSafe() && ($target = $this->otherProductTarget($request, $service))) return $target;
        foreach(config('subscriptions.route_features',[]) as $route=>$feature) if($request->routeIs($route,$route.'.*')) abort_unless($service->access($feature)['allowed'],403,'This feature is not included in your subscription.');
        if($request->isMethodSafe())return $next($request);
        if ($this->isRenewalRequest($request)) return $next($request);
        if ($this->isReadOnlyInteraction($request)) return $next($request);
        $access=$service->access();
        abort_if(!$access['allowed']||$access['read_only'],403,$access['read_only']?'The clinic is in read-only mode until its license or subscription is renewed.':$access['reason']);
        return $next($request);
    }
    private function otherProductTarget(Request $request, \App\Services\ClinicAccessService $service): ?\Illuminate\Http\RedirectResponse
    {
        $clinicalRoute = collect(config('subscriptions.route_features', []))
            ->contains(fn ($feature, $route) => $feature === 'clinical' && $request->routeIs($route, $route.'.*'));
        if ($clinicalRoute && \App\Support\OpticalMode::opticalOnly() && \Illuminate\Support\Facades\Route::has('optical.dashboard')) {
            session()->flash('product_notice', 'Clinic pages are not part of your optical shop subscription.');
            return new \Illuminate\Http\RedirectResponse(route('optical.dashboard'));
        }
        if ($request->routeIs('optical.*') && ! $service->access('optical')['allowed']) {
            $url = app(\App\Support\Tenancy\RoleDashboardResolver::class)->url($request->user());
            if (rtrim($url, '/') === rtrim($request->url(), '/')) return null;
            session()->flash('product_notice', 'The optical shop module is not part of your subscription.');
            return new \Illuminate\Http\RedirectResponse($url);
        }
        return null;
    }

    private function isReadOnlyInteraction(Request $request): bool
    {
        if (!$request->routeIs('livewire.update')) return false;
        $components = $request->input('components', []);
        if (!is_array($components) || !$components) return false;
        foreach ($components as $component) {
            $snapshot = json_decode($component['snapshot'] ?? '', true);
            $name = $snapshot['memo']['name'] ?? '';
            $rules = config('license_read_only')[$name] ?? ['fields' => [], 'methods' => []];
            $filterFields = array_merge($rules['fields'], ['search', 'searchTerm', 'searchQuery', 'perPage',
                'fromDate', 'toDate', 'startDate', 'endDate', 'statusFilter', 'sortField', 'sortDirection']);
            foreach (array_keys($component['updates'] ?? []) as $field) {
                if (!str_starts_with($field, 'paginators.') && !in_array($field, $filterFields, true)) return false;
            }
            foreach ($component['calls'] ?? [] as $call) {
                if (!in_array($call['method'] ?? '', array_merge(['$refresh', 'gotoPage', 'nextPage', 'previousPage', 'setPage'], $rules['methods']), true)) return false;
            }
        }
        // Livewire still verifies snapshots and component authorization; the database
        // guard prevents any accidental writes from hooks while these reads run.
        return true;
    }
    private function isRenewalRequest(Request $request): bool
    {
        if (!$request->routeIs('livewire.update') || !auth()->user()?->hasRole('Super Admin')) return false;
        $components = $request->input('components', []);
        if (!is_array($components) || !$components) return false;
        $allowed = [
            'admin.license-component' => ['activate', '$refresh'],
            'admin.admin-settings-component' => ['setTab', '$refresh'],
            'admin.clinic-subscription-portal-component' => ['requestPlanChange', 'saveBillingProfile', '$refresh'],
        ];
        foreach ($components as $component) {
            $snapshot = json_decode($component['snapshot'] ?? '', true);
            // Livewire verifies the snapshot signature before executing these calls.
            $name = $snapshot['memo']['name'] ?? '';
            if (!isset($allowed[$name])) return false;
            foreach ($component['calls'] ?? [] as $call) {
                if (!in_array($call['method'] ?? '', $allowed[$name], true)) return false;
            }
        }
        return true;
    }
}
