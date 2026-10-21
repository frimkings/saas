<?php

namespace Tests\Feature;

use App\Livewire\Platform\PricingRequestsComponent;
use App\Mail\OwnerNoticeMail;
use App\Models\PlatformSetting;
use App\Models\PricingRequest;
use App\Models\User;
use App\Services\PlatformRequestAlerts;
use App\Services\PricingCatalogue;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class PricingPageTest extends TestCase
{
    use RefreshDatabase;

    private const INBOX = 'requests@platform.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->artisan('pricing:clinical-suite')->assertSuccessful();
        PlatformSetting::put(['support_email' => self::INBOX, 'support_whatsapp' => '0241234567', 'support_name' => 'Nexus App']);
    }

    private function request(array $overrides = []): array
    {
        return $overrides + ['clinic_name' => 'Bright Eyes', 'contact_name' => 'Ama Mensah', 'phone' => '0244000111', 'email' => 'ama@brighteyes.test',
            'town' => 'Kumasi', 'plan' => 'clinic-standard', 'interval' => 'monthly', 'users' => 1, 'storage_gb' => 0, 'visits' => 0, 'branches' => 1];
    }

    public function test_the_page_lists_the_five_plans_and_the_login_page_links_to_it(): void
    {
        $this->get('/pricing')->assertOk()
            ->assertSeeInOrder(['Clinic Starter', 'GHS 100', 'Clinic Essential', 'Clinic Standard', 'Most popular', 'Clinic Plus', 'Clinic Pro', 'GHS 320'])
            ->assertSee('Build your own')->assertSee('https://wa.me/233241234567', false);

        $this->get('/login')->assertOk()->assertSee(route('pricing'), false)->assertSee('See plans &amp; pricing', false);
    }

    public function test_offline_installs_have_no_pricing_page_or_link(): void
    {
        config(['tenancy.enabled' => false]);

        $this->get('/pricing')->assertNotFound();
        $this->post('/pricing/request', $this->request())->assertNotFound();
        $this->get('/login')->assertOk()->assertDontSee(route('pricing'), false);
    }

    public function test_the_quote_is_the_cheapest_plan_that_covers_the_selection(): void
    {
        $catalogue = app(PricingCatalogue::class);

        // Referral letters and 1 GB of scans for a small clinic: Starter + two add-ons beats Essential.
        $small = $catalogue->quote(['features' => [Feature::REFERRALS], 'users' => 2, 'storage_gb' => 1, 'visits' => 5]);
        $this->assertSame('clinic-starter', $small['best']['family']);
        $this->assertEquals(135, $small['best']['monthly']);
        $this->assertSame([['Referral letters', 15.0], ['Scan storage: 1 × 1 GB', 20.0]], $small['best']['lines']);

        // Expenses and the audit trail for 3 users and 12 patients a day: Standard (180) beats Essential + add-ons (200).
        $mid = $catalogue->quote(['features' => [Feature::EXPENSE_TRACKING, Feature::AUDIT_TRAIL], 'users' => 3, 'visits' => 12]);
        $this->assertSame('clinic-standard', $mid['best']['family']);
        $this->assertFalse($mid['options'][0]['ok']);
        $this->assertSame('Meant for up to 8 patients a day', $mid['options'][0]['why']);

        // Five users rule out every plan with a user limit.
        $this->assertSame('clinic-plus', $catalogue->quote(['users' => 5])['best']['family']);

        // Any plan takes more branches, each at the plan's own price (yearly too): Standard with 2 is 180 + 180.
        $branches = $catalogue->quote(['branches' => 2, 'features' => [Feature::EXPENSE_TRACKING, Feature::AUDIT_TRAIL], 'visits' => 20]);
        $this->assertSame('clinic-standard', $branches['best']['family']);
        $this->assertSame([['1 extra branch', 180.0, 1800.0]], $branches['best']['lines']);
        $this->assertEquals([360, 3600], [$branches['best']['monthly'], $branches['best']['yearly']]);

        // Yearly: the plan's yearly price plus twelve months of each add-on.
        $this->assertEquals(1000 + 12 * 35, $small['best']['yearly']);
    }

    public function test_an_extra_not_offered_on_its_own_needs_the_plan_that_has_it(): void
    {
        \App\Models\PlatformAddon::where('feature', Feature::APPROVALS)->update(['is_offered' => false]);

        $quote = app(PricingCatalogue::class)->quote(['features' => [Feature::APPROVALS]]);

        $this->assertSame('clinic-pro', $quote['best']['family']);
        $this->assertSame('Approvals (voids, discounts) isn\'t in this plan', $quote['options'][0]['why']);
    }

    public function test_a_request_is_saved_quoted_again_and_emailed_to_the_platform(): void
    {
        Mail::fake();

        $this->post('/pricing/request', $this->request(['plan' => 'clinic-starter', 'features' => [Feature::REFERRALS], 'storage_gb' => 1, 'interval' => 'yearly',
            'message' => 'We open in December']))
            ->assertRedirect(route('pricing'))->assertSessionHas('pricing_sent');

        $request = PricingRequest::sole();
        $this->assertSame(['Clinic Starter', 'new', 'yearly', '135.00', '1420.00'], [$request->plan_name, $request->status, $request->billing_interval, $request->monthly_total, $request->quoted_total]);
        $this->assertSame([Feature::REFERRALS], $request->selection['features']);
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo(self::INBOX) && $mail->hasReplyTo('ama@brighteyes.test')
            && $mail->heading === 'Bright Eyes asked for Clinic Starter' && $mail->details['Price'] === 'GHS 1,420.00 a year'
            && $mail->details['Add-ons'] === 'Referral letters, Scan storage: 1 × 1 GB');
    }

    public function test_a_plan_that_does_not_cover_the_selection_or_a_bot_is_refused(): void
    {
        Mail::fake();

        $this->from('/pricing')->post('/pricing/request', $this->request(['plan' => 'clinic-starter', 'users' => 5]))
            ->assertSessionHasErrors(['plan' => 'Clinic Starter doesn\'t cover what you chose: Up to 2 users. Pick the suggested plan instead.']);
        $this->from('/pricing')->post('/pricing/request', $this->request(['plan' => 'no-such-plan']))->assertSessionHasErrors('plan');
        $this->from('/pricing')->post('/pricing/request', $this->request(['website' => 'http://spam.example']))->assertSessionHasErrors('website');
        $this->from('/pricing')->post('/pricing/request', $this->request(['phone' => 'call me']))->assertSessionHasErrors('phone');

        $this->assertSame(0, PricingRequest::count());
        Mail::assertNothingSent();
    }

    public function test_the_platform_follows_requests_up_and_waiting_ones_are_in_the_morning_reminder(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['name' => 'Platform Ama']);
        $admin->forceFill(['is_platform_admin' => true])->save();
        $request = PricingRequest::create(['clinic_name' => 'Bright Eyes', 'contact_name' => 'Ama', 'phone' => '0244000111', 'plan_name' => 'Clinic Standard',
            'monthly_total' => 180, 'quoted_total' => 180, 'selection' => ['users' => 4]]);
        $request->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->assertSame('sent', app(PlatformRequestAlerts::class)->remindWaiting());
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => isset($mail->details['Pricing page: Clinic Standard · Bright Eyes']));

        $this->actingAs($admin)->get(route('platform.pricing'))->assertOk()->assertSee('Bright Eyes')->assertSee('1 new');
        Livewire::actingAs($admin)->test(PricingRequestsComponent::class)
            ->call('open', $request->id)->set('notes', 'Called, demo on Friday')->call('mark', $request->id, 'contacted');
        $this->assertSame(['contacted', 'Called, demo on Friday', $admin->id], [$request->fresh()->status, $request->fresh()->notes, $request->fresh()->handled_by]);

        $staff = User::factory()->create();
        $this->actingAs($staff)->get(route('platform.pricing'))->assertForbidden();
    }

    public function test_the_platform_chooses_which_plans_the_page_shows(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        Livewire::actingAs($admin)->test(PricingRequestsComponent::class)->call('setTab', 'page')
            ->set('page.clinic-essential.show', false)->set('page.clinic-pro.max_visits', '120')->set('page.clinic-pro.tagline', 'For hospitals')
            ->call('savePage')->assertHasNoErrors();

        $tiers = app(PricingCatalogue::class)->tiers();
        $this->assertSame(['clinic-starter', 'clinic-standard', 'clinic-plus', 'clinic-pro'], $tiers->pluck('family')->all());
        $this->assertSame([120, 'For hospitals'], [$tiers->last()['max_visits'], $tiers->last()['tagline']]);
        $this->get('/pricing')->assertDontSee('Clinic Essential')->assertSee('For hospitals');
    }
}
