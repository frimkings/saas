<?php

namespace Tests\Feature;

use App\Livewire\Platform\ClinicAttendanceSizeComponent;
use App\Models\Clinic;
use App\Models\ClinicAddon;
use App\Models\ClinicSubscription;
use App\Models\PlatformInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AttendanceSizing;
use App\Services\ClinicAddonService;
use App\Services\PricingCatalogue;
use App\Services\SubscriptionBillingService;
use App\Services\SubscriptionService;
use App\Support\AttendancePlan;
use App\Support\Feature;
use App\Support\PlanProduct;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Attendance priced by staff size: package, size and a locked price, billed on renewals. */
class AttendanceBillingTest extends TestCase
{
    use RefreshDatabase;

    /** A plan at GHS 200 a month, current period 1–30 Oct; today is 10 Oct. */
    private function clinic(string $interval = 'monthly', array $features = ['clinical'], float $price = 200): array
    {
        Mail::fake();
        config(['tenancy.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00'));
        $owner = User::factory()->create(['name' => 'Kofi Owner']);
        $owner->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $clinic = Clinic::create(['name' => 'Korle Care', 'slug' => 'korle-care', 'deployment_mode' => 'hosted', 'status' => 'active', 'billing_email' => 'owner@korle.test']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($owner->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($owner->id, ['status' => 'active', 'is_default' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Basic', 'code' => 'basic', 'features' => $features, 'base_price' => $price, 'annual_price' => $price * 10,
            'billing_interval' => $interval, 'currency' => 'GHS', 'tax_rate' => 0]);
        $subscription = ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => $interval,
            'current_period_starts_at' => Carbon::parse('2026-10-01 00:00:00'), 'current_period_ends_at' => Carbon::parse('2026-10-30 23:59:59')]);
        app(TenantContext::class)->set($owner, $clinic, $branch, [$branch->id]);
        $this->actingAs($owner);

        return [$clinic, $subscription->fresh()];
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    private function renewalLines(ClinicSubscription $subscription): array
    {
        return app(SubscriptionBillingService::class)->invoiceFor($subscription->fresh())->lines()->orderBy('sort')->get()
            ->map(fn ($line) => [$line->description, (float) $line->amount])->all();
    }

    public function test_starting_attendance_by_size_switches_it_on_charges_the_rest_and_bills_each_renewal(): void
    {
        [$clinic, $subscription] = $this->clinic();
        $subscriptions = app(SubscriptionService::class);
        $this->assertFalse($subscriptions->hasFeature($clinic, Feature::ATTENDANCE));

        // 80 staff on Standard is the 100 size: 190 + 120.
        $size = app(AttendanceSizing::class)->set($clinic, 'standard', 80, null, 'Signed', $this->admin());
        $this->assertSame(['Standard, up to 100 staff', '310.00', false], [$size->name(), $size->monthly_price, $size->custom_price]);

        $this->assertTrue($subscriptions->hasFeature($clinic, Feature::ATTENDANCE));
        $this->assertTrue($subscriptions->hasFeature($clinic, Feature::ATTENDANCE_SHIFTS));
        $this->assertFalse($subscriptions->hasFeature($clinic, Feature::ATTENDANCE_PAYROLL));
        $this->assertSame(100, AttendancePlan::staffLimit($clinic));

        // The rest of October now: 21 of 30 days.
        $charge = PlatformInvoice::where('source', 'addon')->sole();
        $this->assertEquals(217, $charge->total);
        $this->assertSame('Attendance Suite: Standard, up to 100 staff (21 days to 30 Oct 2026)', $charge->lines()->value('description'));

        $this->assertSame([['Basic plan (monthly)', 200.0], ['Attendance Suite: Standard, up to 100 staff', 310.0]], $this->renewalLines($subscription));
    }

    public function test_yearly_clinics_pay_twelve_months_and_an_attendance_only_plan_at_nothing_has_no_plan_line(): void
    {
        [$clinic, $subscription] = $this->clinic('yearly', PlanProduct::features(PlanProduct::ATTENDANCE, []), 0);
        app(AttendanceSizing::class)->set($clinic, 'basic', 40, null, null, null);

        $this->assertSame([['Attendance Suite: Basic, up to 50 staff', 1800.0]], $this->renewalLines($subscription));
    }

    public function test_the_price_stays_when_the_list_changes_until_repriced(): void
    {
        [$clinic, $subscription] = $this->clinic();
        $sizing = app(AttendanceSizing::class);
        $size = $sizing->set($clinic, 'basic', 40, null, null, null);
        $renewal = app(SubscriptionBillingService::class)->invoiceFor($subscription->fresh());
        $this->assertEquals(350, $renewal->total);

        PricingCatalogue::saveSettings(['attendance' => ['sizes' => [[50, 175], [599, 430]]]] + PricingCatalogue::settings());
        $this->assertEquals(150, $sizing->current($clinic->id)->monthly_price);

        // Repricing updates the renewal already issued, while it's unpaid.
        $sizing->reprice($size, null);
        $this->assertEquals(175, $sizing->current($clinic->id)->monthly_price);
        $this->assertEquals(375, $renewal->fresh()->total);
        $this->assertSame(1, $renewal->lines()->where('description', 'like', 'Attendance Suite:%')->count());
    }

    public function test_moving_up_a_size_raises_the_limit_now_and_the_price_from_the_next_renewal(): void
    {
        [$clinic, $subscription] = $this->clinic();
        $sizing = app(AttendanceSizing::class);
        $sizing->set($clinic, 'basic', 40, null, null, null);
        $this->assertSame(50, AttendancePlan::staffLimit($clinic));

        $sizing->set($clinic, 'basic', 120, null, 'Grew', null);

        $this->assertSame(150, AttendancePlan::staffLimit($clinic));
        // Only the first start was charged straight away.
        $this->assertSame(1, PlatformInvoice::where('source', 'addon')->count());
        $this->assertContains(['Attendance Suite: Basic, up to 150 staff', 225.0], $this->renewalLines($subscription));
    }

    public function test_special_pricing_needs_the_agreed_price_and_sets_that_many_staff(): void
    {
        [$clinic, $subscription] = $this->clinic();
        $sizing = app(AttendanceSizing::class);

        try {
            $sizing->set($clinic, 'complete', 650, null, null, null);
            $this->fail('A size from 600 staff needs a price.');
        } catch (ValidationException $e) {
            $this->assertSame('From 600 staff there is no list price: enter the monthly price you agreed.', $e->errors()['size'][0]);
        }

        $size = $sizing->set($clinic, 'complete', 650, 900, null, null);
        $this->assertSame([650, '900.00', true], [$size->staff_limit, $size->monthly_price, $size->custom_price]);
        $this->assertTrue(app(SubscriptionService::class)->hasFeature($clinic, Feature::ATTENDANCE_HOSPITAL));
        $this->assertContains(['Attendance Suite: Complete, up to 650 staff', 900.0], $this->renewalLines($subscription));
    }

    public function test_stopping_keeps_attendance_to_the_end_of_the_paid_period(): void
    {
        [$clinic, $subscription] = $this->clinic();
        $sizing = app(AttendanceSizing::class);
        $size = $sizing->stop($sizing->set($clinic, 'basic', 40, null, null, null), null, 'Closing a branch');

        $this->assertSame('2026-10-30 23:59:59', $size->ends_at->toDateTimeString());
        $this->assertTrue(app(SubscriptionService::class)->hasFeature($clinic, Feature::ATTENDANCE));
        $this->assertSame([['Basic plan (monthly)', 200.0]], $this->renewalLines($subscription));
    }

    public function test_staff_packs_and_flat_attendance_are_no_longer_sold_but_old_packs_still_count(): void
    {
        [$clinic] = $this->clinic();
        $addons = app(ClinicAddonService::class);
        $this->assertFalse($addons->catalogue($clinic)[Feature::ATTENDANCE]['offered']);

        foreach ([Feature::ATTENDANCE, Feature::ATTENDANCE_STAFF_PACK] as $feature) {
            try {
                $addons->add($clinic, $feature, 50, null, null);
                $this->fail("$feature should not be sold.");
            } catch (ValidationException) {
            }
        }

        ClinicAddon::create(['clinic_id' => $clinic->id, 'feature' => Feature::ATTENDANCE_STAFF_PACK, 'monthly_price' => 20, 'quantity' => 1, 'units' => 50,
            'currency' => 'GHS', 'starts_at' => now()->subMonth()]);
        app(AttendanceSizing::class)->set($clinic, 'basic', 40, null, null, null);
        $this->assertSame(100, AttendancePlan::staffLimit($clinic));
    }

    public function test_modules_cost_the_price_list_and_are_not_billed_once_the_package_has_them(): void
    {
        [$clinic, $subscription] = $this->clinic();
        $sizing = app(AttendanceSizing::class);
        $addons = app(ClinicAddonService::class);
        $sizing->set($clinic, 'standard', 40, null, null, null);

        $this->assertSame([60.0, true], [$addons->catalogue($clinic)[Feature::ATTENDANCE_PAYROLL]['price'], $addons->catalogue($clinic)[Feature::ATTENDANCE_PAYROLL]['offered']]);
        $addons->add($clinic, Feature::ATTENDANCE_PAYROLL, 60, null, null);
        $this->assertContains(['Attendance: payroll add-on', 60.0], $this->renewalLines($subscription));

        try {
            $addons->add($clinic, Feature::ATTENDANCE_SHIFTS, 80, null, null);
            $this->fail('Standard already has shifts.');
        } catch (ValidationException $e) {
            $this->assertSame('The clinic\'s attendance package already includes this.', $e->errors()['addon'][0]);
        }

        // On Professional, payroll comes with the package: the add-on isn't billed any more.
        PlatformInvoice::query()->delete();
        $sizing->set($clinic, 'professional', 40, null, null, null);
        $this->assertSame([['Basic plan (monthly)', 200.0], ['Attendance Suite: Professional, up to 50 staff', 370.0]], $this->renewalLines($subscription));
    }

    public function test_the_platform_sets_the_size_on_the_clinic_panel(): void
    {
        [$clinic] = $this->clinic();
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(ClinicAttendanceSizeComponent::class, ['clinicId' => $clinic->id])
            ->set('package', 'complete')->set('staff', '650')->call('save')
            ->assertHasErrors('size')->assertSee('From 600 staff there is no list price: enter the monthly price you agreed.')
            ->set('package', 'professional')->set('staff', '360')->assertSee('List price for Professional up to 400 staff: 570.00 a month.')
            ->call('save')->assertHasNoErrors()->assertSee('Professional, up to 400 staff');

        $this->assertSame(400, app(AttendanceSizing::class)->current($clinic->id)->staff_limit);
        $this->actingAs(User::factory()->create());
        Livewire::test(ClinicAttendanceSizeComponent::class, ['clinicId' => $clinic->id])->assertForbidden();
    }
}
