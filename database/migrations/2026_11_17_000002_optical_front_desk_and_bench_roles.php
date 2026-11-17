<?php

use App\Support\OpticalAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Optical Suite by job (decided 2026-11-17): the front desk is the Secretary's (and the Cashier's),
 * the lab bench is the Optician's. Secretary and Cashier keep only "optical sales"; the Optician
 * keeps only "optical lab" and starts on the Lab Workbench. Managers and Super Admins are unchanged,
 * as are Optical Assistant (front desk) and Lab Technician (lab and stock).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) return;
        OpticalAccess::installRoles();
        if (Schema::hasColumn('roles', 'dashboard_route')) {
            Role::where('name', 'Optician')->where('guard_name', 'web')->where('dashboard_route', 'optical.dashboard')
                ->update(['dashboard_route' => 'optical.lab-workbench']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Back to before: Secretary, Cashier and Optician open every optical screen again. */
    public function down(): void
    {
        if (! Schema::hasTable('roles')) return;
        foreach (Role::where('guard_name', 'web')->whereIn('name', ['Secretary', 'Cashier', 'Optician'])->get() as $role) {
            $role->givePermissionTo(OpticalAccess::PERMISSIONS);
        }
        if (Schema::hasColumn('roles', 'dashboard_route')) {
            Role::where('name', 'Optician')->where('guard_name', 'web')->where('dashboard_route', 'optical.lab-workbench')
                ->update(['dashboard_route' => 'optical.dashboard']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
