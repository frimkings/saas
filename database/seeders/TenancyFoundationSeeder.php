<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenancyFoundationSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('clinics') || ! Schema::hasTable('branches')) {
            return;
        }

        $clinic = Clinic::query()->first();
        if (! $clinic) {
            $name = Schema::hasTable('settings')
                ? (Setting::query()->value('clinic_name') ?: 'My Eye Clinic')
                : 'My Eye Clinic';

            $clinic = Clinic::create([
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
                'status' => 'active',
                'deployment_mode' => app()->environment('production') ? 'hosted' : 'local',
                'default_timezone' => (string) config('app.timezone', 'UTC'),
                'default_currency' => 'GH₵',
            ]);
        }

        $branch = $clinic->branches()->where('is_default', true)->first()
            ?? $clinic->branches()->first()
            ?? $clinic->branches()->create([
                'code' => 'MAIN',
                'name' => 'Main Branch',
                'timezone' => $clinic->default_timezone,
                'receipt_prefix' => 'MAIN',
                'is_default' => true,
                'is_active' => true,
            ]);

        User::query()->orderBy('id')->chunkById(500, function ($users) use ($clinic, $branch): void {
            foreach ($users as $user) {
                $clinic->users()->syncWithoutDetaching([
                    $user->id => [
                        'status' => 'active',
                        'is_default' => ! $user->clinics()->wherePivot('is_default', true)->exists(),
                        'staff_identifier' => $user->staff_id,
                        'joined_at' => now(),
                    ],
                ]);
                $branch->users()->syncWithoutDetaching([
                    $user->id => [
                        'status' => 'active',
                        'is_default' => true,
                        'joined_at' => now(),
                    ],
                ]);
                if (Schema::hasTable('branch_user_role')) {
                    foreach ($user->roles()->pluck('roles.id') as $roleId) {
                        DB::table('branch_user_role')->insertOrIgnore([
                            'branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $roleId,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }
        });
    }
}
