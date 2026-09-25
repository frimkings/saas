<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Clinic;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class CatalogueTenancyTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private const TABLES = [
        'diagnoses',
        'drugs',
        'lens_options',
        'categories',
        'sms_templates',
        'referral_snippets',
        'insurers',
        'suppliers',
    ];

    public function test_catalogue_tables_have_clinic_ownership_and_no_unassigned_legacy_rows(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'clinic_id'), $table.' is missing clinic_id.');
            $this->assertSame(0, DB::table($table)->whereNull('clinic_id')->count(), $table.' has unassigned rows.');
        }
    }

    public function test_same_catalogue_name_can_exist_in_two_clinics_but_queries_are_isolated(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        [$clinicA, $branchA] = $this->createMembership($user, 'Clinic A', 'clinic-a');
        [$clinicB, $branchB] = $this->createMembership($user, 'Clinic B', 'clinic-b');

        $context = app(TenantContext::class);
        $context->set($user, $clinicA, $branchA, [$branchA->id]);
        $categoryA = Category::create(['name' => 'Frames', 'user_id' => $user->id]);

        $context->set($user, $clinicB, $branchB, [$branchB->id]);
        $categoryB = Category::create(['name' => 'Frames', 'user_id' => $user->id]);

        $this->assertNotSame($categoryA->clinic_id, $categoryB->clinic_id);
        $this->assertSame([$categoryB->id], Category::query()->pluck('id')->all());
        $this->assertSame(2, Category::withoutGlobalScope('clinic')->where('name', 'Frames')->count());

        $context->set($user, $clinicA, $branchA, [$branchA->id]);
        $this->assertSame([$categoryA->id], Category::query()->pluck('id')->all());
    }

    public function test_client_supplied_clinic_id_is_replaced_by_resolved_context(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        [$clinicA, $branchA] = $this->createMembership($user, 'Clinic A', 'clinic-a');
        [$clinicB] = $this->createMembership($user, 'Clinic B', 'clinic-b');
        app(TenantContext::class)->set($user, $clinicA, $branchA, [$branchA->id]);

        $category = new Category(['name' => 'Protected', 'user_id' => $user->id]);
        $category->clinic_id = $clinicB->id;
        $category->save();

        $this->assertSame($clinicA->id, $category->clinic_id);
    }

    public function test_enabled_tenancy_without_context_returns_no_catalogue_rows(): void
    {
        config()->set('tenancy.enabled', true);
        app(TenantContext::class)->clear();

        $this->assertSame(0, Category::query()->count());
    }

    private function createMembership(User $user, string $name, string $slug): array
    {
        $clinic = $this->activeClinic(['name' => $name, 'slug' => $slug]);
        $branch = $clinic->branches()->create([
            'code' => 'MAIN',
            'name' => 'Main Branch',
            'is_default' => true,
        ]);
        $clinic->users()->attach($user, ['status' => 'active']);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        return [$clinic, $branch];
    }
}
