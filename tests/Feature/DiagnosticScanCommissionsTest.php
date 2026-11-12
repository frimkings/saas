<?php

namespace Tests\Feature;

use App\Livewire\Scans\DiagnosticScansComponent;
use App\Livewire\Scans\ScanCommissionsComponent;
use App\Models\{Clinic, ClinicSubscription, DiagnosticScan, Expense, Patient, RefundLog, Sales, ScanCommission, ScanCommissionPayout, ScanOrder, ScanPartnerClinic, ScanPartnerRate, SubscriptionPlan, User};
use App\Services\Finance\RefundProcessor;
use App\Services\Scans\ScanCommissionService;
use App\Services\Scans\ScanOrderService;
use App\Support\Feature;
use App\Support\Guides\TourCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Diagnostic scans, phase 3: referrers earn on fully paid scans, lose it on refunds, and are paid by voucher. */
class DiagnosticScanCommissionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Clinic $clinic;
    private $branch;
    private DiagnosticScan $oct;
    private DiagnosticScan $vft;
    private ScanPartnerClinic $partner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->user = User::factory()->create();
        $this->clinic = Clinic::create(['name' => 'Scan Centre', 'slug' => 'scan-centre', 'deployment_mode' => 'hosted']);
        $this->branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $this->clinic->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $this->branch->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $features = ['clinical', Feature::DIAGNOSTIC_SCANS];
        $plan = SubscriptionPlan::create(['name' => 'Scan plan', 'code' => 'scan-plan', 'included_branches' => 1, 'included_users' => 5,
            'storage_limit_mb' => 100, 'features' => $features, 'base_price' => 10, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth(), 'feature_snapshot' => $features]);
        $this->actAs('Manager');

        $this->oct = DiagnosticScan::create(['name' => 'OCT Macula', 'price' => 150, 'per_eye' => true, 'commission_type' => 'percent', 'commission_value' => 10]);
        $this->vft = DiagnosticScan::create(['name' => 'Visual Field', 'price' => 200, 'commission_type' => 'fixed', 'commission_value' => 20]);
        $this->partner = ScanPartnerClinic::create(['code' => 'RC-001', 'name' => 'Hilltop Eye Clinic']);
    }

    private function actAs(string $roleName): void
    {
        app(TenantContext::class)->set($this->user, $this->clinic, $this->branch, [$this->branch->id]);
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $this->user->syncRoles([$role]);
        \Illuminate\Support\Facades\DB::table('branch_user_role')->where('user_id', $this->user->id)->delete();
        \Illuminate\Support\Facades\DB::table('branch_user_role')->insert(['branch_id' => $this->branch->id, 'user_id' => $this->user->id, 'role_id' => $role->id]);
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($this->user, $this->branch);
        $this->actingAs($this->user);
    }

    private function patient(string $name = 'Kofi Scan'): Patient
    {
        return Patient::createWithGeneratedPxNumber(['user_id' => $this->user->id, 'name' => $name, 'contact' => '0241234567', 'gender' => 'Male']);
    }

    /** A partner-referred order for OCT both eyes (300) and VFT (200), billed with this payment. */
    private function referredOrder(float $paid = 500, array $extra = []): ScanOrder
    {
        $order = app(ScanOrderService::class)->create($extra + ['patient_id' => $this->patient()->id, 'source' => 'partner', 'scan_partner_clinic_id' => $this->partner->id,
            'items' => [['diagnostic_scan_id' => $this->oct->id, 'eye' => 'OU'], ['diagnostic_scan_id' => $this->vft->id]]]);
        app(ScanOrderService::class)->checkout($order, [['method' => 'cash', 'amount' => $paid]]);

        return $order->fresh(['items', 'sale.items']);
    }

    private function owed(string $type = 'partner', ?int $id = null): float
    {
        return round((float) ScanCommission::for($type, $id ?? $this->partner->id)->where('status', 'accrued')->sum('amount'), 2);
    }

    public function test_a_fully_paid_referred_bill_earns_the_catalogue_commission(): void
    {
        $this->referredOrder();

        // OCT: 10% of 300 = 30; VFT: fixed 20 per scan.
        $rows = ScanCommission::orderBy('id')->get();
        $this->assertSame([30.0, 20.0], $rows->map(fn ($r) => (float) $r->amount)->all());
        $this->assertSame(['percent', 'fixed'], $rows->pluck('rate_type')->all());
        $this->assertTrue($rows->every(fn ($r) => $r->payee_type === 'partner' && $r->scan_partner_clinic_id === $this->partner->id && $r->status === 'accrued'));
        $this->assertSame(50.0, $this->owed());

        // Saving the sale again changes nothing.
        $sale = Sales::findOrFail(ScanOrder::firstOrFail()->sale_id);
        app(ScanCommissionService::class)->syncForSale($sale);
        $this->assertSame(2, ScanCommission::count());
    }

    public function test_an_agreed_partner_rate_wins_and_is_frozen_on_the_scan(): void
    {
        ScanPartnerRate::create(['scan_partner_clinic_id' => $this->partner->id, 'diagnostic_scan_id' => $this->oct->id, 'commission_type' => 'percent', 'commission_value' => 15]);
        $order = $this->referredOrder();
        $this->assertSame(65.0, $this->owed()); // 15% of 300 + 20

        // Changing rates later leaves earned commission alone.
        $this->oct->update(['commission_value' => 50]);
        ScanPartnerRate::query()->update(['commission_value' => 40]);
        app(ScanCommissionService::class)->syncForSale($order->sale);
        $this->assertSame(65.0, $this->owed());
    }

    public function test_part_paid_bills_earn_nothing_until_settled(): void
    {
        $order = $this->referredOrder(300);
        $this->assertSame(0, ScanCommission::count());

        // The balance is collected later (as Outstanding Balances does): now it earns.
        $order->sale->update(['amount_paid' => 500, 'payment_status' => 'paid', 'bill_status' => 'finalized', 'finalized_at' => now()]);
        $this->assertSame(50.0, $this->owed());
    }

    public function test_self_referred_scans_earn_no_one_and_our_doctors_earn_the_catalogue_rate(): void
    {
        $self = app(ScanOrderService::class)->create(['patient_id' => $this->patient()->id, 'source' => 'self', 'items' => [['diagnostic_scan_id' => $this->vft->id]]]);
        app(ScanOrderService::class)->checkout($self, [['method' => 'cash', 'amount' => 200]]);
        $this->assertSame(0, ScanCommission::count());

        $doctor = User::factory()->create(['name' => 'Dr Owusu']);
        ScanPartnerRate::create(['scan_partner_clinic_id' => $this->partner->id, 'diagnostic_scan_id' => $this->oct->id, 'commission_type' => 'percent', 'commission_value' => 50]);
        $internal = app(ScanOrderService::class)->create(['patient_id' => $this->patient()->id, 'source' => 'internal', 'ordered_by' => $doctor->id,
            'items' => [['diagnostic_scan_id' => $this->oct->id, 'eye' => 'OD']]]);
        app(ScanOrderService::class)->checkout($internal, [['method' => 'cash', 'amount' => 150]]);
        $this->assertSame(15.0, $this->owed('staff', $doctor->id)); // catalogue 10%, not a partner's rate
    }

    public function test_refunds_void_unpaid_commission_and_correct_paid_commission(): void
    {
        // Refund before payout: the commission is voided.
        $first = $this->referredOrder();
        $this->refund($first->sale, [$first->items[1]->sale_item_id]); // the VFT
        $this->assertSame(30.0, $this->owed());
        $this->assertSame(1, ScanCommission::where('status', 'void')->count());

        // Pay it out, then refund the OCT: a negative correction waits for the next payout.
        app(ScanCommissionService::class)->payOut('partner', $this->partner->id, now(), 'cash', null, null);
        $this->assertSame(0.0, $this->owed());
        $this->refund($first->sale->fresh(), [$first->items[0]->sale_item_id]);
        $correction = ScanCommission::where('kind', 'correction')->firstOrFail();
        $this->assertSame(-30.0, (float) $correction->amount);
        $this->assertSame('paid', ScanCommission::where('kind', 'earned')->where('scan_order_item_id', $first->items[0]->id)->value('status'));

        // The next referral nets it off; nothing can be paid while the corrections outweigh it.
        $this->expectException(ValidationException::class);
        app(ScanCommissionService::class)->payOut('partner', $this->partner->id, now(), 'cash', null, null);
    }

    public function test_the_next_payout_nets_off_corrections(): void
    {
        $first = $this->referredOrder();
        app(ScanCommissionService::class)->payOut('partner', $this->partner->id, now(), 'cash', null, null);
        $this->refund($first->sale, $first->sale->items->pluck('id')->all()); // whole bill: -50
        $this->referredOrder(); // +50 again, and another +50 below
        $this->referredOrder();
        $this->assertSame(50.0, $this->owed());

        $payout = app(ScanCommissionService::class)->payOut('partner', $this->partner->id, now(), 'momo', 'MM123', null);
        $this->assertEquals(50, $payout->amount);
        $this->assertSame(0, ScanCommission::where('status', 'accrued')->count());
    }

    private function refund(Sales $sale, array $itemIds): void
    {
        $log = RefundLog::create(['sale_id' => $sale->id, 'sale_item_ids' => $itemIds, 'status' => RefundLog::STATUS_APPROVED,
            'initiated_by' => auth()->id(), 'approved_by' => auth()->id(), 'approved_at' => now(), 'reason' => 'Scan not done']);
        app(RefundProcessor::class)->process($log);
    }

    public function test_paying_out_records_a_voucher_and_an_expense(): void
    {
        $this->referredOrder();
        $this->travel(1)->days();
        $this->referredOrder();

        // Pay only what was earned yesterday.
        Livewire::test(ScanCommissionsComponent::class)
            ->assertSee('Hilltop Eye Clinic')->assertSee('100.00')
            ->call('openPayee', 'partner', $this->partner->id)->assertSee('OCT Macula')
            ->set('upTo', now()->subDay()->toDateString())->assertSee('Pay '.currency().' 50.00')
            ->set('payMethod', 'momo')->set('payReference', 'MM-778')->call('pay')->assertHasNoErrors()
            ->assertSee('COM-'.now()->format('Y').'-0001');

        $payout = ScanCommissionPayout::firstOrFail();
        $this->assertEquals(50, $payout->amount);
        $this->assertSame(now()->subDay()->toDateString(), $payout->period_to->toDateString());
        $this->assertSame(2, $payout->commissions()->count());
        $this->assertSame(50.0, $this->owed());

        $expense = Expense::findOrFail($payout->expense_id);
        $this->assertEquals(50, $expense->amount);
        $this->assertSame('momo', $expense->payment_method);
        $this->assertSame('Hilltop Eye Clinic', $expense->payee);
        $this->assertSame(ScanCommissionService::EXPENSE_CATEGORY, $expense->category->name);

        Livewire::test(ScanCommissionsComponent::class)->call('openPayee', 'partner', $this->partner->id)
            ->set('upTo', now()->addDay()->toDateString())->call('pay')->assertHasErrors('upTo');
    }

    public function test_only_managers_see_commissions(): void
    {
        Livewire::test(DiagnosticScansComponent::class)->assertSee('Commissions')->call('setTab', 'commissions')->assertSet('tab', 'commissions');

        $this->actAs('Secretary');
        Livewire::test(DiagnosticScansComponent::class)->assertDontSee('Commissions')->call('setTab', 'commissions')->assertStatus(422);
        Livewire::withQueryParams(['tab' => 'commissions'])->test(DiagnosticScansComponent::class)->assertSet('tab', 'desk');
        Livewire::test(ScanCommissionsComponent::class)->assertForbidden();
    }

    public function test_the_page_has_its_tour_and_the_parts_it_points_at(): void
    {
        foreach ([true, false] as $manager) {
            $tour = TourCatalog::tour('clinic.scans', ['manager' => $manager]);
            $this->assertNotNull($tour);
            $this->assertSame(1, $tour['version']);
            $this->assertSame($manager, collect($tour['steps'])->contains('target', 'scan-tab-commissions'));
        }
        Livewire::test(DiagnosticScansComponent::class)->assertSeeHtml('data-tour="tour-replay"')
            ->assertSeeHtml('data-tour="scan-tab-catalogue"')->assertSeeHtml('data-tour="scan-tab-partners"')->assertSeeHtml('data-tour="scan-tab-commissions"');
        Livewire::test(\App\Livewire\Scans\ScanDeskComponent::class)
            ->assertSeeHtml('data-tour="scan-desk-tiles"')->assertSeeHtml('data-tour="scan-new-order"')->assertSeeHtml('data-tour="scan-stages"');
    }
}
