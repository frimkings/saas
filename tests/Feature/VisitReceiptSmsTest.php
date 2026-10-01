<?php

namespace Tests\Feature;

use App\Livewire\POSComponent;
use App\Models\{Category, Clinic, ClinicSubscription, Patient, PatientVisit, PaymentTransaction, Product, Sales, Setting, SmsLog, SmsTemplate, SubscriptionPlan, User};
use App\Services\Messaging\{SmsCreditService, SmsCredentials, SmsDriver};
use App\Services\Visits\{PatientVisits, VisitReceiptSms};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** One SMS per visit at the clinic's closing time, instead of one per payment. */
class VisitReceiptSmsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Patient $patient;
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true, 'services.eazisms' => ['url' => 'https://platform.test/sms', 'key' => 'key', 'default_sender' => 'EYEPLATFORM']]);
        $test = $this;
        $this->app->instance(SmsDriver::class, new class($test) implements SmsDriver {
            public function __construct(private $test) {}
            public function send(SmsCredentials $credentials, string $to, string $message): array { $this->test->record($message); return ['success' => true, 'message_id' => 'msg']; }
            public function balance(SmsCredentials $credentials): array { return ['success' => true, 'response' => ['balance' => 99]]; }
        });

        $this->travelTo(Carbon::parse('2026-10-01 10:00:00', 'UTC'));
        $this->user = User::factory()->create();
        $this->user->assignRole(Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']));
        $clinic = Clinic::create(['name' => 'Visit Clinic', 'slug' => 'visit-clinic', 'deployment_mode' => 'hosted', 'default_timezone' => 'Africa/Accra']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Plan', 'code' => 'plan', 'included_branches' => 1, 'included_users' => 5,
            'storage_limit_mb' => 100, 'features' => ['*'], 'base_price' => 10, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        app(TenantContext::class)->set($this->user, $clinic, $branch, [$branch->id]);
        $this->actingAs($this->user);
        Setting::getSettings()->update(['sms_enabled' => true, 'visit_receipts_enabled' => true]);
        app(SmsCreditService::class)->add($clinic->id, 100, 'grant', ['note' => 'Test credits']);
        SmsTemplate::ensureDefaults();
        SmsTemplate::whereIn('key', ['visit_receipt', 'visit_part_payment', 'payment_receipt'])->update(['is_enabled' => true]);

        $this->patient = Patient::createWithGeneratedPxNumber(['user_id' => $this->user->id, 'name' => 'Ama', 'contact' => '0241112222', 'gender' => 'Female']);
    }

    public function record(string $message): void
    {
        $this->sent[] = $message;
    }

    public function test_nothing_goes_before_closing_time_then_one_receipt_at_five(): void
    {
        $visit = $this->visitWithSale(250, 250);

        $this->runAt('2026-10-01 16:00:00');
        $this->assertSame(0, $this->texts('visit_receipt'));

        $this->runAt('2026-10-01 17:00:00');
        $this->runAt('2026-10-01 18:00:00');
        $this->assertSame(1, $this->texts('visit_receipt'));
        $this->assertStringContainsString('250.00', end($this->sent));
        $this->assertStringContainsString($visit->visit_number, end($this->sent));
    }

    public function test_part_paid_visit_gets_amount_paid_and_balance_then_final_when_settled(): void
    {
        $visit = $this->visitWithSale(500, 200);

        $this->runAt('2026-10-01 17:00:00');
        $this->assertSame(1, $this->texts('visit_part_payment'));
        $this->assertStringContainsString('GHS 200.00 from you today', end($this->sent));
        $this->assertStringContainsString('Balance due: GHS 300.00', end($this->sent));

        // A week later the balance is paid: final receipt SMS that evening.
        $this->travelTo(Carbon::parse('2026-10-08 11:00:00'));
        $sale = $visit->sales()->first();
        PaymentTransaction::create(['sale_id' => $sale->id, 'amount' => 300, 'payment_method' => 'cash', 'collected_by' => $this->user->id]);
        $sale->update(['amount_paid' => 500, 'payment_status' => 'paid']);

        $this->runAt('2026-10-08 17:05:00');
        $this->assertSame(1, $this->texts('visit_receipt'));
        $this->assertStringContainsString('GHS 500.00 in full', end($this->sent));
    }

    public function test_a_sleeping_server_sends_yesterdays_texts_the_next_morning(): void
    {
        $this->visitWithSale(250, 250);

        $this->runAt('2026-10-01 22:00:00');   // after sending hours
        $this->assertSame(0, $this->texts('visit_receipt'));

        $this->runAt('2026-10-02 08:00:00');
        $this->assertSame(1, $this->texts('visit_receipt'));
    }

    public function test_the_clinic_closing_time_is_used(): void
    {
        Setting::getSettings()->update(['closing_time' => '19:30']);
        $this->visitWithSale(250, 250);

        $this->runAt('2026-10-01 19:00:00');
        $this->assertSame(0, $this->texts('visit_receipt'));
        $this->runAt('2026-10-01 19:30:00');
        $this->assertSame(1, $this->texts('visit_receipt'));
    }

    public function test_nothing_is_sent_when_the_setting_or_message_is_off(): void
    {
        $this->visitWithSale(250, 250);
        Setting::getSettings()->update(['visit_receipts_enabled' => false]);
        $this->runAt('2026-10-01 17:00:00');
        $this->assertSame(0, SmsLog::count());

        Setting::getSettings()->update(['visit_receipts_enabled' => true]);
        SmsTemplate::where('key', 'visit_receipt')->update(['is_enabled' => false]);
        $this->runAt('2026-10-01 18:00:00');
        $this->assertSame(0, SmsLog::count());
    }

    public function test_pos_payment_sms_is_replaced_by_the_visit_sms(): void
    {
        $category = Category::create(['user_id' => $this->user->id, 'name' => 'Drugs', 'type' => 'drug']);
        $drops = Product::factory()->create(['user_id' => $this->user->id, 'category_id' => $category->id, 'selling_price' => 50, 'cost_price' => 10, 'quantity' => 10]);
        $checkout = fn () => Livewire::test(POSComponent::class)
            ->call('selectPatient', $this->patient->id)
            ->set('cart', [$drops->id => ['product_id' => $drops->id, 'quantity' => 1]])
            ->set('payments', [['method' => 'cash', 'amount' => 50]])
            ->call('checkout');

        $checkout();
        $this->assertSame(0, $this->texts('payment_receipt'));

        Setting::getSettings()->update(['visit_receipts_enabled' => false]);
        $checkout();
        $this->assertSame(1, $this->texts('payment_receipt'));
    }

    private function visitWithSale(float $total, float $paid): PatientVisit
    {
        $sale = Sales::create(['user_id' => $this->user->id, 'patient_id' => $this->patient->id, 'business_line' => 'clinic',
            'transaction_id' => 'TX-' . uniqid(), 'total_amount' => $total, 'amount_paid' => $paid,
            'payment_status' => $paid >= $total ? 'paid' : 'partial']);
        PaymentTransaction::create(['sale_id' => $sale->id, 'amount' => $paid, 'payment_method' => 'cash', 'collected_by' => $this->user->id]);

        return app(PatientVisits::class)->attach($sale);
    }

    private function runAt(string $accraTime): void
    {
        $this->travelTo(Carbon::parse($accraTime, 'Africa/Accra'));
        app(VisitReceiptSms::class)->sendDue();
    }

    private function texts(string $key): int
    {
        return SmsLog::where('template_key', $key)->count();
    }
}
