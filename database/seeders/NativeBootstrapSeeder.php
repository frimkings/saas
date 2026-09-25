<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Diagnosis;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class NativeBootstrapSeeder extends Seeder
{
    /**
     * Seed only missing first-run data. Existing offline installations are
     * never overwritten, and changed user passwords remain untouched.
     */
    public function run(): void
    {
        if (Schema::hasTable('users') && User::query()->doesntExist()) {
            $this->call(UserSeeder::class);
        }

        if (Schema::hasTable('categories') && Category::query()->doesntExist()) {
            $this->call(CategorySeeder::class);
        }

        if (Schema::hasTable('diagnoses') && Diagnosis::query()->doesntExist()) {
            $this->call(DiagnosisSeeder::class);
        }

        $this->call(TenancyFoundationSeeder::class);
    }
}
