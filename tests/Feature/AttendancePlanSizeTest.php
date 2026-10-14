<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Livewire\Platform\PlatformDashboardComponent;
use App\Models\AttendanceDepartment;
use App\Models\AttendanceShift;
use App\Models\AttendanceStaff;
use App\Models\Clinic;
use App\Models\ClinicAddonRequest;
use App\Models\ClinicSubscription;
use App\Models\PlatformAddon;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\AttendancePlan;
use App\Support\Feature;
use App\Support\PlanProduct;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * Attendance plans: the platform sets how many staff a plan may follow, and ticks which parts of
 * attendance it includes; the rest can be bought as add-ons. Plans from before attendance was
 * split keep every part.
 */
class AttendancePlanSizeTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
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

    /** An attendance-only plan with these parts ticked, made as the platform's plan screen makes it. */
    private function onPlan(?int $staff, array $parts = []): void
    {
        $plan = SubscriptionPlan::create(['name' => 'Plan '.uniqid(), 'code' => 'plan-'.uniqid(), 'features' => PlanProduct::features(PlanProduct::ATTENDANCE, $parts),
            'base_price' => 0, 'billing_interval' => 'monthly', 'attendance_staff' => $staff]);
        ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => 'monthly',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        app(\App\Services\SubscriptionService::class)->forgetCurrent();
    }

    public function test_a_plan_follows_so_many_staff(): void
    {
        $this->onPlan(3, [Feature::ATTENDANCE_SHIFTS]);
        $this->assertSame(3, AttendancePlan::staffLimit());

        // The fourth person on the terminal is kept, not followed.
        foreach (['001', '002', '003', '004'] as $n) {
            AttendanceStaff::create(['employee_no' => $n, 'name' => 'Person '.$n, 'is_tracked' => true]);
        }
        $this->assertSame([true, true, true, false], AttendanceStaff::orderBy('employee_no')->pluck('is_tracked')->all());

        $fourth = AttendanceStaff::where('employee_no', '004')->sole();
        Livewire::test(AttendanceComponent::class)->assertSee('Your plan follows up to')->assertSee('1 more is on the terminal but not followed')
            ->call('openStaff', $fourth->id)->set('staffTracked', true)->call('saveStaff')->assertHasErrors(['staffTracked']);
        $this->assertFalse($fourth->fresh()->is_tracked);

        // Room again once someone stops being followed.
        AttendanceStaff::where('employee_no', '001')->update(['is_tracked' => false]);
        Livewire::test(AttendanceComponent::class)->call('openStaff', $fourth->id)->set('staffTracked', true)->call('saveStaff')->assertHasNoErrors();
        $this->assertTrue($fourth->fresh()->is_tracked);

        // Unlimited, and installs of their own, have no limit.
        $this->onPlan(null);
        $this->assertNull(AttendancePlan::staffLimit());
        $this->onPlan(2);
        $this->clinic->forceFill(['deployment_mode' => 'offline'])->save();
        $this->assertNull(AttendancePlan::staffLimit($this->clinic->fresh()));
    }

    public function test_only_the_parts_ticked_on_the_plan_are_there_and_the_rest_can_be_asked_for(): void
    {
        PlatformAddon::create(['feature' => Feature::ATTENDANCE_PAYROLL, 'monthly_price' => 50, 'currency' => 'GHS', 'is_offered' => true]);
        $this->onPlan(null, [Feature::ATTENDANCE_SHIFTS]);
        $this->assertSame(['shifts' => true, 'overtime' => false, 'leave' => false, 'payroll' => false, 'phone' => false, 'trends' => false, 'hospital' => false], AttendancePlan::parts());
        AttendanceShift::create(['name' => 'Morning', 'starts_at' => '08:00', 'ends_at' => '14:00']);

        $page = Livewire::withQueryParams(['view' => 'trends'])->test(AttendanceComponent::class)->assertSet('view', 'summary')
            ->assertSeeHtml('wire:click="openRoster"')->assertDontSee('Month by month')
            ->assertDontSeeHtml('wire:click="openDepartments"')->assertDontSeeHtml('wire:click="openLeaveTypes"')->assertDontSeeHtml('wire:click="openPhone"')
            ->assertDontSeeHtml('wire:click="openPayroll"')
            // What is not in the plan is listed with what it does and, where offered, its price.
            ->set('showMore', true)->assertSee('More attendance features')->assertSee('Payroll')->assertSee('GHS 50.00 a month')->assertSee('Hospital tools')
            ->assertSee('Contact us to add it')
            ->call('requestPart', Feature::ATTENDANCE_PAYROLL)->assertSee('Asked for; waiting');
        $this->assertSame(1, ClinicAddonRequest::where('feature', Feature::ATTENDANCE_PAYROLL)->where('status', 'pending')->count());
        foreach (['openDepartments', 'openStaffImport', 'togglePattern', 'openPayroll', 'openLeaveTypes', 'openPhone'] as $action) {
            Livewire::test(AttendanceComponent::class)->call($action)->assertForbidden();
        }
        // Departments made before are not shown without the hospital tools, and give heads no way in.
        AttendanceDepartment::create(['name' => 'Nursing', 'heads' => [auth()->id()]]);
        $this->assertSame([], \App\Support\AttendanceScope::headed(auth()->user()));

        // The platform approves the request: payroll is there.
        app(\App\Services\ClinicAddonService::class)->approve(ClinicAddonRequest::sole(), null, null);
        app(\App\Services\SubscriptionService::class)->forgetCurrent();
        $this->assertTrue(AttendancePlan::parts()['payroll']);
        Livewire::test(AttendanceComponent::class)->call('openPayroll')->assertSet('showPayroll', true);
    }

    public function test_plans_from_before_attendance_was_split_keep_every_part(): void
    {
        // Saved before: attendance with no parts named.
        $old = [Feature::CLINICAL, Feature::ATTENDANCE];
        foreach (PlanProduct::ATTENDANCE_PARTS as $part) {
            $this->assertTrue(PlanProduct::allows($old, $part), $part);
        }
        $this->assertContains(Feature::ATTENDANCE_PAYROLL, PlanProduct::extrasOf($old));
        // Saved now, with attendance but no parts: only attendance itself.
        $new = PlanProduct::features(PlanProduct::CLINIC, [Feature::ATTENDANCE]);
        $this->assertTrue(PlanProduct::allows($new, Feature::ATTENDANCE));
        $this->assertFalse(PlanProduct::allows($new, Feature::ATTENDANCE_PAYROLL));
        // A part never comes without attendance, and every extra ('*') includes them all.
        $this->assertFalse(PlanProduct::allows(PlanProduct::features(PlanProduct::CLINIC, [Feature::ATTENDANCE_PAYROLL]), Feature::ATTENDANCE_PAYROLL));
        $this->assertTrue(PlanProduct::allows(PlanProduct::features(PlanProduct::ATTENDANCE, [], true), Feature::ATTENDANCE_HOSPITAL));

        // An old attendance-only plan in use: everything is there.

        $plan = SubscriptionPlan::create(['name' => 'Old', 'code' => 'old', 'features' => [Feature::ATTENDANCE_ONLY, Feature::ATTENDANCE], 'base_price' => 0, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => 'monthly',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        app(\App\Services\SubscriptionService::class)->forgetCurrent();
        $this->assertNotContains(false, AttendancePlan::parts());
    }

    public function test_the_platform_sets_the_staff_number_and_ticks_the_parts(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        Livewire::withQueryParams(['tab' => 'plans'])->actingAs($admin)->test(PlatformDashboardComponent::class)
            ->call('openNewPlan')->assertSee('Staff on attendance')
            ->set('planName', 'Attendance small')->set('planCode', 'attendance-small')->set('basePrice', '40')->set('annualPrice', '400')
            ->set('planProduct', 'attendance')->assertSee('Attendance: payroll')->assertSee('Attendance: hospital tools')
            ->set('attendanceStaff', '10')->set('planExtras', [Feature::ATTENDANCE_SHIFTS, Feature::ATTENDANCE_TRENDS])->call('savePlan')->assertHasNoErrors()
            ->assertSee('10 on attendance');
        $plan = SubscriptionPlan::where('code', 'attendance-small')->sole();
        $this->assertSame(10, $plan->attendance_staff);
        $this->assertTrue(PlanProduct::allows($plan->features, Feature::ATTENDANCE_TRENDS));
        $this->assertFalse(PlanProduct::allows($plan->features, Feature::ATTENDANCE_PAYROLL));
        $this->assertSame(10, app(\App\Services\SubscriptionLifecycleService::class)->snapshots($plan)['plan_snapshot']['attendance_staff']);
    }
}
