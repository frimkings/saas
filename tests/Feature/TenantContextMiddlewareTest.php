<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashierPatientClearance;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\TenancyFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class TenantContextMiddlewareTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth'])->get('/_test/tenant-context', function (TenantContext $context) {
            return response()->json([
                'clinic_id' => $context->clinicId(),
                'branch_id' => $context->branchId(),
                'authorized_branch_ids' => $context->authorizedBranchIds(),
            ]);
        });

        Route::middleware(['web', 'auth'])->get(
            '/_test/tenant-clearance/{clearance}',
            fn (CashierPatientClearance $clearance) => response()->json(['uuid' => $clearance->uuid])
        );
    }

    public function test_context_is_inert_when_feature_is_disabled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/_test/tenant-context')
            ->assertOk()
            ->assertJson([
                'clinic_id' => null,
                'branch_id' => null,
                'authorized_branch_ids' => [],
            ]);
    }

    public function test_context_resolves_an_authorized_default_clinic_and_branch(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $this->seed(TenancyFoundationSeeder::class);

        $clinic = Clinic::query()->firstOrFail();
        $branch = $clinic->defaultBranch()->firstOrFail();

        $this->actingAs($user)
            ->get('/_test/tenant-context')
            ->assertOk()
            ->assertJson([
                'clinic_id' => $clinic->id,
                'branch_id' => $branch->id,
                'authorized_branch_ids' => [$branch->id],
            ])
            ->assertSessionHas(config('tenancy.session_keys.clinic'), $clinic->id)
            ->assertSessionHas(config('tenancy.session_keys.branch'), $branch->id);
    }

    public function test_context_rejects_a_user_without_active_clinic_membership(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/_test/tenant-context')
            ->assertForbidden();
    }

    public function test_forged_session_ids_fall_back_to_authorized_memberships(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $this->seed(TenancyFoundationSeeder::class);

        $authorizedClinic = Clinic::query()->firstOrFail();
        $authorizedBranch = $authorizedClinic->defaultBranch()->firstOrFail();

        $foreignClinic = $this->activeClinic(['name' => 'Foreign Clinic', 'slug' => 'foreign-clinic']);
        $foreignBranch = Branch::create([
            'clinic_id' => $foreignClinic->id,
            'code' => 'MAIN',
            'name' => 'Foreign Main',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->withSession([
                config('tenancy.session_keys.clinic') => $foreignClinic->id,
                config('tenancy.session_keys.branch') => $foreignBranch->id,
            ])
            ->get('/_test/tenant-context')
            ->assertOk()
            ->assertJson([
                'clinic_id' => $authorizedClinic->id,
                'branch_id' => $authorizedBranch->id,
            ])
            ->assertSessionHas(config('tenancy.session_keys.clinic'), $authorizedClinic->id)
            ->assertSessionHas(config('tenancy.session_keys.branch'), $authorizedBranch->id);
    }

    public function test_context_exposes_only_active_authorized_branches_in_the_selected_clinic(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $this->seed(TenancyFoundationSeeder::class);

        $clinic = Clinic::query()->firstOrFail();
        $second = $clinic->branches()->create([
            'code' => 'EAST',
            'name' => 'East Branch',
            'is_active' => true,
        ]);
        $inactive = $clinic->branches()->create([
            'code' => 'OLD',
            'name' => 'Old Branch',
            'is_active' => false,
        ]);
        $second->users()->attach($user, ['status' => 'active']);
        $inactive->users()->attach($user, ['status' => 'active']);

        $default = $clinic->defaultBranch()->firstOrFail();

        $this->actingAs($user)
            ->withSession([config('tenancy.session_keys.branch') => $second->id])
            ->get('/_test/tenant-context')
            ->assertOk()
            ->assertJson([
                'branch_id' => $second->id,
                'authorized_branch_ids' => [$default->id, $second->id],
            ]);
    }

    public function test_tenant_context_is_resolved_before_scoped_route_model_binding(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $clinic = $this->activeClinic(['name' => 'Binding Clinic', 'slug' => 'binding-clinic']);
        $branch = $clinic->branches()->create([
            'code' => 'MAIN',
            'name' => 'Main',
            'is_default' => true,
        ]);
        $clinic->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);

        $patient = Patient::factory()->create(['user_id' => $user->id]);
        $clearance = CashierPatientClearance::create([
            'user_id' => $user->id,
            'patient_id' => $patient->id,
            'payment_status' => 'Paid',
            'doctor_status' => false,
            'clearance_date' => now()->toDateString(),
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($user)
            ->withSession([
                config('tenancy.session_keys.clinic') => $clinic->id,
                config('tenancy.session_keys.branch') => $branch->id,
            ])
            ->get('/_test/tenant-clearance/'.$clearance->uuid)
            ->assertOk()
            ->assertJson(['uuid' => $clearance->uuid]);
    }
}
