<?php

namespace App\Support;

use App\Models\User;
use App\Services\ClinicAccessService;
use App\Support\Tenancy\TenantContext;

/** Navigation preferences only; route middleware remains the access authority. */
class NavigationWorkspace
{
    public static function available(User $user): array
    {
        $workspaces = [];
        $manager = $user->hasAnyRole(['Manager', 'Super Admin']);
        if ($manager) {
            $workspaces['administration'] = ['Administration', 'admin.dashboard'];
        }
        if (! OpticalMode::opticalOnly() && app(ClinicAccessService::class)->access('clinical')['allowed']) {
            $route = match (true) {
                $manager => 'admin.clinical-task-center',
                $user->hasRole('Doctor') => 'doctor.dashboard',
                $user->hasRole('Secretary') => 'secretary.dashboard',
                $user->hasRole('Cashier') => 'cashier.seller-desk',
                default => null,
            };
            if ($route) $workspaces['clinical'] = ['Clinical', $route];
        }
        if ($user->hasAnyRole(['Secretary', 'Cashier', 'Optician', 'Manager', 'Super Admin'])
            && app(ClinicAccessService::class)->access('optical')['allowed']) {
            $workspaces['optical'] = ['Optical', 'optical.dashboard'];
        }
        return $workspaces;
    }

    public static function preferenceKey(User $user): string
    {
        $context = app(TenantContext::class);
        return 'navigation_workspace_'.$user->id.'_'.($context->clinic()?->id ?? 0).'_'.($context->branch()?->id ?? 0);
    }

    public static function rememberedRoute(User $user): ?string
    {
        $key = self::preferenceKey($user);
        $choice = session($key, request()->cookie($key));
        return self::available($user)[$choice][1] ?? null;
    }

    public static function current(): string
    {
        if (request()->routeIs('optical.*')) return 'optical';
        if (request()->routeIs('doctor.*', 'secretary.*', 'cashier.*',
            'admin.clinical-task-center', 'admin.diagnoses', 'admin.insurance.*',
            'admin.category', 'admin.product', 'admin.stock-*', 'admin.inventory-alerts',
            'admin.suppliers', 'admin.purchase-orders')) return 'clinical';
        if (request()->routeIs('admin.sms-logs', 'admin.patient-recall', 'staff.messages')) {
            $choice = session(self::preferenceKey(auth()->user()));
            return $choice === 'clinical' ? 'clinical' : 'administration';
        }
        return 'administration';
    }
}
