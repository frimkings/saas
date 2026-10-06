<?php

namespace App\Support;

use App\Services\Reminders\AttentionItems;

/**
 * Sidebar menus for the Tailwind clinic layout (layouts/clinic). Each entry is a link
 * ['label', 'route', 'icon', 'badge'?, 'active'?, 'activePath'?, 'activeWhen'?, 'show'?] or a group
 * ['label', 'icon', 'items' => [...]]. `active` is a routeIs() pattern (or list) when it differs
 * from the link's own route, `activePath` a request()->is() pattern, `activeWhen` a precomputed
 * answer; entries with 'show' => false are left out, and so are groups left empty.
 */
class ClinicNavigation
{
    public static function menu(string $name): array
    {
        return match ($name) {
            'reception' => self::reception(),
            'doctor' => self::doctor(),
            'admin' => self::admin(),
            default => [],
        };
    }

    /** Reception and cashier desk. */
    public static function reception(): array
    {
        $counts = app(AttentionItems::class)->counts('clinic');

        return [
            ['label' => 'Dashboard', 'route' => 'secretary.dashboard', 'icon' => 'fa-tachometer-alt'],
            ['label' => 'Needs Attention', 'route' => 'attention', 'icon' => 'fa-bell', 'badge' => $counts['total'] ?? 0],
            ['label' => 'Patients', 'icon' => 'fa-users', 'items' => [
                ['label' => 'Registry Hub', 'route' => 'secretary.patients'],
                ['label' => 'Appointments', 'route' => 'secretary.appointments', 'badge' => $counts['appointments'] ?? 0],
            ]],
            ['label' => 'Clearance & Spectacles', 'icon' => 'fa-clipboard-check', 'items' => [
                ['label' => 'Patient Clearance', 'route' => 'secretary.patient-clearance'],
                ['label' => 'Spectacles', 'route' => 'secretary.spectacles', 'badge' => $counts['orders'] ?? 0],
            ]],
            ['label' => 'Sales & Billing', 'icon' => 'fa-cash-register', 'items' => [
                ['label' => 'Point of Sale', 'route' => 'cashier.seller-desk'],
                ['label' => 'Sales Records', 'route' => 'cashier.sales-records'],
                ['label' => 'Outstanding Balances', 'route' => 'cashier.outstanding-balances', 'active' => 'cashier.outstanding-balances*'],
            ]],
        ];
    }

    /** Doctors. */
    public static function doctor(): array
    {
        $counts = app(AttentionItems::class)->counts('clinic');

        return [
            ['label' => 'Dashboard', 'route' => 'doctor.dashboard', 'icon' => 'fa-tachometer-alt'],
            ['label' => 'Needs Attention', 'route' => 'attention', 'icon' => 'fa-bell', 'badge' => $counts['total'] ?? 0, 'active' => 'attention*'],
            ['label' => 'Clinical', 'icon' => 'fa-stethoscope', 'items' => [
                ['label' => 'Patient Queue', 'route' => 'doctor.patient-awaiting', 'active' => ['doctor.patient-awaiting', 'doctor.patient-records']],
                ['label' => 'All Records', 'route' => 'doctor.all-records', 'active' => ['doctor.all-records', 'doctor.patient-timeline']],
                ['label' => 'Referrals', 'route' => 'doctor.referrals'],
            ]],
        ];
    }

