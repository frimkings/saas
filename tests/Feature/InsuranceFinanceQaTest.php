<?php

namespace Tests\Feature;

use App\Livewire\Admin\InsuranceClaimsComponent;
use App\Livewire\Admin\InsurersComponent;
use App\Livewire\Cashier\SalesRecordsComponent;
use App\Livewire\OutstandingBalancesComponent;
use App\Livewire\ReportsComponent;
use App\Models\{Category, DayClose, InsuranceClaim, Insurer, Patient, Product, RefundLog, SaleItem, Sales};
use App\Services\Finance\RefundProcessor;
use App\Services\Insurance\ClaimSettlement;
use App\Services\Insurance\InsuranceBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** The insurance walk-through (2026-10-25): claims, what insurers refuse, and how it reaches the reports. */
class InsuranceFinanceQaTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    private Insurer $insurer;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerOn('clinic-pro');
        $this->insurer = Insurer::create(['name' => 'Acacia Health', 'scheme_type' => 'Private', 'active' => true, 'shortfall_action' => 'bill_patient']);
        $this->patient = Patient::factory()->create(['user_id' => auth()->id(), 'insurer_id' => $this->insurer->id]);
    }

    /** A frame sold and handed over: 400, of which the insurer was billed 200 and the patient paid 200. */
    private function frameSale(float $insurerShare = 200): Sales
    {
        $category = Category::firstOrCreate(['name' => 'Frames'], ['type' => 'frame', 'user_id' => auth()->id()]);
        $frame = Product::create(['user_id' => auth()->id(), 'name' => 'Ray-Ban Frame', 'category_id' => $category->id,
            'batch_number' => 'RB-' . random_int(1000, 9999), 'manufacture_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->addYears(3)->toDateString(), 'quantity' => 10, 'cost_price' => 150, 'selling_price' => 400]);
        $sale = Sales::create(['user_id' => auth()->id(), 'patient_id' => $this->patient->id, 'insurer_id' => $this->insurer->id,
            'transaction_id' => 'TXN-' . strtoupper(bin2hex(random_bytes(4))), 'total_amount' => 400, 'insurer_amount' => $insurerShare,
            'amount_paid' => 400 - $insurerShare, 'payment_status' => 'paid', 'profit' => 250]);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $frame->id, 'prescribed_quantity' => 0, 'dispensed_quantity' => 1,
            'selling_price' => 400, 'unit_cost' => 150, 'subtotal' => 400, 'insurer_amount' => $insurerShare, 'notes' => 'Direct POS Sale']);
        $sale->paymentTransactions()->create(['amount' => 400 - $insurerShare, 'payment_method' => 'cash', 'collected_by' => auth()->id()]);
        app(InsuranceBilling::class)->syncDraftClaim($sale);

        return $sale->fresh();
    }

    private function claimFor(Sales $sale): InsuranceClaim
    {
        return InsuranceClaim::where('sale_id', $sale->id)->sole();
    }

    private function moveClaim(InsuranceClaim $claim, string $status, array $values = [])
    {
        $page = Livewire::test(InsuranceClaimsComponent::class)->call('openStatusModal', $claim->id, $status);
        foreach ($values as $key => $value) {
            $page->set($key, $value);
        }

        return $page->call('applyStatus');
    }

    public function test_collecting_what_the_insurer_refused_keeps_the_item_sold_and_it_can_still_be_refunded(): void
    {
        $sale = $this->frameSale();
        $claim = $this->claimFor($sale);
        $this->moveClaim($claim, 'submitted');
        $this->moveClaim($claim, 'rejected', ['statusState.rejection_reason' => 'Frame not covered']);

        $sale->refresh();
        $this->assertSame('partial', $sale->payment_status);
        $this->assertSame(0.0, (float) $sale->items->first()->insurer_amount);

        // The report counts the bill once, at what was charged, and shows where the insurer's part went.
        $insurance = Livewire::test(ReportsComponent::class)->viewData('insurance');
        $this->assertEquals(200, $insurance['billed']);
        $this->assertEquals(200, $insurance['toPatients']);
        $this->assertEquals(200, $insurance['patient']);
        Livewire::test(SalesRecordsComponent::class)->assertSee('Part paid');

        Livewire::test(OutstandingBalancesComponent::class)->call('openCollect', $sale->id)
            ->assertSee('Items (already handed over)')
            ->set('collectAmount', 200)->set('paymentMethod', 'cash')->call('collectPayment')->assertHasNoErrors();

        $sale->refresh();
        $item = $sale->items->first();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(1, (int) $item->dispensed_quantity);
        $this->assertSame(400.0, (float) $item->subtotal);
        $this->assertSame(250.0, (float) $sale->profit);

        $log = RefundLog::create(['sale_id' => $sale->id, 'sale_item_ids' => [$item->id], 'request_type' => RefundLog::TYPE_REFUND,
            'reason_code' => 'other', 'reason' => 'Returned', 'status' => RefundLog::STATUS_APPROVED,
            'initiated_by' => auth()->id(), 'approved_by' => auth()->id()]);
        $this->assertSame(400.0, (float) app(RefundProcessor::class)->process($log)->refunded_amount);
    }

    public function test_a_claim_asks_no_more_than_the_insurers_share_and_approved_means_all_of_it(): void
    {
        $sale = $this->frameSale();
        $claim = $this->claimFor($sale);

        Livewire::test(InsuranceClaimsComponent::class)->call('openEdit', $claim->id)
            ->set('state.claim_amount', 350)->call('save')->assertHasErrors('state.claim_amount');
        $this->assertSame(200.0, (float) $claim->fresh()->claim_amount);

        $this->moveClaim($claim, 'submitted');
        $this->moveClaim($claim, 'partially_approved', ['statusState.approved_amount' => 200])->assertHasErrors('statusState.approved_amount');
        $this->moveClaim($claim, 'approved', ['statusState.approved_amount' => 999])->assertHasNoErrors();
        $this->assertSame(200.0, (float) $claim->fresh()->approved_amount);
    }

    public function test_an_insurer_is_not_paid_more_than_its_share_of_the_bill(): void
    {
        $sale = $this->frameSale(80);
        // A claim raised above the share before claims were capped.
        $claim = tap($this->claimFor($sale))->update(['claim_amount' => 150, 'approved_amount' => 150, 'status' => 'approved']);

        $this->expectException(ValidationException::class);
        app(ClaimSettlement::class)->recordPayment($this->insurer,
            ['amount' => 150, 'payment_method' => 'bank_transfer', 'paid_on' => today()->toDateString()],
            [$claim->id => ['amount' => 150]]);
    }

    public function test_an_insurer_payment_is_not_dated_into_a_closed_day(): void
    {
        $sale = $this->frameSale();
        $claim = tap($this->claimFor($sale))->update(['status' => 'submitted', 'submission_date' => today()]);
        DayClose::create(['business_date' => today(), 'status' => DayClose::CLOSED, 'methods' => [], 'cashiers' => [],
            'closed_by' => auth()->id(), 'closed_at' => now()]);

        try {
            app(ClaimSettlement::class)->recordPayment($this->insurer,
                ['amount' => 200, 'payment_method' => 'bank_transfer', 'paid_on' => today()->toDateString()],
                [$claim->id => ['amount' => 200]]);
            $this->fail('A payment on a closed day was recorded.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment.paid_on', $e->errors());
        }
    }

    public function test_write_offs_count_as_a_cost_and_the_report_agrees_with_its_item_tab(): void
    {
        $this->insurer->update(['shortfall_action' => 'write_off']);
        $sale = $this->frameSale();
        $claim = $this->claimFor($sale);
        $this->moveClaim($claim, 'submitted');
        $this->moveClaim($claim, 'partially_approved', ['statusState.approved_amount' => 150]);

        $this->assertSame(350.0, (float) $sale->fresh()->total_amount);
        $this->assertSame(150.0, (float) $sale->fresh()->items->first()->insurer_amount);

        $report = Livewire::test(ReportsComponent::class);
        $this->assertEquals(400, $report->viewData('summary')['total_sales']);
        $this->assertEquals(200, $report->viewData('insurance')['billed']);
        $this->assertEquals(50, $report->viewData('insurance')['writtenOff']);
        $this->assertEquals(400, $report->set('analyticsView', 'items')->viewData('salesByItems')->sum('revenue'));
    }

    public function test_two_insurers_cannot_share_a_name(): void
    {
        Livewire::test(InsurersComponent::class)->call('openCreate')
            ->set('state.name', 'acacia health')->set('state.scheme_type', 'Private')->call('save')
            ->assertHasErrors('state.name');
        $this->assertSame(1, Insurer::count());
    }
}
