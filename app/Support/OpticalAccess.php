<?php

namespace App\Support;

use App\Models\User;

/**
 * Who may open which optical screen, and do which job steps. Managers and Super Admins do
 * everything; other staff need the permission a screen or step asks for, which their role carries:
 *
 *   Secretary, Cashier, Optical Assistant   the front desk: orders, incoming orders, POS, sales,
 *                                           prescriptions, partner clinics; take payments and
 *                                           hand glasses over (Collected)
 *   Optician                                the lab bench: workbench and job tracking, sending
 *                                           jobs to the lab, glazing, marking them ready
 *   Lab Technician                          the lab and stock: bench steps plus catalogue,
 *                                           receiving and stock counts
 *
 * Everyone who can open them looks lenses up in the catalogue and lens grid; changing the
 * catalogue stays with managers. Decided 2026-11-17 (front desk = Secretary, bench = Optician).
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
        'Optician' => ['permissions' => [self::LAB], 'dashboard' => 'optical.lab-workbench',
            'about' => 'The lab bench: workbench and job tracking. Sends jobs to the lab, glazes and marks them ready; looks lenses up in the catalogue.'],
        'Optical Assistant' => ['permissions' => [self::SALES], 'dashboard' => 'optical.orders',
            'about' => 'The sales desk: orders, awaiting collection, POS, sales records, prescriptions and partner clinics.'],
        'Lab Technician' => ['permissions' => [self::LAB, self::STOCK], 'dashboard' => 'optical.lab-workbench',
            'about' => 'The lab: workbench, job tracking, catalogue and stock counts.'],
    ];

    /** Clinic roles that work the optical front desk. */
    public const DESK_ROLES = ['Secretary', 'Cashier'];

    /** Job steps done at the bench; handing over (Collected) and money are the front desk's. */
    public const LAB_STEPS = ['Sent to Lab', 'In Production', 'Ready for Collection', 'Ready'];

    private const MANAGERS = ['Manager', 'Super Admin'];
    private const MANAGER_ONLY = 'managers';

    /** Route => the permissions, any one of which opens it. Routes not listed need any optical permission. */
    private const SCREENS = [
        'optical.orders' => [self::SALES],
        'optical.orders.create' => [self::SALES],
        'optical.requests' => [self::SALES],
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
        'optical.customers' => [self::SALES], // old link, redirects to partners
        'optical.partners.statement' => [self::SALES],
        'optical.partners.statement.print' => [self::SALES],
        'optical.catalogue' => [self::SALES, self::LAB, self::STOCK],
        'optical.lens-grid' => [self::SALES, self::LAB, self::STOCK],
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
        // The clinic's front desk works the optical front desk only.
        foreach (\Spatie\Permission\Models\Role::where('guard_name', 'web')->whereIn('name', self::DESK_ROLES)->get() as $role) {
            self::syncOptical($role, [self::SALES]);
            // Staff & roles stays with managers, so the desk can't hand itself the lab or a manager's role.
            if ($role->hasPermissionTo('manage users')) $role->revokePermissionTo('manage users');
        }
        foreach (self::ROLES as $name => $settings) {
            $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            self::syncOptical($role, $settings['permissions']);
            if (\Illuminate\Support\Facades\Schema::hasColumn('roles', 'dashboard_route') && ! $role->dashboard_route) {
                $role->forceFill(['dashboard_route' => $settings['dashboard']])->save();
            }
        }
        // Managers add and manage staff; the Super Admin role itself stays with Super Admins.
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web'])->givePermissionTo('manage users');
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])->givePermissionTo(\Spatie\Permission\Models\Permission::all());
        $registrar->forgetCachedPermissions();
    }

    /** Give a role exactly these optical permissions; its other (clinic) permissions are untouched. */
    private static function syncOptical(\Spatie\Permission\Models\Role $role, array $permissions): void
    {
        foreach (array_diff(self::PERMISSIONS, $permissions) as $permission) {
            if ($role->hasPermissionTo($permission)) $role->revokePermissionTo($permission);
        }
        $role->givePermissionTo($permissions);
    }

    /** Front desk work: orders, payments, handing glasses over. */
    public static function desk(?User $user): bool
    {
        return self::isManager($user) || (bool) $user?->can(self::SALES);
    }

    /**
     * Money (prices, totals, balances, payments) and the customer's phone are for the front desk
     * and managers. Bench staff (Optician, Lab Technician) see the job, not the till or the customer.
     */
    public static function seesMoneyAndContacts(?User $user): bool
    {
        return self::desk($user);
    }

    /** Bench work: sending jobs to the lab, glazing, marking ready, receiving lenses. */
    public static function bench(?User $user): bool
    {
        return self::isManager($user) || (bool) $user?->can(self::LAB);
    }

    /** May this person move a job to this status? */
    public static function canMoveTo(?User $user, string $status): bool
    {
        return in_array($status, self::LAB_STEPS, true) ? self::bench($user) : self::desk($user);
    }

    public static function isManager(?User $user): bool
    {
        return \App\Support\Access\Access::allows($user, 'optical.manage');
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