    /** Super Admins and Managers, by navigation workspace (administration, clinical or optical). */
    public static function admin(): array
    {
        $workspace = NavigationWorkspace::current();
        $user = auth()->user();
        $superAdmin = (bool) $user?->hasRole('Super Admin');
        $communications = array_map(fn ($link) => ['label' => $link[1], 'route' => $link[0], 'icon' => self::iconName($link[2])],
            CommunicationsNavigation::links());

        if ($workspace === 'optical') {
            $links = [];
            foreach (OpticalNavigation::links() as [$route, $label, $icon]) {
                $links[] = ['label' => $label, 'route' => $route, 'icon' => $icon, 'show' => OpticalNavigation::allowed($route), 'activeWhen' => OpticalNavigation::active($route)];
            }
            // Patient recall is for clinic consultations, so not listed here.
            $links[] = ['label' => 'Communications', 'icon' => 'fa-comments', 'items' => array_values(array_filter($communications, fn ($link) => $link['route'] !== 'admin.patient-recall'))];

            return self::visible($links);
        }

        $counts = app(AttentionItems::class)->counts('clinic');
        $menu = [];
        if ($workspace === 'administration') {
            $menu[] = ['label' => 'Business Overview', 'route' => 'admin.dashboard', 'icon' => 'fa-tachometer-alt'];
        } else {
            $menu[] = ['label' => 'Clinical Task Center', 'route' => 'admin.clinical-task-center', 'icon' => 'fa-clipboard-check'];
            $menu[] = ['label' => 'Patients', 'route' => 'secretary.patients', 'icon' => 'fa-users'];
            $menu[] = ['label' => 'Needs Attention', 'route' => 'attention', 'icon' => 'fa-bell', 'badge' => $counts['total'] ?? 0];
            $menu[] = ['label' => 'Appointments', 'route' => 'secretary.appointments', 'icon' => 'fa-calendar-alt', 'badge' => $counts['appointments'] ?? 0];
        }

        if ($workspace === 'clinical') {
            $doctorPages = (bool) $user?->hasAnyRole(['Doctor', 'Super Admin']);
            $menu[] = ['label' => 'Clinical', 'icon' => 'fa-stethoscope', 'items' => [
                ['label' => 'Patients Awaiting', 'route' => 'doctor.patient-awaiting', 'show' => $doctorPages, 'active' => ['doctor.patient-awaiting', 'doctor.patient-records']],
                ['label' => 'All Records', 'route' => 'doctor.all-records', 'show' => $doctorPages, 'active' => ['doctor.all-records', 'doctor.patient-timeline']],
                ['label' => 'Diagnoses', 'route' => 'admin.diagnoses'],
            ]];
            $menu[] = ['label' => 'Clinical Inventory', 'icon' => 'fa-boxes', 'items' => [
                ['label' => 'Categories', 'route' => 'admin.category'],
                ['label' => 'Products', 'route' => 'admin.product'],
                ['label' => 'Stock Receiving', 'route' => 'admin.stock-movements'],
                ['label' => 'Stock Transfers', 'route' => 'admin.stock-transfers'],
                ['label' => 'Inventory Alerts', 'route' => 'admin.inventory-alerts'],
                ['label' => 'Suppliers', 'route' => 'admin.suppliers'],
                ['label' => 'Purchase Orders', 'route' => 'admin.purchase-orders'],
            ]];
        }

        if ($workspace === 'administration') {
            // An optical-only subscriber gets the optical pages that do the same job.
            $opticalOnly = OpticalMode::opticalOnly();
            $menu[] = ['label' => 'Finances', 'icon' => 'fa-chart-line', 'items' => [
                ['label' => FinanceStatements::hasBothLines() ? 'Clinic Sales Reports' : 'Sales Reports', 'route' => $opticalOnly ? 'optical.reports' : 'admin.reports'],
                ['label' => 'Sales Records', 'route' => $opticalOnly ? 'optical.sales' : 'cashier.sales-records', 'active' => $opticalOnly ? 'optical.sales' : ['cashier.sales-records', 'admin.sales-records']],
                ['label' => $opticalOnly ? 'Profit & Loss' : 'Income Statement', 'route' => $opticalOnly ? 'optical.profit' : 'admin.income-statement', 'activePath' => $opticalOnly ? null : 'admin/income-statement*'],
                ['label' => 'Combined Statement', 'route' => 'admin.combined-statement', 'show' => FinanceStatements::canViewCombined($user), 'activePath' => 'admin/combined-statement*'],
                ['label' => 'Expenses', 'route' => $opticalOnly ? 'optical.expenses' : 'admin.expenses', 'activePath' => $opticalOnly ? null : 'admin/expenses*'],
                ['label' => 'Daily Cash Summary', 'route' => 'admin.daily-cash-summary', 'show' => ! $opticalOnly, 'activePath' => 'admin/daily-cash-summary*'],
                ['label' => 'Outstanding Balances', 'route' => 'cashier.outstanding-balances', 'show' => ! $opticalOnly, 'active' => 'cashier.outstanding-balances*'],
                ['label' => 'Lens Outstanding PDF', 'route' => 'admin.lens-outstanding-report'],
                ['label' => 'Quotations', 'route' => 'admin.quotations', 'show' => ! $opticalOnly],
                ['label' => 'Patient Ledger', 'route' => 'admin.patient-ledger', 'show' => ! $opticalOnly],
                ['label' => 'Approvals', 'route' => 'admin.approvals', 'badge' => ApprovalCounts::total(), 'activePath' => 'admin/approvals*'],
            ]];
        }

        if ($workspace === 'clinical') {
            $menu[] = ['label' => 'Insurance', 'icon' => 'fa-shield-alt', 'items' => [
                ['label' => 'Claims', 'route' => 'admin.insurance.claims', 'activePath' => 'admin/insurance/claims*'],
                ['label' => 'Insurers', 'route' => 'admin.insurance.insurers', 'activePath' => 'admin/insurance/insurers*'],
                ['label' => 'Receivables', 'route' => 'admin.insurance.receivables', 'activePath' => 'admin/insurance/receivables*'],
                ['label' => 'Insurer Payments', 'route' => 'admin.insurance.payments', 'activePath' => 'admin/insurance/payments*',
                    'show' => $superAdmin || (bool) $user?->can(\App\Models\InsurerPayment::PERMISSION)],
            ]];
        }

        // Communications: each link only for those who can open it.
        $menu[] = ['label' => 'Communications', 'icon' => 'fa-comments', 'items' => $communications];

        if ($workspace === 'administration') {
            $menu[] = ['label' => 'Staff & Security', 'icon' => 'fa-users-cog', 'items' => [
                ['label' => 'Users', 'route' => 'admin.users', 'show' => $superAdmin],
                ['label' => 'Roles & Permissions', 'route' => 'admin.roles-permissions', 'show' => $superAdmin],
                ['label' => 'Login History', 'route' => 'admin.login-history'],
                ['label' => 'Audit Trail', 'route' => 'admin.audit-trail'],
            ]];
            $menu[] = ['label' => 'System', 'icon' => 'fa-cog', 'show' => $superAdmin, 'items' => [
                ['label' => 'Settings', 'route' => 'admin.settings', 'activePath' => 'admin/settings*'],
                ['label' => 'Backups', 'route' => 'admin.backups'],
                ['label' => 'Branches', 'route' => 'admin.branches', 'activePath' => 'admin/branches*'],
                ['label' => 'Offline Health', 'route' => 'admin.offline-health'],
                ['label' => 'License & Subscription', 'route' => 'admin.license'],
                ['label' => 'Subscription & Billing', 'route' => 'admin.subscription', 'activePath' => 'admin/subscription*',
                    'show' => app(\App\Services\ClinicAccessService::class)->hosted()],
            ]];
        }

        return self::visible($menu);
    }

