<?php

namespace App\Support;

class OpticalNavigation
{
    public static function links(): array
    {
        return [
            ['optical.dashboard', 'Dashboard', 'fa-th-large'],
            ['optical.attention', 'Needs Attention', 'fa-bell'],
            ['optical.orders', 'Orders', 'fa-clipboard-list'],
            ['optical.lab-workbench', 'Lab Workbench', 'fa-tools'],
            ['optical.collections', 'Awaiting Collection', 'fa-bell'],
            ['optical.jobs', 'Job Tracking', 'fa-hourglass-half'],
            ['optical.pos', 'Retail POS', 'fa-shopping-cart'],
            ['optical.sales', 'Sales Records', 'fa-receipt'],
            ['optical.prescriptions', 'Prescriptions', 'fa-file-medical'],
            ['optical.catalogue', 'Catalogue & Stock', 'fa-boxes'],
            ['optical.purchasing', 'Purchasing', 'fa-truck'],
            ['optical.stock-counts', 'Stock Counts', 'fa-clipboard-check'],
            ['optical.partners', 'Partner Clinics', 'fa-users'],
            ['optical.reports', 'Reports', 'fa-chart-bar'],
            ['optical.profit', 'Profit & Loss', 'fa-balance-scale'],
            ['optical.expenses', 'Expenses', 'fa-wallet'],
            ['optical.settings', 'Settings', 'fa-cog'],
        ];
    }

    /** Whether the signed-in person's role opens this page (App\Support\OpticalAccess). */
    public static function allowed(string $route): bool
    {
        return OpticalAccess::can(auth()->user(), $route);
    }

    /** Adding staff and giving them roles: managers, Super Admins, or anyone given "manage users". */
    public static function canManageStaff(): bool
    {
        $user = auth()->user();
        return (bool) ($user?->hasRole('Super Admin') || $user?->can('manage users'));
    }

    public static function active(string $route): bool
    {
        return request()->routeIs($route)
            || ($route === 'optical.orders' && request()->routeIs('optical.orders.*'));
    }
}
