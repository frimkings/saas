<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\User;
use Database\Seeders\TenancyFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_creates_a_default_clinic_and_branch(): void
    {
        $clinic = Clinic::query()->firstOrFail();
        $branch = Branch::query()->firstOrFail();

        $this->assertTrue($branch->is_default);
        $this->assertTrue($branch->is_active);
        $this->assertTrue($branch->clinic->is($clinic));
        $this->assertTrue($clinic->defaultBranch->is($branch));
    }

    public function test_bootstrap_assigns_existing_users_without_duplicates(): void
    {
        $user = User::factory()->create();

        $this->seed(TenancyFoundationSeeder::class);
        $this->seed(TenancyFoundationSeeder::class);

        $clinic = Clinic::query()->firstOrFail();
        $branch = $clinic->defaultBranch()->firstOrFail();

        $this->assertCount(1, $user->fresh()->clinics);
        $this->assertCount(1, $user->fresh()->branches);
        $this->assertSame('active', $user->clinics()->firstOrFail()->pivot->status);
        $this->assertTrue((bool) $user->branches()->firstOrFail()->pivot->is_default);
        $this->assertDatabaseCount('clinic_user', 1);
        $this->assertDatabaseCount('branch_user', 1);
        $this->assertTrue($branch->users()->firstOrFail()->is($user));
    }

    public function test_a_user_can_belong_to_multiple_clinics_and_branches(): void
    {
        $user = User::factory()->create();
        $secondClinic = Clinic::create([
            'name' => 'Second Clinic',
            'slug' => 'second-clinic',
        ]);
        $secondBranch = $secondClinic->branches()->create([
            'code' => 'MAIN',
            'name' => 'Main Branch',
            'is_default' => true,
        ]);

        $secondClinic->users()->attach($user, ['status' => 'active']);
        $secondBranch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        $this->seed(TenancyFoundationSeeder::class);

        $this->assertCount(2, $user->fresh()->clinics);
        $this->assertCount(2, $user->fresh()->branches);
    }
}
