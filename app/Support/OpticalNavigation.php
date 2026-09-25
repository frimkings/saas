<?php

namespace App\Support;

class OpticalNavigation
{
    public static function links(): array
    {
        return [
            ['optical.dashboard', 'Dashboard', 'fa-th-large'],
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

    /** Pages only managers can open. */
    private const MANAGER_ONLY = ['optical.settings', 'optical.purchasing', 'optical.profit', 'optical.expenses'];

    public static function allowed(string $route): bool
    {
        return ! in_array($route, self::MANAGER_ONLY, true) || (bool) auth()->user()?->hasAnyRole(['Manager', 'Super Admin']);
    }

    public static function active(string $route): bool
    {
        return request()->routeIs($route)
            || ($route === 'optical.orders' && request()->routeIs('optical.orders.*'));
    }
}
