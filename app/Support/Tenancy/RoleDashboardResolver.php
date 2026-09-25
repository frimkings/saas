<?php

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Support\Facades\Route;

class RoleDashboardResolver
{
    private const DASHBOARDS = [
        'Super Admin' => 'admin.dashboard',
        'Manager' => 'admin.dashboard',
        'Doctor' => 'doctor.dashboard',
        'Secretary' => 'secretary.dashboard',
        'Cashier' => 'cashier.seller-desk',
        'Optician' => 'optical.dashboard',
    ];

    public function routeName(User $user): string
    {
        $context = app(TenantContext::class);
        if (config('tenancy.enabled') && $context->branch()) {
            // Never route from stale/global Spatie roles. Branch assignments
            // are the authority once a tenant context has been resolved.
            app(BranchRoleManager::class)->hydrate($user, $context->branch());
        }

        if ($remembered = \App\Support\NavigationWorkspace::rememberedRoute($user)) {
            return $remembered;
        }

        if (\App\Support\OpticalMode::opticalOnly($context->clinic())) {
            return 'optical.dashboard';
        }

        foreach (self::DASHBOARDS as $role => $route) {
            if ($user->hasRole($role)) {
                return $route;
            }
        }

        $custom = $user->roles->first(fn ($role) => $role->dashboard_route && Route::has($role->dashboard_route));
        return $custom?->dashboard_route ?? 'user.profile';
    }

    public function url(User $user): string
    {
        return route($this->routeName($user));
    }
}
