<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Guides\Guides;
use App\Support\Guides\TourCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformToursTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_platform_admin' => true])->save();
    }

    public function test_every_platform_page_has_a_tour_and_what_it_points_at(): void
    {
        $pages = [
            'platform.dashboard' => [route('platform.dashboard'), ['pf-metrics', 'pf-find', 'pf-directory', 'pf-onboard', 'pf-nav', 'nav-quotes', 'pf-modes']],
            'platform.clinics' => [route('platform.dashboard', ['tab' => 'clinics']), ['pf-find', 'pf-directory', 'pf-onboard']],
            'platform.plans' => [route('platform.dashboard', ['tab' => 'plans']), ['pf-plans', 'pf-plan-products', 'pf-new-plan']],
            'platform.billing' => [route('platform.dashboard', ['tab' => 'billing']), ['pf-billing-sections', 'pf-billing-summary', 'pf-invoice-filters', 'pf-invoice-actions']],
            'platform.pricing' => [route('platform.pricing'), ['pr-tabs', 'pr-status', 'pr-list']],
            'platform.quotes' => [route('platform.quotes'), ['pq-new', 'pq-status', 'pq-list']],
            'platform.sms' => [route('platform.sms'), ['sms-gateway', 'sms-sections']],
            'platform.announcements' => [route('platform.announcements'), ['an-list', 'an-new']],
            'platform.usage' => [route('platform.usage'), ['cu-period', 'cu-totals', 'cu-clinics']],
            'platform.imports-other' => [route('platform.imports.other'), ['xi-tabs', 'xi-upload', 'xi-list']],
            'platform.analytics' => [route('platform.subscription-analytics'), ['sa-filters', 'sa-export']],
            'platform.support' => [route('platform.support'), ['sp-form', 'sp-preview']],
        ];

        $this->actingAs($this->admin);
        foreach ($pages as $key => [$url, $markers]) {
            $tour = TourCatalog::tour($key);
            $this->assertNotNull($tour, "$key has a tour");
            $targets = array_filter(array_column($tour['steps'], 'target'));
            $this->assertSame([], array_values(array_diff($markers, $targets)), "$key tour covers its markers");

            $page = $this->get($url)->assertOk()->assertSeeHtml('data-tour="tour-replay"');
            foreach ($markers as $marker) $page->assertSeeHtml('data-tour="' . $marker . '"');
            $page->assertSee($tour['steps'][0]['title']);
        }
    }

    public function test_finishing_a_platform_tour_is_remembered_outside_any_clinic(): void
    {
        $this->actingAs($this->admin)->withSession(['workspace_mode' => 'platform'])
            ->post(route('guides.record', 'platform.quotes'), ['status' => 'done'])->assertNoContent();

        $this->assertTrue(Guides::seen('platform.quotes', 1, $this->admin));
        $this->assertDatabaseHas('user_guides', ['user_id' => $this->admin->id, 'clinic_id' => 0, 'guide' => 'platform.quotes']);
    }

    public function test_clinic_staff_never_get_platform_tours(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertNull(TourCatalog::tour('platform.dashboard'));
    }
}
