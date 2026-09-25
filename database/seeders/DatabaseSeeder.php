<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            UserSeeder::class,
            RolesAndPermissionsSeeder::class,
            CategorySeeder::class,
            DiagnosisSeeder::class,
            TenancyFoundationSeeder::class,
        ]);
    }
}
