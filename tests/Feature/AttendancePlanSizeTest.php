<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Livewire\Platform\PlatformDashboardComponent;
use App\Models\AttendanceDepartment;
use App\Models\AttendanceShift;
use App\Models\AttendanceStaff;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\AttendancePlan;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * The platform sets on each plan how many staff a clinic may follow on attendance. Up to 30 is a
 * small clinic's package; more, or unlimited, brings the hospital tools.
 */
class AttendancePlanSizeTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $this->clinic = $this->activeClinic(['name' => 'Small Clinic', 'slug' => 'small-clinic']);
        $this->clinic->forceFill(['deployment_mode' => 'hosted'])->save();
        $branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $admin = User::factory()->create();
        $admin->assignRole($role);
        $this->clinic->users()->attach($admin, ['status' => 'active', 'clinic_role' => 'Super Admin', 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $branch->id, 'user_id' => $admin->id, 'status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $admin->id, 'role_id' => $role->id]);
        $this->actingAs($admin);
        app(TenantContext::class)->set($admin, $this->clinic, $branch, [$branch->id]);
    }

    private function onPlan(?int $staff): void
    {
        $plan = SubscriptionPlan::create(['name' => 'Plan '.($staff ?? 'unlimited'), 'code' => 'plan-'.($staff ?? 'unlimited'), 'features' => ['*'], 'base_price' => 0,
            'billing_interval' => 'monthly', 'attendance_staff' => $staff]);
        ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => 'monthly',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
    }

    public function test_a_small_clinic_plan_follows_so_many_staff_and_has_no_hospital_tools(): void
    {
        $this->onPlan(3);
        $this->assertSame([3, false], [AttendancePlan::staffLimit(), AttendancePlan::hospital()]);

        // The fourth person on the terminal is kept, not followed.
        foreach (['001', '002', '003', '004'] as $n) {
            AttendanceStaff::create(['employee_no' => $n, 'name' => 'Person '.$n, 'is_tracked' => true]);
        }
        $this->assertSame([true, true, true, false], AttendanceStaff::orderBy('employee_no')->pluck('is_tracked')->all());
        AttendanceShift::create(['name' => 'Morning', 'starts_at' => '08:00', 'ends_at' => '14:00']);

        $fourth = AttendanceStaff::where('employee_no', '004')->sole();
        $page = Livewire::test(AttendanceComponent::class)->assertSee('Your plan follows up to')->assertSee('1 more is on the terminal but not followed')
            ->assertDontSeeHtml('wire:click="openDepartments"')->assertDontSeeHtml('wire:click="openStaffImport"')
            ->call('openStaff', $fourth->id)->set('staffTracked', true)->call('saveStaff')->assertHasErrors(['staffTracked']);
        $this->assertFalse($fourth->fresh()->is_tracked);
        $page->call('openRoster')->assertDontSee('Fill from a pattern')->assertDontSee('Swap two people');
        foreach (['openDepartments', 'openStaffImport', 'togglePattern', 'toggleSwap'] as $action) {
            Livewire::test(AttendanceComponent::class)->call($action)->assertForbidden();
        }

        // Departments made under a bigger plan are not shown on a small one, and give heads no way in.
        AttendanceDepartment::create(['name' => 'Nursing', 'heads' => [auth()->id()]]);
        $this->assertSame([], \App\Support\AttendanceScope::headed(auth()->user()));
        Livewire::test(AttendanceComponent::class)->assertDontSee('All departments');

        // Room again once someone stops being followed.
        AttendanceStaff::where('employee_no', '001')->update(['is_tracked' => false]);
        Livewire::test(AttendanceComponent::class)->call('openStaff', $fourth->id)->set('staffTracked', true)->call('saveStaff')->assertHasNoErrors();
        $this->assertTrue($fourth->fresh()->is_tracked);
    }

    public function test_larger_or_unlimited_plans_bring_the_hospital_tools(): void
    {
        $this->onPlan(31);
        $this->assertTrue(AttendancePlan::hospital());
        Livewire::test(AttendanceComponent::class)->assertSeeHtml('wire:click="openDepartments"')->call('openDepartments')->assertSet('showDepartments', true);

        $this->onPlan(null);
        app(\App\Services\SubscriptionService::class)->forgetCurrent();
        $this->assertSame([null, true], [AttendancePlan::staffLimit(), AttendancePlan::hospital()]);
        // Not hosted (an install of its own): no limit.
        $this->onPlan(2);
        $this->clinic->forceFill(['deployment_mode' => 'offline'])->save();
        $this->assertSame([null, true], [AttendancePlan::staffLimit($this->clinic->fresh()), AttendancePlan::hospital($this->clinic->fresh())]);
    }

    public function test_the_platform_sets_the_number_on_each_plan(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        Livewire::withQueryParams(['tab' => 'plans'])->actingAs($admin)->test(PlatformDashboardComponent::class)
            ->call('openNewPlan')->assertSee('Staff on attendance')
            ->set('planName', 'Attendance small')->set('planCode', 'attendance-small')->set('basePrice', '40')->set('annualPrice', '400')
            ->set('planProduct', 'attendance')->set('attendanceStaff', '10')->call('savePlan')->assertHasNoErrors()->assertSee('10 on attendance');
        $plan = SubscriptionPlan::where('code', 'attendance-small')->sole();
        $this->assertSame(10, $plan->attendance_staff);
        $this->assertSame(10, app(\App\Services\SubscriptionLifecycleService::class)->snapshots($plan)['plan_snapshot']['attendance_staff']);
    }
}
