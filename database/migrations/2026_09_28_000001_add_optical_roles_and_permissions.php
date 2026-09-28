<?php

use App\Support\OpticalAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Optical screens by role (App\Support\OpticalAccess), and managers may add staff.
 *
 * Everyone keeps the optical screens they could open before, so no one loses (or gains)
 * access on the day this ships: Secretary, Cashier and Optician opened every screen; Manager
 * and Super Admin open everything anyway. The narrower optical roles are new. Clinics can
 * then change any role under Roles & Permissions.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) return;
        OpticalAccess::installRoles();
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) return;
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::whereIn('name', ['Optical Assistant', 'Lab Technician'])->where('guard_name', 'web')->delete();
        Role::where('name', 'Manager')->first()?->revokePermissionTo('manage users');
        Permission::whereIn('name', OpticalAccess::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
