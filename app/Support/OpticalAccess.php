<?php

namespace App\Support;

use App\Models\User;

/**
 * Who may open which optical screen. Managers and Super Admins open everything; other staff
 * need the permission a screen asks for, which their role carries:
 *
 *   Optician           everything except management (sales desk, lab, stock, reports)
 *   Optical Assistant  the sales desk: orders, collection, POS, sales, prescriptions, partners
 *   Lab Technician     the lab: workbench, job tracking, stock and stock counts
 *
 * Secretary and Cashier, which could open every optical screen before these were split, keep
 * them all (see the 2026_09_28 migration). Buttons inside a screen still check their own rules.
 */
final class OpticalAccess
{
    public const SALES = 'optical sales';
    public const LAB = 'optical lab';
    public const STOCK = 'optical stock';
    public const REPORTS = 'optical reports';
    public const PERMISSIONS = [self::SALES, self::LAB, self::STOCK, self::REPORTS];

    /** Optical roles and what they carry; the dashboard each starts on. */
    public const ROLES = [
        'Optician' => ['permissions' => self::PERMISSIONS, 'dashboard' => 'optical.dashboard',
            'about' => 'Everything in optical except management: orders, lab, stock and reports.'],
        'Optical Assistant' => ['permissions' => [self::SALES], 'dashboard' => 'optical.orders',
            'about' => 'The sales desk: orders, awaiting collection, POS, sales records, prescriptions and partner clinics.'],
        'Lab Technician' => ['permissions' => [self::LAB, self::STOCK], 'dashboard' => 'optical.lab-workbench',
            'about' => 'The lab: workbench, job tracking, catalogue and stock counts.'],
    ];

    private const MANAGERS = ['Manager', 'Super Admin'];
    private const MANAGER_ONLY = 'managers';

    /** Route => the permissions, any one of which opens it. Routes not listed need any optical permission. */
    private const SCREENS = [
        'optical.orders' => [self::SALES],
        'optical.orders.create' => [self::SALES],
        'optical.orders.docket' => [self::SALES, self::LAB],
        'optical.lab-workbench' => [self::LAB],
        'optical.lab-workbench.print' => [self::LAB],
        'optical.collections' => [self::SALES],
        'optical.jobs' => [self::SALES, self::LAB],
        'optical.pos' => [self::SALES],
        'optical.sales' => [self::SALES],
        'optical.receipt' => [self::SALES],
        'optical.prescriptions' => [self::SALES],
        'optical.partners' => [self::SALES],
        'optical.partners.statement' => [self::SALES],
        'optical.partners.statement.print' => [self::SALES],
        'optical.catalogue' => [self::SALES, self::LAB, self::STOCK],
        'optical.categories' => [self::STOCK],
        'optical.products' => [self::STOCK],
        'optical.stock' => [self::STOCK],
        'optical.stock-counts' => [self::STOCK],
        'optical.reports' => [self::REPORTS],
        'optical.reports.export' => [self::REPORTS],
        'optical.purchasing' => self::MANAGER_ONLY,
        'optical.purchasing.print' => self::MANAGER_ONLY,
        'optical.stock.receive-lenses' => self::MANAGER_ONLY,
        'optical.expenses' => self::MANAGER_ONLY,
        'optical.expenses.receipt' => self::MANAGER_ONLY,
        'optical.profit' => self::MANAGER_ONLY,
        'optical.profit.export' => self::MANAGER_ONLY,
        'optical.settings' => self::MANAGER_ONLY,
        'optical.audit-trail' => self::MANAGER_ONLY,
        'optical.login-history' => self::MANAGER_ONLY,
    ];

    /**
     * Create the optical permissions and roles and give each role its screens. Safe to run
     * again: used by the migration that introduced them and by the roles seeder.
     */
    public static function installRoles(): void
    {
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        foreach ([...self::PERMISSIONS, 'manage users'] as $name) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        // Secretaries and cashiers could open every optical screen before the split; they still can.
        foreach (\Spatie\Permission\Models\Role::where('guard_name', 'web')->whereIn('name', ['Secretary', 'Cashier'])->get() as $role) {
            $role->givePermissionTo(self::PERMISSIONS);
        }
        foreach (self::ROLES as $name => $settings) {
            $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->givePermissionTo($settings['permissions']);
            if (\Illuminate\Support\Facades\Schema::hasColumn('roles', 'dashboard_route') && ! $role->dashboard_route) {
                $role->forceFill(['dashboard_route' => $settings['dashboard']])->save();
            }
        }
        // Managers add and manage staff; the Super Admin role itself stays with Super Admins.
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web'])->givePermissionTo('manage users');
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])->givePermissionTo(\Spatie\Permission\Models\Permission::all());
        $registrar->forgetCachedPermissions();
    }

    public static function isManager(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(self::MANAGERS);
    }

    /** Can this person open this optical route? */
    public static function can(?User $user, string $route): bool
    {
        if (! $user) return false;
        if (self::isManager($user)) return true;
        $needs = self::SCREENS[$route] ?? self::PERMISSIONS;
        if ($needs === self::MANAGER_ONLY) return false;

        return collect($needs)->contains(fn ($permission) => $user->can($permission));
    }

    /** Can this person use the optical workspace at all? */
    public static function any(?User $user): bool
    {
        return self::can($user, 'optical.dashboard');
    }
}
