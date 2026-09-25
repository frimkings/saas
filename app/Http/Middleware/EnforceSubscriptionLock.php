<?php

namespace App\Http\Middleware;

use App\Services\ClinicAccessService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Shuts a lapsed clinic out once its grace day is over. Staff are sent to the "payment has not
 * been made" / "contact support" page; Super Admins can only reach the renewal page (subscription
 * portal for hosted clinics, license page for offline installs). User accounts are never changed,
 * so paying or activating a new license reopens the clinic immediately.
 */
class EnforceSubscriptionLock
{
    /** Routes a locked clinic's admin may still use to renew. */
    private const ADMIN_ROUTES = [
        'admin.subscription', 'admin.subscription.invoice', 'admin.subscription.receipt', 'admin.license',
        'notifications.unread-count', 'messages.unread-count',
    ];

    /** Livewire components on those pages (including the navbar widgets in the admin layout). */
    private const ADMIN_COMPONENTS = [
        'admin.clinic-subscription-portal-component', 'admin.license-component',
        'notification-bell-component', 'staff-messages-dropdown-component',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        if (!auth()->check() || $request->routeIs('logout', 'tenant.*', 'platform.*', 'password.force.*', 'subscription.locked')) {
            return $next($request);
        }
        if (config('tenancy.enabled') && !app(TenantContext::class)->resolved()) {
            return $next($request);
        }

        $stage = app(ClinicAccessService::class)->stage();
        if (!$stage || !$stage->blocksStaff()) {
            return $next($request);
        }

        if (auth()->user()->hasRole('Super Admin')) {
            if ($request->routeIs(...self::ADMIN_ROUTES) || $this->onlyAdminComponents($request)) {
                return $next($request);
            }
            return $this->deny($request, route($stage->renewalRoute()), 'Renew the clinic subscription to continue.');
        }

        return $this->deny($request, route('subscription.locked'), 'This clinic is locked until its subscription is renewed.');
    }

    private function onlyAdminComponents(Request $request): bool
    {
        if (!$request->routeIs('livewire.update')) {
            return false;
        }

        $components = (array) $request->input('components', []);
        foreach ($components as $component) {
            $name = json_decode($component['snapshot'] ?? '', true)['memo']['name'] ?? '';
            if (!in_array($name, self::ADMIN_COMPONENTS, true)) {
                return false;
            }
        }

        return $components !== [];
    }

    private function deny(Request $request, string $to, string $message): mixed
    {
        // Livewire actions and polling get a plain 403; the next page load is redirected.
        if ($request->routeIs('livewire.update') || $request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->to($to);
    }
}
