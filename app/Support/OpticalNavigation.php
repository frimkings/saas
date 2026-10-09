<?php

namespace App\Support;

class OpticalNavigation
{
    /**
     * The sidebar, grouped by area of work. Each role sees only the links it can open, so a
     * receptionist (Optical Assistant) sees the front desk, a lab technician the lab and
     * stock, and managers everything. Groups without a link the person can open are hidden.
     *
     * @return array<int, array{key: ?string, label: ?string, links: array<int, array{0: string, 1: string, 2: string}>}>
     */
    public static function groups(): array
    {
        return [
            ['key' => null, 'label' => null, 'links' => [
                ['optical.dashboard', 'Dashboard', 'fa-th-large'],
                ['optical.attention', 'Needs Attention', 'fa-bell'],
            ]],
            ['key' => 'desk', 'label' => 'Front desk', 'links' => [
                // Awaiting Collection opens from the Orders page (Ready filter and collection reminders).
                ['optical.orders', 'Orders', 'fa-clipboard-list'],
                ['optical.pos', 'Retail POS', 'fa-shopping-cart'],
                ['optical.sales', 'Sales Records', 'fa-receipt'],
                ['optical.prescriptions', 'Prescriptions', 'fa-file-medical'],
                ['optical.partners', 'Partner Clinics', 'fa-users'],
            ]],
            ['key' => 'lab', 'label' => 'Lab', 'links' => [
                ['optical.lab-workbench', 'Lab Workbench', 'fa-tools'],
                ['optical.jobs', 'Job Tracking', 'fa-hourglass-half'],
            ]],
            ['key' => 'stock', 'label' => 'Stock', 'links' => [
                // Products and categories are tabs of Catalogue & Stock.
                ['optical.catalogue', 'Catalogue & Stock', 'fa-boxes'],
                ['optical.stock', 'Receiving & Batches', 'fa-dolly'],
                ['optical.purchasing', 'Purchasing', 'fa-truck'],
                ['optical.stock-counts', 'Stock Counts', 'fa-clipboard-check'],
            ]],
            ['key' => 'money', 'label' => 'Money', 'links' => [
                ['optical.reports', 'Reports', 'fa-chart-bar'],
                ['optical.profit', 'Profit & Loss', 'fa-balance-scale'],
                ['optical.expenses', 'Expenses', 'fa-wallet'],
            ]],
            ['key' => 'admin', 'label' => 'Admin', 'links' => [
                ['optical.settings', 'Settings', 'fa-cog'],
                ['admin.users', 'Staff & roles', 'fa-user-shield'],
                ['optical.audit-trail', 'Audit Trail', 'fa-history'],
                ['optical.login-history', 'Login History', 'fa-sign-in-alt'],
            ]],
        ];
    }

    /** Groups each role starts with open; the group of the page being viewed is always open. */
    public const OPEN_FOR_ROLE = [
        'Optical Assistant' => ['desk'],
        'Lab Technician' => ['lab', 'stock'],
        'Optician' => ['desk', 'lab'],
    ];

    /** The optical pages as one list (phone menu, and the optical menu of a combined clinic). */
    public static function links(): array
    {
        return array_values(array_filter(array_merge(...array_column(self::groups(), 'links')),
            fn ($link) => str_starts_with($link[0], 'optical.') && ! in_array($link[0], ['optical.audit-trail', 'optical.login-history'], true)));
    }

    /** Whether the signed-in person's role opens this page (App\Support\OpticalAccess). */
    public static function allowed(string $route): bool
    {
        return match ($route) {
            'admin.users' => self::canManageStaff(),
            'optical.audit-trail', 'optical.login-history' => \App\Services\LicenseService::has(Feature::AUDIT_TRAIL) && OpticalAccess::can(auth()->user(), $route),
            default => OpticalAccess::can(auth()->user(), $route),
        };
    }

    /** The URL of a menu link (the staff screen links back to optical). */
    public static function url(string $route): string
    {
        return $route === 'admin.users' ? route('admin.users', ['from' => 'optical']) : route($route);
    }

    /** Whether a group starts open for this person on this page. */
    public static function opensFor(array $group): bool
    {
        if ($group['key'] === null) return true;
        foreach ($group['links'] as [$route]) if (self::active($route)) return true;
        $roles = auth()->user()?->getRoleNames()->all() ?? [];
        foreach ($roles as $role) if (in_array($group['key'], self::OPEN_FOR_ROLE[$role] ?? [], true)) return true;
        return false;
    }

    /**
     * Pages opened with wire:navigate (no full reload). Sales Records shares a script with the
     * clinic side that isn't navigate-safe yet, so it still loads normally.
     */
    public static function navigable(string $route): bool
    {
        return $route !== 'optical.sales';
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
            || ($route === 'optical.orders' && request()->routeIs('optical.orders.*', 'optical.collections'))
            || ($route === 'optical.catalogue' && request()->routeIs('optical.products', 'optical.categories'))
            || ($route === 'optical.stock' && request()->routeIs('optical.stock.*'))
            || ($route === 'admin.users' && request()->routeIs('admin.users'));
    }
}
