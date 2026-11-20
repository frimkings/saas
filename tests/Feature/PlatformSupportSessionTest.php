<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserRoleManagerComponent;
use App\Models\{Branch, Clinic, ClinicSubscription, SubscriptionPlan, User};
use App\Support\SupportSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Platform support login (19 Nov 2026): a platform admin inspects one clinic's own interface without
 * joining it, so they never appear on that clinic's user list. Entering and leaving are audited.
 */
class PlatformSupportSessionTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        // These two guard the session login across requests; they are unrelated to support sessions
        // and interfere with seeding a session by hand in a test.
        $this->withoutMiddleware([
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \App\Http\Middleware\LogoutInactiveUsers::class,
        ]);

        $this->clinic = Clinic::create(['name' => 'Hill Eye Clinic', 'slug' => 'hill-eye', 'status' => 'active', 'deployment_mode' => 'hosted']);
        $this->branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Clinic plan', 'code' => 'clinic-plan', 'included_branches' => 1, 'included_users' => 5,
            'storage_limit_mb' => 100, 'features' => ['clinical'], 'base_price' => 0, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth(), 'feature_snapshot' => ['clinical']]);

        // A real staff member of the clinic, so the user list is not empty.
        $staff = User::factory()->create(['name' => 'Ama Owner', 'email' => 'ama@hill.test']);
        $this->clinic->users()->attach($staff->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Super Admin']);
        $this->branch->users()->attach($staff->id, ['status' => 'active', 'is_default' => true]);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        DB::table('branch_user_role')->insert(['branch_id' => $this->branch->id, 'user_id' => $staff->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);

        // The platform admin: no clinic membership anywhere.
        $this->admin = User::factory()->create(['name' => 'Dev Support', 'email' => 'frimkings@gmail.com']);
        $this->admin->forceFill(['is_platform_admin' => true, 'is_active' => true, 'must_change_password' => false])->save();
    }

    /** The session state a request carries while a support session is open. */
    private function supportSession(): array
    {
        return [
            SupportSession::KEY => $this->clinic->id,
            'workspace_mode' => 'clinic',
            config('tenancy.session_keys.clinic') => $this->clinic->id,
            config('tenancy.session_keys.branch') => $this->branch->id,
        ];
    }

    public function test_only_a_platform_admin_can_enter_support(): void
    {
        $outsider = User::factory()->create();
        $outsider->forceFill(['is_active' => true, 'must_change_password' => false])->save();
        $this->actingAs($outsider)->post(route('platform.support-session.enter', $this->clinic))->assertForbidden();
        $this->assertNull(session(SupportSession::KEY));
    }

    public function test_entering_support_records_and_audits_the_clinic(): void
    {
        $this->actingAs($this->admin)->post(route('platform.support-session.enter', $this->clinic))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($this->clinic->id, session(SupportSession::KEY));
        $this->assertSame('clinic', session('workspace_mode'));
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'SUPPORT_SESSION_STARTED', 'clinic_id' => $this->clinic->id, 'user_id' => $this->admin->id,
        ]);
    }

    public function test_a_clinic_page_opens_as_super_admin_but_the_admin_never_joins_the_clinic(): void
    {
        // With a support session open, a clinic page resolves that clinic as the tenant and opens.
        $this->actingAs($this->admin)->withSession($this->supportSession())
            ->get(route('admin.dashboard'))->assertOk();
        $this->assertSame($this->clinic->id, app(TenantContext::class)->clinicId());

        // The admin never became a member, so the clinic cannot see them.
        $this->assertFalse($this->clinic->users()->whereKey($this->admin->id)->exists());
        $this->assertSame(0, DB::table('clinic_user')->where('user_id', $this->admin->id)->count());
        $this->assertSame(0, DB::table('branch_user')->where('user_id', $this->admin->id)->count());

        // The clinic's own user list shows its staff but not the support admin.
        app(TenantContext::class)->set($this->admin, $this->clinic, $this->branch, [$this->branch->id]);
        $this->admin->setRelation('roles', collect([Role::where('name', 'Super Admin')->first()]));
        Livewire::actingAs($this->admin)->test(UserRoleManagerComponent::class)
            ->assertSee('ama@hill.test')->assertDontSee('frimkings@gmail.com');
    }

    public function test_exiting_support_returns_to_the_platform_and_is_audited(): void
    {
        $this->actingAs($this->admin)->withSession($this->supportSession())
            ->post(route('support-session.exit'))->assertRedirect(route('platform.dashboard'));

        $this->assertNull(session(SupportSession::KEY));
        $this->assertSame('platform', session('workspace_mode'));
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'SUPPORT_SESSION_ENDED', 'clinic_id' => $this->clinic->id]);
    }

    public function test_support_sessions_leave_no_trace_in_the_clinic_audit_trail(): void
    {
        $this->actingAs($this->admin);
        app(TenantContext::class)->set($this->admin, $this->clinic, $this->branch, [$this->branch->id]);

        // While inspecting, a clinic audit write is suppressed, so the hidden admin never shows there.
        session([SupportSession::KEY => $this->clinic->id]);
        $this->assertNull(\App\Models\AuditTrail::record('support.looked', 'Looked around', null, [], [], null, true));
        $this->assertSame(0, \App\Models\AuditTrail::count());

        // Outside a support session the same call records as normal.
        session()->forget(SupportSession::KEY);
        $this->assertNotNull(\App\Models\AuditTrail::record('normal.event', 'A staff action', null, [], [], null, true));
        $this->assertSame(1, \App\Models\AuditTrail::count());
    }

    public function test_a_suspended_clinic_cannot_be_entered(): void
    {
        $this->clinic->update(['status' => 'suspended']);
        $this->actingAs($this->admin)->post(route('platform.support-session.enter', $this->clinic))->assertStatus(422);
        $this->assertNull(session(SupportSession::KEY));
    }
}
