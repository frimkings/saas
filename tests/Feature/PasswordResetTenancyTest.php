<?php

namespace Tests\Feature;

use App\Livewire\Admin\PasswordResetApprovalsComponent;
use App\Models\{Branch, Clinic, PasswordResetRequest, User};
use App\Support\ApprovalCounts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** A clinic's admins see and approve password resets only for their own active, non-platform staff. */
class PasswordResetTenancyTest extends TestCase
{
    use RefreshDatabase, GivesClinicsAccess;

    private Clinic $clinicA;
    private Clinic $clinicB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->clinicA = $this->activeClinic(['name' => 'Clinic A', 'slug' => 'clinic-a', 'deployment_mode' => 'hosted']);
        $this->clinicB = $this->activeClinic(['name' => 'Clinic B', 'slug' => 'clinic-b', 'deployment_mode' => 'hosted']);
        foreach ([$this->clinicA, $this->clinicB] as $clinic) {
            $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        }
    }

    private function member(Clinic $clinic, ?string $role = null, array $attributes = [], string $status = 'active'): User
    {
        $user = User::factory()->create($attributes);
        $branch = $clinic->branches()->first();
        $clinic->users()->attach($user->id, ['status' => $status, 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        if ($role) {
            $role = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $user->assignRole($role);
            DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $role->id]);
        }

        return $user;
    }

    private function signInAs(User $user, Clinic $clinic): void
    {
        $branch = $clinic->branches()->first();
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($user, $branch);
        ApprovalCounts::forget();
        $this->actingAs($user);
    }

    private function resetRequestFor(User $user): PasswordResetRequest
    {
        app(TenantContext::class)->clear(); // filed from the public forgot-password page

        return PasswordResetRequest::create(['email' => $user->email, 'status' => 'pending']);
    }

    public function test_another_clinics_admin_cannot_see_count_or_approve_the_request(): void
    {
        $request = $this->resetRequestFor($this->member($this->clinicB, 'Doctor'));
        $this->signInAs($this->member($this->clinicA, 'Super Admin'), $this->clinicA);

        $this->assertSame(0, PasswordResetRequest::pendingCount());
        $this->assertSame(0, ApprovalCounts::pending()['password']);
        Livewire::test(PasswordResetApprovalsComponent::class)->assertDontSee($request->email);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(PasswordResetApprovalsComponent::class)
            ->call('openConfirm', $request->id, 'approve')->call('execute');
    }

    public function test_the_staff_members_own_clinic_admin_sees_and_approves_it(): void
    {
        $request = $this->resetRequestFor($this->member($this->clinicB, 'Doctor'));
        $this->signInAs($this->member($this->clinicB, 'Super Admin'), $this->clinicB);

        $this->assertSame(1, PasswordResetRequest::pendingCount());
        Livewire::test(PasswordResetApprovalsComponent::class)->assertSee($request->email)
            ->call('openConfirm', $request->id, 'approve')->call('execute');

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_platform_accounts_and_former_staff_are_hidden_from_clinic_admins(): void
    {
        $platform = $this->member($this->clinicB, 'Super Admin', ['is_platform_admin' => true]);
        $former = $this->member($this->clinicB, null, [], 'removed');
        $this->resetRequestFor($platform);
        $this->resetRequestFor($former);
        $this->signInAs($this->member($this->clinicB, 'Super Admin'), $this->clinicB);

        $this->assertSame(0, PasswordResetRequest::pendingCount());
    }

    public function test_the_public_reset_pages_still_find_the_request(): void
    {
        $staff = $this->member($this->clinicB, 'Doctor');
        app(TenantContext::class)->clear();

        $this->post('/forgot-password', ['email' => $staff->email])->assertSessionHas('request_status', 'submitted');
        PasswordResetRequest::where('email', $staff->email)->firstOrFail()->update(['status' => 'approved']);

        $this->post('/forgot-password', ['email' => $staff->email])->assertRedirect(route('password.offline.form'));
    }

    public function test_offline_installs_without_tenancy_see_every_request(): void
    {
        config(['tenancy.enabled' => false]);
        $this->resetRequestFor(User::factory()->create());

        $this->assertSame(1, PasswordResetRequest::pendingCount());
    }
}
