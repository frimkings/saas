<?php

namespace Tests\Feature;

use App\Livewire\Admin\ClinicAddonsComponent as ClinicSide;
use App\Livewire\Platform\AddonCatalogueComponent;
use App\Livewire\Platform\ClinicAddonsComponent as PlatformSide;
use App\Mail\OwnerNoticeMail;
use App\Models\Clinic;
use App\Models\ClinicAddon;
use App\Models\ClinicAddonRequest;
use App\Models\ClinicSubscription;
use App\Models\PlatformAddon;
use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ClinicAddonService;
use App\Services\SubscriptionBillingService;
use App\Services\SubscriptionService;
use App\Support\Feature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** A clinic on a plan can have extra features added on top, billed per month. */
class ClinicAddonsTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'owner@brighteyes.test';

    /** Basic plan (no extras), GHS 200 a month, current period 1–30 Oct; today is 10 Oct. */
    private function clinic(string $status = 'active', string $interval = 'monthly', array $features = ['clinical']): array
    {
        config(['tenancy.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00'));
        $owner = User::factory()->create(['name' => 'Kofi Owner']);
        $owner->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $clinic = Clinic::create(['name' => 'Bright Eyes', 'slug' => 'bright-eyes', 'deployment_mode' => 'hosted', 'status' => 'active', 'billing_email' => self::OWNER]);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($owner->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($owner->id, ['status' => 'active', 'is_default' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Basic', 'code' => 'basic', 'features' => $features, 'base_price' => 200, 'annual_price' => 2000,
            'billing_interval' => $interval, 'currency' => 'GHS', 'tax_rate' => 0]);
        $subscription = ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => $status, 'billing_interval' => $interval,
            'trial_ends_at' => $status === 'trial' ? Carbon::parse('2026-10-30 23:59:59') : null,
            'current_period_starts_at' => Carbon::parse('2026-10-01 00:00:00'), 'current_period_ends_at' => Carbon::parse('2026-10-30 23:59:59')]);
        PlatformAddon::create(['feature' => Feature::DAILY_SUMMARY, 'monthly_price' => 30, 'is_offered' => true]);
        PlatformAddon::create(['feature' => Feature::SMS_CAMPAIGNS, 'monthly_price' => 50, 'is_offered' => false]);
        app(TenantContext::class)->set($owner, $clinic, $branch, [$branch->id]);
        $this->actingAs($owner);

        return [$clinic, $subscription->fresh(), $owner];
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create(['name' => 'Platform Ama']);
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    public function test_an_add_on_switches_the_feature_on_without_touching_the_plan_and_charges_the_rest_of_the_period(): void
    {
        Mail::fake();
        [$clinic, $subscription] = $this->clinic();
        $this->assertFalse(app(SubscriptionService::class)->hasFeature($clinic, Feature::DAILY_SUMMARY));

        app(ClinicAddonService::class)->add($clinic, Feature::DAILY_SUMMARY, 30, 'Asked by phone', $this->platformAdmin());

        $this->assertTrue(app(SubscriptionService::class)->hasFeature($clinic, Feature::DAILY_SUMMARY));
        $this->assertFalse(app(SubscriptionService::class)->hasFeature($clinic, Feature::SMS_CAMPAIGNS));
        // Same subscription, same dates: nothing about the plan changed.
        $this->assertSame($subscription->id, app(SubscriptionService::class)->current($clinic)->id);
        $this->assertSame('2026-10-30', $subscription->fresh()->current_period_ends_at->toDateString());

        // 21 of the period's 30 days are left: 30 × 21 / 30.
        $invoice = PlatformInvoice::where('source', 'addon')->sole();
        $this->assertSame(['21.00', '21.00'], [$invoice->subtotal, $invoice->total]);
        $this->assertSame('Daily sales email to owner add-on (21 days to 30 Oct 2026)', $invoice->lines->sole()->description);
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo(self::OWNER) && $mail->heading === 'Daily sales email to owner has been added'
            && str_starts_with($mail->details['Charged now'], 'GHS 21.00') && $mail->details['Price'] === 'GHS 30.00 a month');
    }

    public function test_renewals_list_the_plan_and_each_add_on(): void
    {
        Mail::fake();
        [$clinic, $subscription] = $this->clinic();
        app(ClinicAddonService::class)->add($clinic, Feature::DAILY_SUMMARY, 30, null, null);

        $renewal = app(SubscriptionBillingService::class)->invoiceFor($subscription->fresh());
        $this->assertSame([['Basic plan (monthly)', '200.00'], ['Daily sales email to owner add-on', '30.00']],
            $renewal->lines->map(fn ($line) => [$line->description, $line->amount])->all());
        $this->assertSame('230.00', $renewal->total);
        $pdf = view('pdf.subscription-invoice', ['invoice' => $renewal->load(['clinic', 'payments']), 'receipt' => false])->render();
        $this->assertStringContainsString('Daily sales email to owner add-on', $pdf);
    }

    public function test_yearly_clinics_pay_twelve_months_of_an_add_on(): void
    {
        Mail::fake();
        [$clinic, $subscription] = $this->clinic(interval: 'yearly');
        app(ClinicAddonService::class)->add($clinic, Feature::DAILY_SUMMARY, 30, null, null);

        $this->assertSame('360.00', app(SubscriptionBillingService::class)->invoiceFor($subscription->fresh())->lines->last()->amount);
    }

    public function test_an_add_on_joins_a_renewal_already_issued_and_a_trial_pays_nothing_until_it_renews(): void
    {
        Mail::fake();
        [$clinic, $subscription] = $this->clinic('trial');
        $renewal = app(SubscriptionBillingService::class)->invoiceFor($subscription);   // issued before the add-on
        $this->assertSame('200.00', $renewal->total);

        app(ClinicAddonService::class)->add($clinic, Feature::DAILY_SUMMARY, 30, null, null);

        $this->assertSame(0, PlatformInvoice::where('source', 'addon')->count());   // trial: nothing now
        $this->assertSame('230.00', $renewal->fresh()->total);
        $this->assertCount(2, $renewal->fresh()->lines);
    }

    public function test_cancelling_keeps_it_working_to_the_end_of_the_paid_period_and_takes_it_off_the_next_renewal(): void
    {
        Mail::fake();
        [$clinic, $subscription] = $this->clinic();
        $addon = app(ClinicAddonService::class)->add($clinic, Feature::DAILY_SUMMARY, 30, null, null);
        $renewal = app(SubscriptionBillingService::class)->invoiceFor($subscription->fresh());
        $this->assertSame('230.00', $renewal->total);

        app(ClinicAddonService::class)->cancel($addon, $this->platformAdmin(), 'Owner asked');

        $this->assertSame('2026-10-30', $addon->fresh()->ends_at->toDateString());
        $this->assertTrue(app(SubscriptionService::class)->hasFeature($clinic, Feature::DAILY_SUMMARY));
        $this->assertSame('200.00', $renewal->fresh()->total);
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->heading === 'Daily sales email to owner has been cancelled' && $mail->details['Works until'] === '30 Oct 2026');

        $this->travelTo(Carbon::parse('2026-10-31 09:00:00'));
        app()->forgetScopedInstances();
        $this->assertFalse(app(SubscriptionService::class)->hasFeature($clinic, Feature::DAILY_SUMMARY));
    }

    public function test_an_add_on_the_plan_already_includes_is_refused(): void
    {
        [$clinic, $subscription] = $this->clinic(features: ['clinical', Feature::DAILY_SUMMARY]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ClinicAddonService::class)->add($clinic, Feature::DAILY_SUMMARY, 30, null, null);
    }

    public function test_clinic_asks_for_an_add_on_the_platform_is_emailed_and_approving_adds_and_charges_it(): void
    {
        Mail::fake();
        PlatformSetting::put(['support_email' => 'support@visionspacegh.com']);
        [$clinic] = $this->clinic();

        // Only offered add-ons are listed to the clinic.
        Livewire::test(ClinicSide::class)->assertSee('Daily sales email to owner')->assertSee('GHS 30.00 a month')->assertDontSee('SMS reminders')
            ->call('ask', Feature::DAILY_SUMMARY)->set('message', 'For the Kasoa branch too')->call('send')->assertHasNoErrors()
            ->assertSee('Requested · waiting for approval');
        $request = ClinicAddonRequest::sole();
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo('support@visionspacegh.com')
            && $mail->heading === 'Bright Eyes wants the Daily sales email to owner add-on' && $mail->details['Message'] === 'For the Kasoa branch too');

        $admin = $this->platformAdmin();
        Livewire::actingAs($admin)->test(PlatformSide::class, ['clinicId' => $clinic->id])
            ->assertSee('Waiting for your answer')->set("approvePrice.{$request->id}", '25')->call('approve', $request->id)->assertHasNoErrors();

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame('25.00', ClinicAddon::sole()->monthly_price);
        $this->assertSame('17.50', PlatformInvoice::where('source', 'addon')->sole()->total);   // 25 × 21 / 30
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo(self::OWNER) && $mail->heading === 'Daily sales email to owner has been added');
    }

    public function test_declining_tells_the_owner_and_unoffered_add_ons_cannot_be_requested(): void
    {
        Mail::fake();
        [$clinic, , $owner] = $this->clinic();
        $request = app(ClinicAddonService::class)->request($clinic, Feature::DAILY_SUMMARY, null, $owner);
        app(ClinicAddonService::class)->reject($request, 'Not available on Basic', $this->platformAdmin());

        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo(self::OWNER) && $mail->heading === 'Your request for Daily sales email to owner was not approved'
            && $mail->details['Note'] === 'Not available on Basic');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ClinicAddonService::class)->request($clinic, Feature::SMS_CAMPAIGNS, null, $owner);   // not offered
    }

    public function test_platform_sets_add_on_prices_and_only_platform_admins_manage_a_clinics_add_ons(): void
    {
        [$clinic, , $owner] = $this->clinic();
        $admin = $this->platformAdmin();

        Livewire::actingAs($admin)->test(AddonCatalogueComponent::class)
            ->set('rows.' . Feature::WEEKLY_SUMMARY . '.price', '45')->set('rows.' . Feature::WEEKLY_SUMMARY . '.offered', true)
            ->call('save', Feature::WEEKLY_SUMMARY)->assertHasNoErrors();
        $this->assertDatabaseHas('platform_addons', ['feature' => Feature::WEEKLY_SUMMARY, 'monthly_price' => 45, 'is_offered' => true]);

        Livewire::actingAs($owner)->test(PlatformSide::class, ['clinicId' => $clinic->id])->assertForbidden();
    }
}
