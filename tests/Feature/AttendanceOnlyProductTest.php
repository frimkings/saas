<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Livewire\Admin\UserRoleManagerComponent;
use App\Livewire\Platform\PlatformDashboardComponent;
use App\Mail\OwnerSummaryMail;
use App\Models\AttendanceStaff;
use App\Models\Clinic;
use App\Models\ClinicAddon;
use App\Models\ClinicSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ClinicAddonService;
use App\Services\LicenseService;
use App\Services\OwnerSummaryService;
use App\Services\SubscriptionService;
use App\Support\ClinicNavigation;
use App\Support\Feature;
use App\Support\NavigationWorkspace;
use App\Support\OpticalMode;
use App\Support\PlanProduct;
use App\Support\Tenancy\RoleDashboardResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Staff attendance is an extra on clinic and optical plans, and a product of its own for a
 * business that wants nothing else: those subscribers see attendance and the pages to run it.
 */
class AttendanceOnlyProductTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    private function plan(string $code, string $product, array $extras = [], bool $allExtras = false): SubscriptionPlan
    {
        return SubscriptionPlan::create(['name' => ucfirst($code), 'code' => $code, 'family_code' => $code, 'version' => 1, 'product' => $product,
            'features' => PlanProduct::features($product, $extras, $allExtras), 'currency' => 'GHS', 'base_price' => 100, 'annual_price' => 1000,
            'included_branches' => 1, 'trial_days' => 14, 'billing_interval' => 'monthly', 'is_active' => true]);
    }

    /** Someone signed in to a clinic on a plan for $product. */
    private function tenantUser(string $product, string $roleName = 'Super Admin', array $extras = []): User
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user->assignRole($role);
        $this->clinic = Clinic::create(['name' => 'Tenant', 'slug' => 'tenant-'.$product, 'status' => 'active', 'deployment_mode' => 'hosted', 'billing_email' => 'owner@tenant.test']);
        $branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $this->clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'role_id' => $role->id]);
        ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $this->plan('plan-'.$product, $product, $extras)->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        app(TenantContext::class)->set($user, $this->clinic, $branch, [$branch->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_feature_rules_for_attendance(): void
    {
        $only = PlanProduct::features('attendance', ['audit_trail', 'sms_campaigns', 'appointments']);
        // Extras that mean nothing without a clinic or a shop are dropped.
        $this->assertSame(['attendance_only', 'attendance', 'audit_trail'], $only);
        $this->assertSame('attendance', PlanProduct::productOf($only));
        $this->assertTrue(PlanProduct::allows($only, 'attendance'));
        $this->assertTrue(PlanProduct::allows($only, 'audit_trail'));
        foreach (['clinical', 'optical', 'sms_campaigns', 'inventory', 'approvals', 'owner_daily_summary'] as $feature) {
            $this->assertFalse(PlanProduct::allows($only, $feature), $feature);
        }
        // "Every extra" means every extra this product can have.
        $all = PlanProduct::features('attendance', [], true);
        $this->assertTrue(PlanProduct::allows($all, 'owner_daily_summary'));
        $this->assertFalse(PlanProduct::allows($all, 'sms_campaigns'));
        $this->assertFalse(PlanProduct::allows($all, 'clinical'));
        $this->assertSame(PlanProduct::ATTENDANCE_EXTRAS, PlanProduct::extrasOf($all));

        // On a clinic or optical plan attendance is an extra like any other: ticked, in "every extra", or not there.
        $this->assertFalse(PlanProduct::allows(PlanProduct::features('clinic', ['appointments']), 'attendance'));
        $withIt = PlanProduct::features('clinic', ['attendance']);
        $this->assertSame(['clinical', 'attendance'], $withIt);
        $this->assertSame('clinic', PlanProduct::productOf($withIt));
        $this->assertTrue(PlanProduct::allows($withIt, 'attendance'));
        $this->assertTrue(PlanProduct::allows(PlanProduct::features('optical', [], true), 'attendance'));
        // Older "everything" plans keep it.
        foreach ([[], ['*'], null] as $legacy) {
            $this->assertTrue(PlanProduct::allows($legacy, 'attendance'));
            $this->assertSame('both', PlanProduct::productOf($legacy));
        }
        $this->assertArrayNotHasKey('attendance', PlanProduct::extrasFor('attendance'));
        $this->assertArrayHasKey('attendance', PlanProduct::extrasFor('optical'));
        $this->assertArrayNotHasKey('referrals', PlanProduct::extrasFor('optical'));
    }

    public function test_an_attendance_only_manager_lands_on_attendance_and_sees_only_its_pages(): void
    {
        $user = $this->tenantUser('attendance');

        $this->assertTrue(OpticalMode::attendanceOnly());
        $this->assertFalse(OpticalMode::opticalOnly());
        $this->assertSame('admin.attendance', app(RoleDashboardResolver::class)->routeName($user));
        $this->assertSame([], NavigationWorkspace::available($user));

        $labels = fn (array $menu) => collect($menu)->flatMap(fn ($item) => [$item['label'], ...array_column($item['items'] ?? [], 'label')])->all();
        // No audit trail on this plan, so those two links are left out.
        $this->assertSame(['Attendance', 'My profile', 'Messages', 'Staff & Security', 'Users', 'System', 'Settings', 'Branches', 'License & Subscription', 'Subscription & Billing'],
            $labels(ClinicNavigation::menu('admin')));

        $this->get(route('dashboard'))->assertRedirect(route('admin.attendance'));
        $this->get(route('admin.attendance'))->assertOk()->assertSee('Attendance')->assertDontSee('Business Overview')->assertDontSee('Finances');
        // Clinic, sales and optical pages lead back to attendance; an old start page does so quietly.
        foreach (['admin.diagnoses', 'secretary.patients', 'cashier.seller-desk', 'admin.reports', 'optical.orders', 'admin.roles-permissions', 'attention'] as $route) {
            $this->get(route($route))->assertRedirect(route('admin.attendance'))->assertSessionHas('product_notice', 'That page is not part of your attendance subscription.');
        }
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.attendance'))->assertSessionMissing('product_notice');
        $this->get(route('admin.login-history'))->assertForbidden();
        foreach (['user.profile', 'admin.users', 'admin.branches', 'admin.subscription'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_attendance_only_staff_get_a_plain_role_and_their_own_page(): void
    {
        $this->tenantUser('attendance');
        // The Users page offers Staff and the two managing roles, nothing from the clinic or the shop.
        foreach (['Doctor', 'Secretary', 'Cashier', 'Manager'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Livewire::test(UserRoleManagerComponent::class)->assertViewHas('availableRoles', fn ($roles) => $roles->pluck('name')->all() === ['Manager', 'Staff', 'Super Admin']);

        $staff = User::factory()->create();
        $role = Role::where('name', 'Staff')->firstOrFail();
        $staff->assignRole($role);
        $branch = $this->clinic->branches()->first();
        $this->clinic->users()->attach($staff->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($staff->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['user_id' => $staff->id, 'branch_id' => $branch->id, 'role_id' => $role->id]);
        AttendanceStaff::create(['employee_no' => '007', 'name' => $staff->name, 'user_id' => $staff->id]);
        app(TenantContext::class)->set($staff, $this->clinic, $branch, [$branch->id]);
        $this->actingAs($staff);

        $this->assertSame('user.profile', app(RoleDashboardResolver::class)->routeName($staff));
        $this->assertSame(['My attendance', 'Messages'], array_column(ClinicNavigation::menu('reception'), 'label'));
        $this->get(route('dashboard'))->assertRedirect(route('user.profile'));
        $this->get(route('user.profile'))->assertOk()->assertSee('My attendance')->assertDontSee('Point of Sale');
        $this->get(route('admin.attendance'))->assertForbidden();
        $this->get(route('secretary.patients'))->assertRedirect(route('user.profile'));
    }

    public function test_a_clinic_plan_has_attendance_only_when_it_is_ticked_or_added(): void
    {
        $user = $this->tenantUser('clinic', 'Super Admin', ['appointments']);

        $this->assertFalse(LicenseService::has(Feature::ATTENDANCE));
        $this->get(route('admin.attendance'))->assertForbidden();
        $this->get(route('admin.attendance.export', ['format' => 'csv']))->assertForbidden();
        $this->assertNotContains('Attendance', collect(ClinicNavigation::menu('admin'))->flatMap(fn ($item) => array_column($item['items'] ?? [], 'label'))->all());
        AttendanceStaff::create(['employee_no' => '001', 'name' => 'Ama Mensah', 'user_id' => $user->id]);
        $this->get(route('user.profile'))->assertOk()->assertDontSee('My attendance');
        // It is on the list of add-ons the clinic can have.
        $this->assertTrue(app(ClinicAddonService::class)->catalogue($this->clinic)->has('attendance'));

        ClinicAddon::create(['clinic_id' => $this->clinic->id, 'feature' => 'attendance', 'monthly_price' => 50, 'currency' => 'GHS', 'starts_at' => now()->subMinute()]);
        app()->forgetInstance(ClinicAddonService::class);
        app()->forgetInstance(SubscriptionService::class);

        $this->assertTrue(LicenseService::has(Feature::ATTENDANCE));
        $this->assertFalse(OpticalMode::attendanceOnly());
        $this->get(route('admin.attendance'))->assertOk();
        $this->get(route('user.profile'))->assertOk()->assertSee('My attendance');
        $this->assertContains('Attendance', collect(ClinicNavigation::menu('admin'))->flatMap(fn ($item) => array_column($item['items'] ?? [], 'label'))->all());
        Livewire::test(AttendanceComponent::class)->call('editHours')->assertSet('showHours', true);
    }

    public function test_the_platform_makes_and_filters_attendance_only_plans(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        Livewire::withQueryParams(['tab' => 'plans'])->actingAs($admin)->test(PlatformDashboardComponent::class)
            ->call('openNewPlan')->assertSee('Attendance only')
            ->set('planName', 'Attendance')->set('planCode', 'attendance-basic')->set('basePrice', '40')->set('annualPrice', '400')
            ->set('planProduct', 'attendance')->assertSee('Audit trail')->assertDontSee('Referral letters')->assertDontSee('SMS reminders')
            ->set('planExtras', ['audit_trail', 'sms_campaigns'])->call('savePlan')->assertHasNoErrors();

        $plan = SubscriptionPlan::where('code', 'attendance-basic')->firstOrFail();
        $this->assertSame('attendance', $plan->product);
        $this->assertSame(['attendance_only', 'attendance', 'audit_trail'], $plan->features);

        $clinicPlan = $this->plan('clinic-basic', 'clinic');
        foreach ([['Time Co', 'time-co', $plan], ['Clinic One', 'clinic-one', $clinicPlan]] as [$name, $slug, $its]) {
            $clinic = Clinic::create(['name' => $name, 'slug' => $slug, 'status' => 'active', 'deployment_mode' => 'hosted']);
            ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $its->id, 'status' => 'active',
                'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth()]);
        }
        Livewire::withQueryParams(['tab' => 'clinics'])->actingAs($admin)->test(PlatformDashboardComponent::class)
            ->set('productFilter', 'attendance')->assertSee('Time Co')->assertDontSee('Clinic One')
            ->set('productFilter', 'both')->assertDontSee('Time Co')
            ->call('openOnboarding')->set('newClinicProduct', 'attendance')->set('newPlanId', $plan->id)
            ->set('newClinicName', 'New Office')->set('newClinicSlug', 'new-office')->set('newBranchCode', 'MAIN')->set('newBranchName', 'Main')
            ->set('newAdminName', 'Office Owner')->set('newAdminEmail', 'owner@newoffice.test')
            ->call('onboardClinic')->assertHasNoErrors();

        $this->assertSame(['attendance_only', 'attendance', 'audit_trail'], Clinic::where('slug', 'new-office')->firstOrFail()->subscriptions()->latest('id')->first()->feature_snapshot);
    }

    public function test_the_owner_summary_for_attendance_only_has_no_sales_figures(): void
    {
        $this->tenantUser('attendance', 'Super Admin', ['owner_daily_summary']);
        $summaries = app(OwnerSummaryService::class);
        $this->assertTrue($summaries->includes($this->clinic, Feature::DAILY_SUMMARY));
        $this->assertFalse($summaries->includes($this->clinic, Feature::MORNING_ALERTS));

        $summary = $summaries->build($this->clinic, 'daily', Carbon::parse('2026-10-02'), Carbon::parse('2026-10-02'));
        $this->assertTrue($summary['attendanceOnly']);
        $this->assertSame('Daily attendance summary', $summary['title']);
        $this->assertSame(route('admin.attendance'), $summary['url']);
        $html = (new OwnerSummaryMail($summary))->render();
        $this->assertStringContainsString('There is no attendance to report', $html);
        $this->assertStringContainsString('Open attendance', $html);
        foreach (['No sales, payments or expenses', 'Open full reports', 'Sales count when made'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
    }
}
