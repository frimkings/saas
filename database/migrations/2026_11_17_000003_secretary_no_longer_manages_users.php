<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff & roles is for managers (decided 2026-11-17). The Secretary role had "manage users",
 * which let a front-desk user give themselves the Optician or Manager role and get round the
 * front desk / lab split. Managers and Super Admins keep it.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) return;
        foreach (Role::where('guard_name', 'web')->whereIn('name', ['Secretary', 'Cashier'])->get() as $role) {
            if ($role->hasPermissionTo('manage users')) $role->revokePermissionTo('manage users');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) return;
        Role::where('guard_name', 'web')->where('name', 'Secretary')->first()?->givePermissionTo('manage users');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
