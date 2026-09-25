<?php

namespace Tests\Feature;

use App\Models\Appointments;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class ClinicalWorkflowTenancyTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private const TABLES = [
        'cashier_patient_clearances', 'consultations', 'refractions', 'referrals',
        'appointments', 'online_bookings', 'lens_orders', 'patient_documents',
        'consultation_notes',
    ];

    public function test_clinical_tables_are_tenant_ready_and_backfilled(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'clinic_id'));
            $this->assertTrue(Schema::hasColumn($table, 'branch_id'));
            $this->assertSame(0, DB::table($table)->whereNull('clinic_id')->count());
            $this->assertSame(0, DB::table($table)->whereNull('branch_id')->count());
        }
    }

    public function test_appointments_are_isolated_by_active_branch(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        [$clinic, $branchA] = $this->membership($user);
        $branchB = $clinic->branches()->create(['code' => 'EAST', 'name' => 'East']);
        $branchB->users()->attach($user, ['status' => 'active']);
        $context = app(TenantContext::class);

        $context->set($user, $clinic, $branchA, [$branchA->id, $branchB->id]);
        $patient = Patient::factory()->create();
        $appointmentA = Appointments::factory()->create(['patient_id' => $patient->id, 'user_id' => $user->id]);

        $context->set($user, $clinic, $branchB, [$branchA->id, $branchB->id]);
        $appointmentB = Appointments::factory()->create(['patient_id' => $patient->id, 'user_id' => $user->id]);

        $this->assertSame([$appointmentB->id], Appointments::query()->pluck('id')->all());
        $context->set($user, $clinic, $branchA, [$branchA->id, $branchB->id]);
        $this->assertSame([$appointmentA->id], Appointments::query()->pluck('id')->all());
    }

    private function membership(User $user): array
    {
        $clinic = $this->activeClinic(['name' => 'Clinic', 'slug' => 'clinic']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $clinic->users()->attach($user, ['status' => 'active']);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        return [$clinic, $branch];
    }
}
