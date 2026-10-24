<?php

namespace Tests\Feature;

use App\Livewire\Platform\PlatformDashboardComponent;
use App\Livewire\Platform\ProspectQuotesComponent;
use App\Mail\OwnerNoticeMail;
use App\Models\Clinic;
use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Models\PricingRequest;
use App\Models\ProspectQuote;
use App\Models\User;
use App\Services\Messaging\SmsCredentials;
use App\Services\Messaging\SmsDriver;
use App\Services\ProspectQuotes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class ProspectQuotesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    public array $texts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true, 'services.eazisms' => ['url' => 'https://platform.test/sms', 'key' => 'key', 'default_sender' => 'NEXUS']]);
        $this->artisan('pricing:clinical-suite')->assertSuccessful();
        $this->artisan('pricing:attendance-suite')->assertSuccessful();
        PlatformSetting::put(['support_email' => 'sales@platform.test', 'support_phone' => '0266457979', 'support_name' => 'Nexus App']);
        $test = $this;
        $this->app->instance(SmsDriver::class, new class($test) implements SmsDriver {
            public function __construct(private $test) {}
            public function send(SmsCredentials $credentials, string $to, string $message): array { $this->test->texts[] = [$to, $message]; return ['success' => true, 'message_id' => 'm1']; }
            public function balance(SmsCredentials $credentials): array { return ['success' => true]; }
        });
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_platform_admin' => true])->save();
    }

    private function requestFromPage(array $overrides = []): PricingRequest
    {
        Mail::fake();
        $this->post('/pricing/request', $overrides + ['clinic_name' => 'Bright Eyes', 'contact_name' => 'Ama Mensah', 'phone' => '0244000111',
            'email' => 'ama@brighteyes.test', 'town' => 'Kumasi', 'plan' => 'clinic-standard', 'interval' => 'monthly', 'features' => ['referral_letters'],
            'users' => 1, 'storage_mb' => 0, 'visits' => 0, 'branches' => 3])->assertRedirect();

        return PricingRequest::latest('id')->firstOrFail();
    }

    public function test_a_request_becomes_a_numbered_quote_with_the_same_total(): void
    {
        $request = $this->requestFromPage();

        $page = Livewire::withQueryParams(['request' => $request->id])->actingAs($this->admin)->test(ProspectQuotesComponent::class)
            ->assertSet('showEditor', true)->assertSet('form.business_name', 'Bright Eyes')->assertSet('form.subscription_plan_id', (string) $request->subscription_plan_id);
        $lines = $page->get('form.lines');
        $this->assertStringContainsString('Clinic Standard plan', $lines[0]['description']);
        $this->assertGreaterThan(1, count($lines), 'add-ons and extra branches come in as their own lines');
        $this->assertEqualsWithDelta((float) $request->quoted_total, array_sum(array_column($lines, 'amount')), 0.01);

        $page->call('save')->assertHasNoErrors();
        $quote = ProspectQuote::firstOrFail();
        $this->assertSame('QUO-' . now()->year . '-0001', $quote->number);
        $this->assertSame('draft', $quote->status);
        $this->assertEqualsWithDelta((float) $request->quoted_total, (float) $quote->total, 0.01);
        $this->assertSame($request->id, $quote->pricing_request_id);
        $this->assertSame(0, PlatformInvoice::count(), 'a quote is not an invoice');

        // A pro-forma has its own run of numbers; switching a quote's type re-numbers it.
        Livewire::actingAs($this->admin)->test(ProspectQuotesComponent::class)->call('newQuote')
            ->set('form.document_type', 'proforma')->set('form.business_name', 'Walk-in Optics')
            ->set('form.lines', [['description' => 'Optical plan (1 month)', 'amount' => '150']])->call('save')->assertHasNoErrors();
        $this->assertSame('PF-' . now()->year . '-0001', ProspectQuote::where('business_name', 'Walk-in Optics')->value('number'));
        Livewire::actingAs($this->admin)->test(ProspectQuotesComponent::class)->call('edit', $quote->id)->set('form.document_type', 'proforma')->call('save');
        $this->assertSame('PF-' . now()->year . '-0002', $quote->fresh()->number);
    }

    public function test_a_yearly_attendance_request_is_quoted_for_the_year(): void
    {
        $request = $this->requestFromPage(['plan' => 'attendance', 'interval' => 'yearly', 'staff' => 40, 'features' => []]);
        $draft = app(ProspectQuotes::class)->draftFromRequest($request);

        $this->assertSame('attendance', $draft['product']);
        $this->assertNotNull($draft['subscription_plan_id']);
        $this->assertStringContainsString('(12 months)', $draft['lines'][0]['description']);
        $this->assertEqualsWithDelta((float) $request->quoted_total, array_sum(array_column($draft['lines'], 'amount')), 0.01);
    }

    public function test_discount_and_tax_are_worked_out_and_checked(): void
    {
        $this->assertSame(['subtotal' => 300.0, 'tax' => 25.0, 'total' => 275.0],
            ProspectQuotes::totals([['amount' => 200], ['amount' => 100]], 50, 10));

        Livewire::actingAs($this->admin)->test(ProspectQuotesComponent::class)->call('newQuote')->set('form.business_name', 'Too Generous')
            ->set('form.lines', [['description' => 'Plan', 'amount' => '100']])->set('form.discount', '150')->call('save')->assertHasErrors('form.discount');
        $this->assertSame(0, ProspectQuote::count());
    }

    public function test_it_goes_out_by_email_and_sms_and_the_link_opens_without_signing_in(): void
    {
        $request = $this->requestFromPage();
        $quote = app(ProspectQuotes::class)->create(app(ProspectQuotes::class)->draftFromRequest($request) + ['document_type' => 'quotation', 'terms' => 'MoMo 0244 000 000'], $this->admin->id);
        Mail::fake();

        Livewire::actingAs($this->admin)->test(ProspectQuotesComponent::class)->call('sendEmail', $quote->id)->call('sendSms', $quote->id);

        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo('ama@brighteyes.test') && $mail->heading === "Quotation {$quote->number}"
            && count($mail->rawAttachments) === 1 && $mail->rawAttachments[0]['name'] === "{$quote->number}.pdf");
        $this->assertCount(1, $this->texts);
        $this->assertSame('0244000111', $this->texts[0][0]);
        $this->assertStringContainsString($quote->publicUrl(), $this->texts[0][1]);
        $this->assertSame('sent', $quote->fresh()->status);
        $this->assertSame('contacted', $request->fresh()->status);

        auth()->logout();
        $this->get($quote->publicUrl())->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/quote/' . str_repeat('a', 48))->assertNotFound();
        $this->get(route('platform.quote.pdf', $quote))->assertRedirect();
    }

    public function test_setting_up_the_clinic_from_a_quote_fills_in_onboarding_and_accepts_the_quote(): void
    {
        $request = $this->requestFromPage();
        $quote = app(ProspectQuotes::class)->create(app(ProspectQuotes::class)->draftFromRequest($request) + ['document_type' => 'proforma'], $this->admin->id);

        Livewire::withQueryParams(['tab' => 'onboarding', 'quote' => $quote->id])->actingAs($this->admin)->test(PlatformDashboardComponent::class)
            ->assertSet('showOnboarding', true)->assertSet('newClinicName', 'Bright Eyes')->assertSet('newAdminEmail', 'ama@brighteyes.test')
            ->assertSet('newPlanId', $request->subscription_plan_id)
            ->set('newBranchCode', 'MAIN')->set('newBranchName', 'Kumasi')->call('onboardClinic')->assertHasNoErrors();

        $clinic = Clinic::where('name', 'Bright Eyes')->firstOrFail();
        $quote->refresh();
        $this->assertSame('accepted', $quote->status);
        $this->assertSame($clinic->id, $quote->clinic_id);
        $this->assertSame('won', $request->fresh()->status);

        // An accepted quote stays as it was sent.
        Livewire::actingAs($this->admin)->test(ProspectQuotesComponent::class)->call('edit', $quote->id)->set('form.business_name', 'Changed')
            ->call('save')->assertHasErrors('form');
        $this->assertSame('Bright Eyes', $quote->fresh()->business_name);
    }

    public function test_only_the_platform_sees_quotes(): void
    {
        $quote = app(ProspectQuotes::class)->create(['document_type' => 'quotation', 'business_name' => 'X', 'lines' => [['description' => 'Plan', 'amount' => 10]]], $this->admin->id);
        $staff = User::factory()->create();

        $this->actingAs($staff)->get(route('platform.quotes'))->assertForbidden();
        $this->actingAs($staff)->get(route('platform.quote.pdf', $quote))->assertForbidden();
        $this->actingAs($this->admin)->get(route('platform.quotes'))->assertOk()->assertSee($quote->number);
    }
}