    /** Drops hidden links and groups left empty. */
    private static function visible(array $menu): array
    {
        $out = [];
        foreach ($menu as $item) {
            if (($item['show'] ?? true) === false) {
                continue;
            }
            if (isset($item['items'])) {
                $item['items'] = self::visible($item['items']);
                if (! $item['items']) {
                    continue;
                }
            }
            $out[] = $item;
        }

        return $out;
    }

    /** 'far fa-envelope' -> 'fa-envelope' (the sidebar adds the style prefix). */
    private static function iconName(string $icon): string
    {
        return collect(explode(' ', $icon))->first(fn ($part) => str_starts_with($part, 'fa-') && $part !== 'fa-fw') ?? 'fa-circle';
    }

    /**
     * Layout for pages every role shares (profile, messages, Needs Attention…): the clinic layout
     * with the person's own menu. Returns [view, data] for Livewire's ->layout().
     */
    public static function sharedLayout(): array
    {
        $user = auth()->user();
        if ($user?->hasAnyRole(['Super Admin', 'Manager'])) {
            return ['layouts.clinic', ['menu' => 'admin']];
        }

        return ['layouts.clinic', ['menu' => $user?->hasRole('Doctor') && ! $user->hasRole('Secretary') ? 'doctor' : 'reception']];
    }

    public static function isActive(array $item): bool
    {
        if (isset($item['items'])) {
            return collect($item['items'])->contains(fn ($child) => self::isActive($child));
        }

        if (array_key_exists('activeWhen', $item)) {
            return (bool) $item['activeWhen'];
        }
        if (! empty($item['activePath']) && request()->is($item['activePath'])) {
            return true;
        }

        return request()->routeIs($item['active'] ?? $item['route']);
    }
}
