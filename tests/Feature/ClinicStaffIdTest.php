<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserRoleManagerComponent;
use App\Livewire\UserProfileComponent;
use App\Models\Clinic;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** Staff IDs are numbered by each clinic: unique within a clinic, reusable across clinics. */
class ClinicStaffIdTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private User $adminA;
    private User $worker;
    private User $colleague;
    private Clinic $clinicA;
    private Clinic $clinicB;
    private int $branchA;
    private int $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('tenancy.enabled', true);
        $adminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $cashier = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);

        $this->clinicA = $this->activeClinic(['name' => 'Clinic A', 'slug' => 'clinic-a']);
        $this->clinicB = $this->activeClinic(['name' => 'Clinic B', 'slug' => 'clinic-b']);
        $this->branchA = $this->clinicA->branches()->create(['code' => 'MAIN', 'name' => 'A Main', 'is_default' => true])->id;
        $this->branchB = $this->clinicB->branches()->create(['code' => 'MAIN', 'name' => 'B Main', 'is_default' => true])->id;

        $this->adminA = User::factory()->create();
        $this->adminA->assignRole($adminRole);
        $this->clinicA->users()->attach($this->adminA, ['status' => 'active', 'clinic_role' => 'Super Admin', 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $this->branchA, 'user_id' => $this->adminA->id, 'status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $this->branchA, 'user_id' => $this->adminA->id, 'role_id' => $adminRole->id]);

        // The worker works at both clinics; the colleague only at Clinic A.
        $this->worker = User::factory()->create(['name' => 'Two Clinic Worker', 'email' => 'worker@example.test']);
        $this->colleague = User::factory()->create(['name' => 'Colleague', 'email' => 'colleague@example.test']);
        foreach ([[$this->worker, $this->clinicA, $this->branchA, true], [$this->worker, $this->clinicB, $this->branchB, false], [$this->colleague, $this->clinicA, $this->branchA, true]] as [$user, $clinic, $branch, $default]) {
            $clinic->users()->attach($user, ['status' => 'active', 'clinic_role' => 'Cashier', 'is_default' => $default]);
            DB::table('branch_user')->insert(['branch_id' => $branch, 'user_id' => $user->id, 'status' => 'active', 'is_default' => true]);
            DB::table('branch_user_role')->insert(['branch_id' => $branch, 'user_id' => $user->id, 'role_id' => $cashier->id]);
        }
        // Clinic B already calls the worker ST001.
        DB::table('clinic_user')->where('clinic_id', $this->clinicB->id)->where('user_id', $this->worker->id)->update(['staff_identifier' => 'ST001']);
    }

    private function staffIdAt(Clinic $clinic, User $user): ?string
    {
        return DB::table('clinic_user')->where('clinic_id', $clinic->id)->where('user_id', $user->id)->value('staff_identifier');
    }

    private function manager()
    {
        return Livewire::actingAs($this->adminA)->test(UserRoleManagerComponent::class);
    }

    public function test_another_clinic_can_use_the_same_staff_id(): void
    {
        $this->manager()->call('edit', $this->colleague->id)->assertSet('staff_id', '')
            ->set('staff_id', 'ST001')->call('store')->assertHasNoErrors();

        $this->assertSame('ST001', $this->staffIdAt($this->clinicA, $this->colleague));
        $this->assertSame('ST001', $this->staffIdAt($this->clinicB, $this->worker));
        $this->assertNull($this->colleague->fresh()->staff_id, 'The platform-wide field is no longer written.');
    }

    public function test_the_same_clinic_cannot_reuse_a_staff_id(): void
    {
        $this->manager()->call('edit', $this->colleague->id)->set('staff_id', 'A-100')->call('store')->assertHasNoErrors();

        $this->manager()->call('edit', $this->worker->id)->set('staff_id', 'A-100')->call('store')->assertHasErrors(['staff_id' => 'unique']);
        $this->assertNull($this->staffIdAt($this->clinicA, $this->worker));
    }

    public function test_a_person_in_two_clinics_has_a_separate_id_in_each(): void
    {
        $this->manager()->call('edit', $this->worker->id)->assertSet('staff_id', '')
            ->set('staff_id', 'A-7')->call('store')->assertHasNoErrors();

        $this->assertSame('A-7', $this->staffIdAt($this->clinicA, $this->worker));
        $this->assertSame('ST001', $this->staffIdAt($this->clinicB, $this->worker));

        // The list, search and profile show the ID for the clinic in use.
        $this->manager()->assertSee('A-7')->assertDontSee('ST001')
            ->set('search', 'A-7')->assertSee('worker@example.test')->assertDontSee('colleague@example.test');

        app(TenantContext::class)->set($this->worker, $this->clinicB, $this->clinicB->branches()->first(), [$this->branchB]);
        Livewire::actingAs($this->worker)->test(UserProfileComponent::class)->assertSet('staff_id', 'ST001');
    }
}
