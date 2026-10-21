<?php

namespace Tests\Feature;

use App\Livewire\Platform\PricingRequestsComponent;
use App\Mail\OwnerNoticeMail;
use App\Models\PlatformSetting;
use App\Models\PricingRequest;
use App\Models\User;
use App\Services\PricingCatalogue;
use App\Support\Feature;
use App\Support\Pricing\AttendanceSuitePrices as Prices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** The Attendance Suite on the public pricing page: priced by staff, packages or modules, quotes from 600 staff. */
class AttendancePricingTest extends TestCase
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
        return $overrides + ['clinic_name' => 'Korle Care Hospital', 'contact_name' => 'Kofi Boateng', 'phone' => '0244000222', 'email' => 'kofi@korle.test',
            'town' => 'Accra', 'plan' => 'attendance:standard', 'interval' => 'monthly', 'staff' => 80];
    }

    public function test_the_price_follows_the_staff_size_and_the_package(): void
    {
        // 40 staff is the 50 size; packages add a fixed amount to Basic at any size.
        $this->assertSame([50, 150.0], [Prices::quote(40)['size'], Prices::quote(40)['monthly']]);
        $this->assertEquals([270, 370, 470], [Prices::quote(40, [], 'standard')['monthly'], Prices::quote(40, [], 'professional')['monthly'], Prices::quote(40, [], 'complete')['monthly']]);

        // 360 staff is the 400 size; yearly is twelve months, no discount.
        $pro = Prices::quote(360, [], 'professional');
        $this->assertSame(['Attendance Professional · up to 400 staff', 570.0, 6840.0], [$pro['name'], $pro['monthly'], $pro['yearly']]);

        // 551 to 599 staff is the last listed size; from 600 there is no list price.
        $this->assertEquals(430, Prices::quote(599)['monthly']);
        $special = Prices::quote(600, [], 'complete');
        $this->assertTrue($special['special']);
        $this->assertSame(['Attendance Complete · 600+ staff', 0.0, null], [$special['name'], $special['monthly'], $special['size']]);
    }

    public function test_ticked_modules_cost_basic_plus_each_unless_a_package_is_no_dearer(): void
    {
        // Shifts and payroll: no package has both for less, so Basic + 80 + 60.
        $custom = Prices::quote(30, [Feature::ATTENDANCE_SHIFTS, Feature::ATTENDANCE_PAYROLL]);
        $this->assertNull($custom['package']);
        $this->assertSame([['Shifts & roster', 80.0], ['Payroll', 60.0]], $custom['lines']);
        $this->assertEquals(290, $custom['monthly']);

        // Shifts, overtime and leave one by one is 160 on top of Basic; Standard is 120, so Standard wins.
        $standard = Prices::quote(100, [Feature::ATTENDANCE_SHIFTS, Feature::ATTENDANCE_OVERTIME, Feature::ATTENDANCE_LEAVE]);
        $this->assertSame(['standard', 310.0, 40.0], [$standard['package'], $standard['monthly'], $standard['saving']]);

        // At the same price the package wins too, as it gives more.
        $this->assertSame('standard', Prices::quote(100, [Feature::ATTENDANCE_SHIFTS, Feature::ATTENDANCE_OVERTIME])['package']);

        // Hospital tools alone stay a module: Complete costs far more.
        $this->assertEquals(270, Prices::quote(10, [Feature::ATTENDANCE_HOSPITAL])['monthly']);
    }

    public function test_the_page_shows_the_attendance_packages_beside_the_clinic_plans(): void
    {
        $this->get('/pricing')->assertOk()
            ->assertSeeInOrder(['Eye clinics', 'Staff attendance', 'Clinic Starter'])
            ->assertSeeInOrder(['How many staff will clock in?', 'Basic', 'GHS 150', 'Standard', 'GHS 270', 'Professional', 'GHS 370', 'Complete', 'GHS 470'])
            ->assertSee('Shifts &amp; roster', false)->assertSee('Special pricing')->assertSee('See attendance prices');
    }

    public function test_attendance_prices_show_even_before_any_clinic_plan_is_on_the_page(): void
    {
        PricingCatalogue::saveSettings(['plans' => []]);

        $this->get('/pricing')->assertOk()->assertSee('Prices are on their way')->assertSee('Request this package')->assertSee('GHS 470');
    }

    public function test_a_package_request_is_quoted_again_by_staff_and_emailed(): void
    {
        Mail::fake();

        $this->post('/pricing/request', $this->request(['plan' => 'attendance:professional', 'staff' => 360, 'interval' => 'yearly',
            'features' => [Feature::ATTENDANCE_HOSPITAL], 'users' => 3]))
            ->assertRedirect(route('pricing') . '#attendance')->assertSessionHas('pricing_sent');

        $request = PricingRequest::sole();
        $this->assertSame(['Attendance Professional · up to 400 staff', null, '570.00', '6840.00'],
            [$request->plan_name, $request->subscription_plan_id, $request->monthly_total, $request->quoted_total]);
        // A card is the package as it stands; boxes ticked elsewhere don't change it.
        $this->assertEquals(['product' => 'attendance', 'staff' => 360, 'size' => 400, 'package' => 'professional', 'features' => [], 'special' => false, 'lines' => []],
            $request->selection);
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->heading === 'Korle Care Hospital asked for Attendance Professional · up to 400 staff'
            && $mail->details['Price'] === 'GHS 6,840.00 a year' && $mail->details['Staff on attendance'] === '360');
    }

    public function test_a_built_request_keeps_its_modules(): void
    {
        $this->post('/pricing/request', $this->request(['plan' => 'attendance', 'staff' => 30,
            'features' => [Feature::ATTENDANCE_SHIFTS, Feature::ATTENDANCE_PAYROLL, 'no_such_module']]))->assertSessionHasNoErrors();

        $request = PricingRequest::sole();
        $this->assertSame(['Attendance Basic · up to 50 staff', '290.00'], [$request->plan_name, $request->monthly_total]);
        $this->assertSame([Feature::ATTENDANCE_SHIFTS, Feature::ATTENDANCE_PAYROLL], $request->selection['features']);
    }

    public function test_from_600_staff_the_request_asks_for_a_quote(): void
    {
        Mail::fake();
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->post('/pricing/request', $this->request(['plan' => 'attendance:complete', 'staff' => 650]))->assertSessionHas('pricing_sent',
            "Thank you, Kofi Boateng. We've received a quote for 650 staff and will call you on 0244000222 shortly.");

        $request = PricingRequest::sole();
        $this->assertSame(['Attendance Complete · 600+ staff', '0.00', true], [$request->plan_name, $request->monthly_total, $request->selection['special']]);
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->details['Price'] === 'Special pricing: agree a quote with them');
        $this->actingAs($admin)->get(route('platform.pricing'))->assertOk()->assertSee('Special pricing: quote to agree');
    }

    public function test_an_attendance_request_needs_a_staff_number_and_a_real_package(): void
    {
        $this->from('/pricing')->post('/pricing/request', $this->request(['staff' => null]))->assertSessionHasErrors(['staff' => 'Enter how many staff you have.']);
        $this->from('/pricing')->post('/pricing/request', $this->request(['plan' => 'attendance:gold']))->assertSessionHasErrors('plan');

        $this->assertSame(0, PricingRequest::count());
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    public function test_standard_is_marked_most_popular_until_the_platform_says_otherwise(): void
    {
        $this->get('/pricing')->assertSeeInOrder(['Basic', 'Most popular', 'Standard', 'Professional']);
        $this->assertSame('standard', collect(Prices::packages())->search(fn ($p) => $p['featured']));
    }

    public function test_the_platform_edits_the_attendance_prices_and_the_page_follows(): void
    {
        $admin = $this->platformAdmin();

        Livewire::actingAs($admin)->test(PricingRequestsComponent::class)->call('setTab', 'attendance')
            ->assertSet('att.special_from', '600')->assertSet('att.sizes.0', ['up_to' => '50', 'price' => '150'])
            // 1 to 30 staff now costs 120; 31 to 499 costs 300; special pricing from 500.
            ->set('att.sizes', [['up_to' => '30', 'price' => '120'], ['up_to' => '499', 'price' => '300']])->set('att.special_from', '500')
            ->set('att.packages.standard.uplift', '100')->set('att.modules.attendance_payroll', '75')
            ->set('att.taglines.complete', 'For hospitals')->set('att.featured', 'professional')
            ->call('saveAttendance')->assertHasNoErrors();

        $this->assertEquals([30 => 120, 499 => 300], Prices::sizes());
        $this->assertSame([500, 220.0, 'professional'], [Prices::specialFrom(), Prices::quote(30, [], 'standard')['monthly'], collect(Prices::packages())->search(fn ($p) => $p['featured'])]);
        $this->assertEquals(375, Prices::quote(31, [Feature::ATTENDANCE_PAYROLL])['monthly']);
        $this->assertTrue(Prices::quote(500)['special']);
        $this->get('/pricing')->assertSee('For hospitals')->assertSee('GHS 120')->assertSeeInOrder(['Standard', 'Most popular', 'Professional']);

        // The clinic plans' settings are kept, and saving those keeps the attendance prices.
        $this->assertNotEmpty(PricingCatalogue::settings()['plans']);
        Livewire::actingAs($admin)->test(PricingRequestsComponent::class)->call('setTab', 'page')->call('savePage');
        $this->assertSame(500, Prices::specialFrom());

        Livewire::actingAs($admin)->test(PricingRequestsComponent::class)->call('resetAttendance');
        $this->assertSame([600, 150.0], [Prices::specialFrom(), Prices::quote(10)['monthly']]);
        $this->assertNotEmpty(PricingCatalogue::settings()['plans']);
    }

    public function test_every_staff_number_below_special_pricing_needs_a_price(): void
    {
        $component = Livewire::actingAs($this->platformAdmin())->test(PricingRequestsComponent::class)->call('setTab', 'attendance');

        $component->set('att.sizes', [['up_to' => '50', 'price' => '150'], ['up_to' => '400', 'price' => '350']])->call('saveAttendance')
            ->assertHasErrors(['att.sizes' => 'The largest size must be up to 599 staff, one below special pricing, so every number of staff has a price.']);
        $component->set('att.sizes', [['up_to' => '599', 'price' => '150'], ['up_to' => '599', 'price' => '350']])->call('saveAttendance')
            ->assertHasErrors(['att.sizes' => 'Two sizes have the same number of staff.']);
        $component->set('att.sizes', [['up_to' => '599', 'price' => '']])->call('saveAttendance')->assertHasErrors(['att.sizes.0.price']);

        $this->assertSame([], Prices::saved());
    }
}
