<?php

namespace Tests\Feature;

use App\Livewire\Admin\AllApprovalsComponent;
use App\Livewire\Admin\CashBookComponent;
use App\Livewire\Admin\DayCloseComponent;
use App\Livewire\Admin\PaymentCorrectionApprovalsComponent;
use App\Livewire\Admin\RefundApprovalsComponent;
use App\Livewire\Cashier\SalesRecordsComponent;
use App\Models\CashMovement;
use App\Models\DayClose;
use App\Models\Expense;
use App\Models\PaymentCorrection;
use App\Models\PaymentTransaction;
use App\Models\RefundLog;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Models\User;
use App\Services\Finance\CashBook;
use App\Services\Finance\DayCloseService;
use App\Services\Finance\PaymentCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashBookAndDayCloseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->userWith('Super Admin', 'Owner Ama');
        $this->manager = $this->userWith('Manager', 'Manager Kojo');
        $this->cashier = $this->userWith('Cashier', 'Cashier Esi');
        $this->actingAs($this->owner);
    }

    private function userWith(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    private function sale(string $ref, float $amount, string $method = 'cash', ?User $by = null): Sales
    {
        $by ??= $this->cashier;
        $sale = Sales::create(['user_id' => $by->id, 'business_line' => 'clinic', 'transaction_id' => $ref,
            'total_amount' => $amount, 'amount_paid' => $amount, 'payment_status' => 'paid']);
        SaleItem::create(['sale_id' => $sale->id, 'prescribed_quantity' => 1, 'dispensed_quantity' => 1, 'selling_price' => $amount]);
        PaymentTransaction::create(['sale_id' => $sale->id, 'amount' => $amount, 'payment_method' => $method, 'collected_by' => $by->id]);

        return $sale;
    }

    private function startBook(float $cash = 200): void
    {
        Livewire::test(CashBookComponent::class)->set('startDate', today()->toDateString())
            ->set('openingCounts.cash', (string) $cash)->call('startBook')->assertHasNoErrors();
    }

    public function test_the_cash_book_runs_a_balance_from_payments_refunds_expenses_and_banking(): void
    {
        $this->startBook(200);
        $this->sale('TX-1', 100);
        $this->sale('TX-2', 50, 'momo');
        $refunded = $this->sale('TX-3', 60);
        RefundLog::create(['sale_id' => $refunded->id, 'request_type' => RefundLog::TYPE_REFUND, 'status' => RefundLog::STATUS_PROCESSED,
            'refund_number' => 'RF-9', 'refunded_amount' => 30, 'reason' => 'Returned', 'reason_code' => 'other', 'initiated_by' => $this->cashier->id,
            'processed_by' => $this->owner->id, 'processed_at' => now(),
            'original_payment_references' => [['payment_method' => 'cash', 'amount' => 60]]]);
        Expense::create(['expense_date' => today()->toDateString(), 'description' => 'Water', 'amount' => 20, 'payment_method' => 'cash', 'recorded_by' => $this->owner->id]);

        Livewire::test(CashBookComponent::class)
            ->set('moveType', CashMovement::BANKED)->set('moveAmount', '150')->set('moveReference', 'SLIP-1')->call('recordMovement')->assertHasNoErrors()
            ->assertSee('Payment')->assertSee('TX-1')->assertSee('Refund paid out — RF-9')->assertSee('Expense — Water')->assertSee('SLIP-1')
            ->assertViewHas('closing', 160.0) // 200 + 100 + 60 − 30 − 20 − 150
            ->call('selectMethod', 'momo')->assertViewHas('closing', 50.0);

        $book = app(CashBook::class);
        $this->assertSame(160.0, $book->balanceBefore('cash', today()->addDay()));
        $this->assertSame(200.0, $book->balanceBefore('cash', today()), 'The opening count is the start of the day.');

        // A mistake is cancelled, not deleted, and stays on the book.
        $banked = CashMovement::where('type', CashMovement::BANKED)->first();
        Livewire::test(CashBookComponent::class)->call('askCancel', $banked->id)->set('cancelReason', 'Wrong amount')->call('cancelMovement')->assertHasNoErrors()
            ->assertViewHas('closing', 310.0);
        $this->assertNotNull($banked->fresh()->cancelled_at);
        $this->expectException(\LogicException::class);
        $banked->fresh()->delete();
    }

    public function test_only_the_owner_starts_the_book_and_sets_the_float(): void
    {
        $this->actingAs($this->manager);
        Livewire::test(CashBookComponent::class)->assertSee('Ask the owner to enter the opening count')
            ->set('openingCounts.cash', '10')->call('startBook')->assertForbidden();
        Livewire::test(CashBookComponent::class)->set('floatAmount', '50')->call('saveFloat')->assertForbidden();
    }

    public function test_managers_open_the_cash_book(): void
    {
        $this->actingAs($this->manager)->get(route('admin.cash-book'))->assertOk()->assertSee('Start the cash book');
    }

    public function test_cashiers_close_the_day_but_do_not_open_the_cash_book(): void
    {
        $this->actingAs($this->cashier)->get(route('admin.day-close'))->assertOk()->assertSee('Day close')->assertSee('Day Close');
        $this->get(route('admin.cash-book'))->assertForbidden();
    }

    public function test_closing_the_day_records_the_count_difference_and_banking_and_settles_the_day(): void
    {
        $this->startBook(100);
        $this->sale('TX-A', 80);
        $this->sale('TX-B', 40, 'momo', $this->manager);

        $this->actingAs($this->cashier);
        $this->get(route('admin.day-close'))->assertOk()->assertSee('Count the till');
        Livewire::test(DayCloseComponent::class)
            ->call('close')->assertHasErrors('close') // the cash must be counted
            ->set('counted.cash', '175')->assertSee('Short '.currency().' 5.00')
            ->set('banked', '125')->set('notes', 'Five cedis missing')->call('close')->assertHasNoErrors()
            ->assertSee('Closed by Cashier Esi');

        $close = DayClose::first();
        $this->assertTrue($close->isClosed());
        $this->assertSame(180.0, (float) $close->methods['cash']['expected']);
        $this->assertSame(-5.0, (float) $close->methods['cash']['variance']);
        $this->assertSame(['Cashier Esi', 'Manager Kojo'], collect($close->cashiers)->pluck('name')->sort()->values()->all());
        $this->assertSame(50.0, app(CashBook::class)->balanceBefore('cash', today()->addDay()), '175 counted, 125 banked.');

        // The day is settled.
        $sale = Sales::where('transaction_id', 'TX-A')->first();
        $this->assertSame('Today has been closed, so its sales can no longer be voided. Use a refund, or ask the owner to reopen the day.', SalesRecordsComponent::voidProblem($sale));
        try {
            app(PaymentCorrectionService::class)->request($sale->paymentTransactions()->first(), 'momo', 'Wrong method');
            $this->fail('A closed day takes no corrections.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('has been closed', $e->getMessage());
        }
        Livewire::test(CashBookComponent::class)->set('moveAmount', '10')->call('recordMovement')->assertHasErrors('moveAmount');

        // Only the owner reopens it; that cancels what the close recorded.
        Livewire::test(DayCloseComponent::class)->set('reopenReason', 'Recount')->call('reopen')->assertForbidden();
        $this->actingAs($this->owner);
        Livewire::test(DayCloseComponent::class)->set('reopenReason', 'Recount the drawer')->call('reopen')->assertHasNoErrors();
        $this->assertSame(DayClose::REOPENED, $close->fresh()->status);
        $this->assertSame(0, CashMovement::active()->where('day_close_id', $close->id)->count());
        $this->assertSame(180.0, app(CashBook::class)->balanceBefore('cash', today()->addDay()));

        Livewire::test(DayCloseComponent::class)->set('counted.cash', '180')->call('close')->assertHasNoErrors()->assertSee('Balanced')->assertSee('Closed by');
        $this->assertSame(1, DayClose::count(), 'One row per day, closed again.');
    }

    public function test_a_payment_under_the_wrong_method_is_moved_once_the_owner_approves(): void
    {
        $sale = $this->sale('TX-W', 70);
        $payment = $sale->paymentTransactions()->first();

        $this->actingAs($this->cashier);
        Livewire::test(DayCloseComponent::class)->call('startCorrection', $payment->id)
            ->set('correctTo', 'momo')->set('correctReason', 'Patient paid by MoMo')->call('requestCorrection')->assertHasNoErrors()
            ->assertSee('Correction pending')->assertSee('Decide these before closing')
            ->set('counted.cash', '0')->call('close')->assertHasErrors('close');
        $correction = PaymentCorrection::first();
        $this->assertSame(['cash', 'momo', 'pending'], [$correction->from_method, $correction->to_method, $correction->status]);

        $this->actingAs($this->manager);
        $this->assertThrows(fn () => app(PaymentCorrectionService::class)->approve($correction), \RuntimeException::class, 'Only the owner');
        Livewire::test(PaymentCorrectionApprovalsComponent::class)->assertForbidden();

        $this->actingAs($this->owner);
        Livewire::test(AllApprovalsComponent::class)->call('switchType', 'correction')->assertSee('Payment Corrections')->assertSee('Patient paid by MoMo');
        Livewire::test(PaymentCorrectionApprovalsComponent::class)->call('approve', $correction->id);
        $this->assertSame('momo', $payment->fresh()->payment_method);
        $this->assertSame(PaymentCorrection::APPROVED, $correction->fresh()->status);
        $this->assertSame((float) 70, (float) $payment->fresh()->amount);
    }

    public function test_a_void_is_for_todays_sales_and_only_the_owner_approves_it(): void
    {
        $this->travelTo(now()->subDay());
        $old = $this->sale('TX-OLD', 30);
        $this->travelBack();
        $today = $this->sale('TX-NEW', 45);

        $this->assertSame('A void is only for a sale made today. Use a refund for older sales.', SalesRecordsComponent::voidProblem($old));
        $this->assertNull(SalesRecordsComponent::voidProblem($today));

        $this->actingAs($this->cashier);
        Livewire::test(SalesRecordsComponent::class)->call('initiateRefund', $old->id)->set('initiateRefundType', 'void')
            ->set('initiateRefundReasonCode', 'duplicate_charge')->set('initiateRefundReason', 'Charged twice by mistake')
            ->call('submitRefundRequest')->assertHasErrors('initiateRefundType');
        Livewire::test(SalesRecordsComponent::class)->call('initiateRefund', $today->id)->set('initiateRefundType', 'void')
            ->set('initiateRefundReasonCode', 'duplicate_charge')->set('initiateRefundReason', 'Charged twice by mistake')
            ->call('submitRefundRequest')->assertHasNoErrors();
        $void = RefundLog::where('sale_id', $today->id)->first();
        $this->assertSame(RefundLog::TYPE_VOID, $void->request_type);

        // A pending void holds the day open.
        $this->assertCount(1, app(DayCloseService::class)->blockers(today())['voids']);

        $this->actingAs($this->manager);
        Livewire::test(RefundApprovalsComponent::class)->call('confirmApprove', $void->id);
        $this->assertSame(RefundLog::STATUS_PENDING, $void->fresh()->status, 'Managers cannot approve a void.');

        $this->actingAs($this->owner);
        Livewire::test(RefundApprovalsComponent::class)->call('confirmApprove', $void->id)->call('process', $void->id);
        $void->refresh();
        $this->assertSame(RefundLog::STATUS_PROCESSED, $void->status);
        $this->assertTrue($today->fresh()->is_refunded);
        $this->assertCount(0, app(DayCloseService::class)->blockers(today())['voids']);
        $this->assertSame(['cash' => 45.0], CashBook::refundSplit($void));
        $cash = app(CashBook::class)->day(today())['cash'];
        $this->assertSame([45.0, 45.0], [$cash['in'], $cash['out']], 'The voided sale came in and went back out.');
    }
}
