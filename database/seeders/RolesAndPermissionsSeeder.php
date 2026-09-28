<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 2. Create Permissions (The "Actions")
        $permissions = [
            'manage users',
            'view consultations',
            'perform refraction',
            'manage billing',
            'approve clearance revoke',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // 3. Create Roles and Assign Permissions
        // Super Admin gets everything
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin']);
        $superAdminRole->givePermissionTo(Permission::all());

        // Standard Roles
        Role::firstOrCreate(['name' => 'Doctor']);
        Role::firstOrCreate(['name' => 'Cashier']);
        Role::firstOrCreate(['name' => 'Staff']);
        Role::firstOrCreate(['name' => 'Manager']);
        Role::firstOrCreate(['name' => 'Secretary']);
        Role::firstOrCreate(['name' => 'Optician']);

        // Optical screens by role, the optical roles, and managers adding staff.
        \App\Support\OpticalAccess::installRoles();


        // 4. Create a Fresh Admin User to log in with
        $admin = User::firstOrCreate(
            ['email' => 'frimkings@gmail.com'],
            [
                'name' => 'Clinic Admin',
                'password' => Hash::make('password'), // Use a secure password
            ]
        );

        $admin->assignRole('Super Admin');

        // Assign roles to remaining default accounts
        $manager   = User::where('email', 'admin@eyeclinic.com')->first();
        $secretary = User::where('email', 'secretary@gmail.com')->first();
        $staff     = User::where('email', 'staff@eyeclinic.com')->first();

        if ($manager)   $manager->assignRole('Super Admin');
        if ($secretary) $secretary->assignRole('Secretary');
        if ($staff)     $staff->assignRole('Staff');
    }
}
