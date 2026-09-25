<?php

namespace Tests\Feature;

use App\Livewire\Secretary\AppointmentsComponent;
use App\Models\Appointments;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;
use Spatie\Permission\Models\Role;

class AppointmentWorkflowTenancyTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'Doctor', 'guard_name' => 'web']);
    }

    public function test_cross_clinic_patient_cannot_be_used_for_walk_in_booking(): void
    {
        [$user, $clinic, $branch] = $this->tenant('appointments-a');
        [, $otherClinic, $otherBranch] = $this->tenant('appointments-b');
        $context = app(TenantContext::class);
        $context->set($user, $otherClinic, $otherBranch, [$otherBranch->id]);
        $foreignPatient = Patient::factory()->create([
            'user_id' => $user->id, 'name' => 'Foreign Patient', 'pxnumber' => 'PX-FOREIGN',
        ]);
        $context->set($user, $clinic, $branch, [$branch->id]);
        $this->actingAs($user);

        Livewire::test(AppointmentsComponent::class)
            ->set('patient_id', $foreignPatient->id)
            ->set('title', 'Walk-in Visit')
            ->set('reminder_channel', 'none')
            ->call('quickBookWalkIn')
            ->assertHasErrors(['patient_id']);

        $this->assertDatabaseMissing('appointments', ['patient_id' => $foreignPatient->id]);
    }

    public function test_invalid_appointment_status_is_ignored(): void
    {
        [$user, $clinic, $branch] = $this->tenant('appointments-status');
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);
        $this->actingAs($user);
        $patient = Patient::factory()->create(['user_id' => $user->id, 'name' => 'Patient', 'pxnumber' => 'PX-STATUS']);
        $appointment = Appointments::create([
            'patient_id' => $patient->id, 'user_id' => $user->id, 'title' => 'Eye Exam',
            'scheduled_at' => now()->addDay(), 'status' => 'Pending',
        ]);

        Livewire::test(AppointmentsComponent::class)
            ->call('updateStatus', $appointment->id, 'Hacked')
            ->assertDispatched('notify');

        $this->assertSame('Pending', $appointment->fresh()->status);
    }

    private function tenant(string $slug): array
    {
        $user = User::factory()->create();
        $clinic = $this->activeClinic(['name' => $slug, 'slug' => $slug]);
        $branch = $clinic->branches()->create([
            'code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true,
        ]);
        $clinic->users()->attach($user, ['status' => 'active']);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        return [$user, $clinic, $branch];
    }
}
