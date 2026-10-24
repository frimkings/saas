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
            'town' => 'Kumasi', 'plan' => 'clinic-standard', 'interval' => 'monthly', 'users' => 1, 'storage_mb' => 0, 'visits' => 0, 'branches' => 1];
    }

    public function test_the_page_lists_the_four_plans_and_the_login_page_links_to_it(): void
    {
        $this->get('/pricing')->assertOk()
            ->assertSeeInOrder(['Clinic Starter', 'GHS 100', 'Clinic Standard', 'Most popular', 'Clinic Plus', 'Clinic Pro', 'GHS 320'])
            ->assertDontSee('Clinic Essential')
            ->assertSee('Customize')->assertSee('https://wa.me/233241234567', false);

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

        // Referral letters and 1 GB of scans for a small clinic: Starter + two add-ons (135) beats Standard + a storage pack (200).
        $small = $catalogue->quote(['features' => [Feature::REFERRALS], 'users' => 2, 'storage_mb' => 1024, 'visits' => 5]);
        $this->assertSame('clinic-starter', $small['best']['family']);
        $this->assertEquals(135, $small['best']['monthly']);
        $this->assertSame([['Referral letters', 15.0], ['Scan storage: 1 × 1 GB', 20.0]], $small['best']['lines']);

        // Expenses and the audit trail for 3 users and 12 patients a day: Standard + two add-ons (230) beats Plus + one (275).
        $mid = $catalogue->quote(['features' => [Feature::EXPENSE_TRACKING, Feature::AUDIT_TRAIL], 'users' => 3, 'visits' => 12]);
        $this->assertSame('clinic-standard', $mid['best']['family']);
        $this->assertFalse($mid['options'][0]['ok']);
        $this->assertSame('Meant for up to 8 patients a day', $mid['options'][0]['why']);

        // Five users rule out every plan with a user limit.
        $this->assertSame('clinic-plus', $catalogue->quote(['users' => 5])['best']['family']);

        // Any plan takes more branches, each at the plan's own price (yearly too): Standard with 2 is 180 + 50 of add-ons + 180.
        $branches = $catalogue->quote(['branches' => 2, 'features' => [Feature::EXPENSE_TRACKING, Feature::AUDIT_TRAIL], 'visits' => 20]);
        $this->assertSame('clinic-standard', $branches['best']['family']);
        $this->assertContains(['1 extra branch', 180.0, 2160.0], $branches['best']['lines']);
        $this->assertEquals([410, 2160 + 12 * 50 + 2160], [$branches['best']['monthly'], $branches['best']['yearly']]);

        // Storage is in MB: 300 MB fits Plus as it is, while Standard (150 MB) needs a 1 GB pack.
        $scans = $catalogue->quote(['users' => 4, 'storage_mb' => 300, 'visits' => 20]);
        $this->assertSame([['Scan storage: 1 × 1 GB', 20.0]], collect($scans['options'])->firstWhere('family', 'clinic-standard')['lines']);
        $this->assertSame([], collect($scans['options'])->firstWhere('family', 'clinic-plus')['lines']);
        $this->assertSame(300, $scans['selection']['storage_mb']);
        $this->assertSame(2048, $catalogue->quote(['storage_gb' => 2])['selection']['storage_mb'], 'older forms sent GB');

        // Clinical documents on Starter uses its own 20 MB; a plan with no scan storage would bring a storage pack with it.
        $docs = $catalogue->quote(['features' => [Feature::CLINICAL_DOCUMENTS], 'users' => 2, 'visits' => 5]);
        $this->assertSame('clinic-starter', $docs['best']['family']);
        $this->assertSame([['Clinical documents & scan uploads', 15.0]], $docs['best']['lines']);
        $noStorage = $catalogue->tiers()->map(fn ($tier) => $tier['family'] === 'clinic-starter' ? ['storage_mb' => 0] + $tier : $tier);
        $this->assertSame([['Clinical documents & scan uploads', 15.0], ['Scan storage: 1 × 1 GB', 20.0]],
            $catalogue->quote(['features' => [Feature::CLINICAL_DOCUMENTS], 'users' => 2, 'visits' => 5], $noStorage)['best']['lines']);

        // Yearly: the plan's yearly price plus twelve months of each add-on.
        $this->assertEquals(1200 + 12 * 35, $small['best']['yearly'], 'yearly is twelve months: no free months');
    }

    public function test_an_extra_not_offered_on_its_own_needs_the_plan_that_has_it(): void
    {
        \App\Models\PlatformAddon::where('feature', Feature::APPROVALS)->update(['is_offered' => false]);

        $quote = app(PricingCatalogue::class)->quote(['features' => [Feature::APPROVALS]]);

        $this->assertSame('clinic-pro', $quote['best']['family']);
        $this->assertSame('Approvals (voids, discounts, refunds) isn\'t in this plan', $quote['options'][0]['why']);
    }

    public function test_a_request_is_saved_quoted_again_and_emailed_to_the_platform(): void
    {
        Mail::fake();

        $this->post('/pricing/request', $this->request(['plan' => 'clinic-starter', 'features' => [Feature::REFERRALS], 'storage_mb' => 1024, 'interval' => 'yearly',
            'message' => 'We open in December']))
            ->assertRedirect(route('pricing'))->assertSessionHas('pricing_sent');

        $request = PricingRequest::sole();
        $this->assertSame(['Clinic Starter', 'new', 'yearly', '135.00', '1620.00'], [$request->plan_name, $request->status, $request->billing_interval, $request->monthly_total, $request->quoted_total]);
        $this->assertSame([Feature::REFERRALS], $request->selection['features']);
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo(self::INBOX) && $mail->hasReplyTo('ama@brighteyes.test')
            && $mail->heading === 'Bright Eyes asked for Clinic Starter' && $mail->details['Price'] === 'GHS 1,620.00 a year'
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
            ->set('page.clinic-plus.show', false)->set('page.clinic-pro.max_visits', '120')->set('page.clinic-pro.tagline', 'For hospitals')
            ->call('savePage')->assertHasNoErrors();

        $tiers = app(PricingCatalogue::class)->tiers();
        $this->assertSame(['clinic-starter', 'clinic-standard', 'clinic-pro'], $tiers->pluck('family')->all());
        $this->assertSame([120, 'For hospitals'], [$tiers->last()['max_visits'], $tiers->last()['tagline']]);
        $this->get('/pricing')->assertDontSee('Clinic Plus')->assertSee('For hospitals');
    }
}
