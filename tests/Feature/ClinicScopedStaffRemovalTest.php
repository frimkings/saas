<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserRoleManagerComponent;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class ClinicScopedStaffRemovalTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private User $admin;
    private User $worker;
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

        $this->admin = User::factory()->create();
        $this->admin->assignRole($adminRole);
        $this->clinicA->users()->attach($this->admin, ['status' => 'active', 'clinic_role' => 'Super Admin', 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $this->branchA, 'user_id' => $this->admin->id, 'status' => 'active', 'is_default' => true]);

        // The worker has one account and works at both clinics.
        $this->worker = User::factory()->create(['email' => 'worker@example.test']);
        foreach ([[$this->clinicA, $this->branchA, true], [$this->clinicB, $this->branchB, false]] as [$clinic, $branch, $default]) {
            $clinic->users()->attach($this->worker, ['status' => 'active', 'clinic_role' => 'Cashier', 'is_default' => $default]);
            DB::table('branch_user')->insert(['branch_id' => $branch, 'user_id' => $this->worker->id, 'status' => 'active', 'is_default' => true]);
            DB::table('branch_user_role')->insert(['branch_id' => $branch, 'user_id' => $this->worker->id, 'role_id' => $cashier->id]);
        }
    }

    private function membership(Clinic $clinic): object
    {
        return DB::table('clinic_user')->where('clinic_id', $clinic->id)->where('user_id', $this->worker->id)->first();
    }

    public function test_deactivating_in_one_clinic_leaves_the_account_and_other_clinics_active(): void
    {
        Livewire::actingAs($this->admin)->test(UserRoleManagerComponent::class)->call('toggleStatus', $this->worker->id);

        $this->assertSame('inactive', $this->membership($this->clinicA)->status);
        $this->assertNotNull($this->membership($this->clinicA)->left_at);
        $this->assertDatabaseHas('branch_user', ['branch_id' => $this->branchA, 'user_id' => $this->worker->id, 'status' => 'inactive']);
        $this->assertSame('active', $this->membership($this->clinicB)->status);
        $this->assertTrue((bool) $this->worker->fresh()->is_active);

        Livewire::actingAs($this->admin)->test(UserRoleManagerComponent::class)->call('toggleStatus', $this->worker->id);
        $this->assertSame('active', $this->membership($this->clinicA)->status);
        $this->assertNull($this->membership($this->clinicA)->left_at);
    }

    public function test_removing_from_one_clinic_keeps_the_account_and_other_clinic(): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserRoleManagerComponent::class)
            ->assertSee('worker@example.test')
            ->call('delete', $this->worker->id)
            ->assertDontSee('worker@example.test');

        $this->assertDatabaseHas('users', ['id' => $this->worker->id, 'is_active' => true]);
        $this->assertSame('left', $this->membership($this->clinicA)->status);
        $this->assertDatabaseMissing('branch_user_role', ['branch_id' => $this->branchA, 'user_id' => $this->worker->id]);
        $this->assertSame('active', $this->membership($this->clinicB)->status);
        $this->assertDatabaseHas('branch_user_role', ['branch_id' => $this->branchB, 'user_id' => $this->worker->id]);
        // Clinic B becomes the default now that Clinic A is gone.
        $this->assertSame(1, (int) $this->membership($this->clinicB)->is_default);

        // Clinic A can no longer edit or re-password a former worker.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $component->call('edit', $this->worker->id);
    }

    public function test_worker_without_any_active_clinic_is_turned_away_at_login(): void
    {
        DB::table('clinic_user')->where('user_id', $this->worker->id)->update(['status' => 'left']);

        $this->from(route('login'))->post(route('login'), ['login' => 'worker@example.test', 'password' => 'password'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');
        $this->assertGuest();
    }
}
