<?php

namespace Tests\Feature;

use App\Livewire\Admin\InsuranceClaimsComponent;
use App\Livewire\Admin\InsurerPaymentsComponent;
use App\Livewire\Admin\InsurerReceivablesComponent;
use App\Models\InsuranceClaim;
use App\Models\Insurer;
use App\Models\InsurerPayment;
use App\Models\Patient;
use App\Models\SaleAdjustment;
use App\Models\Sales;
use App\Models\User;
use App\Services\Insurance\ClaimSettlement;
use App\Services\Insurance\InsuranceBilling;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class InsurerPaymentsTest extends TestCase
{
    use GivesClinicsAccess, DatabaseTransactions;

    private User $manager;
    private Insurer $insurer;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startOfflineTrial();
        Role::findOrCreate('Manager', 'web');
        Permission::findOrCreate(InsurerPayment::PERMISSION, 'web');
        Role::findByName('Manager', 'web')->givePermissionTo(InsurerPayment::PERMISSION);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('Manager');
        $this->actingAs($this->manager);

        $this->insurer = Insurer::create(['name' => 'Remit Health ' . uniqid(), 'scheme_type' => 'Private', 'active' => true]);
        $this->patient = Patient::factory()->create(['user_id' => $this->manager->id, 'insurer_id' => $this->insurer->id]);
    }

    // ── Recording payments ───────────────────────────────────────────────

    public function test_one_payment_settles_several_claims(): void
    {
        [$saleA, $claimA] = $this->claimedSale(400, 320, 80, 'submitted');
        [$saleB, $claimB] = $this->claimedSale(200, 150, 50, 'approved', 150);

        $payment = app(ClaimSettlement::class)->recordPayment($this->insurer, $this->details(470), [
            $claimA->id => ['amount' => 320],
            $claimB->id => ['amount' => 150],
        ]);

        $this->assertSame(2, $payment->allocations()->count());
        foreach ([$claimA, $claimB] as $claim) {
            $this->assertSame('paid', $claim->fresh()->status);
            $this->assertSame('0.00', $claim->fresh()->shortfall_amount);
        }
        $this->assertSame('320.00', $claimA->fresh()->approved_amount);
        $this->assertSame('320.00', $saleA->fresh()->insurer_amount);
        $this->assertSame('paid', $saleA->fresh()->payment_status);
    }

    public function test_part_payment_keeps_the_claim_open(): void
    {
        [, $claim] = $this->claimedSale(400, 320, 80, 'submitted');

        app(ClaimSettlement::class)->recordPayment($this->insurer, $this->details(200), [$claim->id => ['amount' => 200]]);

        $claim->refresh();
        $this->assertSame('submitted', $claim->status);
        $this->assertSame('200.00', $claim->amount_received);
        $this->assertSame(120.0, $claim->outstandingAmount());
    }

    public function test_closing_a_claim_short_bills_the_patient_by_default(): void
    {
        [$sale, $claim] = $this->claimedSale(400, 320, 80, 'submitted');

        app(ClaimSettlement::class)->recordPayment($this->insurer, $this->details(250), [$claim->id => ['amount' => 250, 'settle' => true]]);

        $sale->refresh();
        $claim->refresh();
        $this->assertSame('paid', $claim->status);
        $this->assertSame('70.00', $claim->shortfall_amount);
        $this->assertSame('bill_patient', $claim->shortfall_action);
        $this->assertSame('250.00', $sale->insurer_amount);
        $this->assertSame('400.00', $sale->total_amount);
        $this->assertSame('partial', $sale->payment_status);
        $this->assertSame(70.0, $sale->remaining_balance);
        $this->assertDatabaseHas('audit_trails', ['event' => 'insurance.shortfall', 'auditable_id' => $sale->id]);
    }

    public function test_closing_a_claim_short_writes_off_when_the_insurer_says_so(): void
    {
        $this->insurer->update(['shortfall_action' => 'write_off']);
        [$sale, $claim] = $this->claimedSale(400, 320, 80, 'submitted');
        $sale->update(['profit' => 300]);

        app(ClaimSettlement::class)->recordPayment($this->insurer, $this->details(250), [$claim->id => ['amount' => 250, 'settle' => true]]);

        $sale->refresh();
        $this->assertSame('330.00', $sale->total_amount);
        $this->assertSame('250.00', $sale->insurer_amount);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(0.0, $sale->remaining_balance);
        $this->assertSame(230.0, (float) $sale->profit);
        $this->assertSame('70.00', SaleAdjustment::where('sale_id', $sale->id)->where('type', 'insurance_write_off')->value('amount'));
        $this->assertSame('write_off', $claim->fresh()->shortfall_action);
    }

    public function test_payment_must_be_fully_applied_and_not_exceed_what_is_owed(): void
    {
        [, $claim] = $this->claimedSale(400, 320, 80, 'submitted');
        $settlement = app(ClaimSettlement::class);

        $this->assertThrowsValidation(fn () => $settlement->recordPayment($this->insurer, $this->details(300), [$claim->id => ['amount' => 200]]));
        $this->assertThrowsValidation(fn () => $settlement->recordPayment($this->insurer, $this->details(400), [$claim->id => ['amount' => 400]]));
        $this->assertSame(0, InsurerPayment::where('insurer_id', $this->insurer->id)->count());
    }

    public function test_draft_or_other_insurers_claims_cannot_be_paid(): void
    {
        [, $draft] = $this->claimedSale(400, 320, 80, 'draft');
        $other = Insurer::create(['name' => 'Other ' . uniqid(), 'scheme_type' => 'Private', 'active' => true]);
        $settlement = app(ClaimSettlement::class);

        $this->assertThrowsValidation(fn () => $settlement->recordPayment($this->insurer, $this->details(100), [$draft->id => ['amount' => 100]]));
        [, $submitted] = $this->claimedSale(400, 320, 80, 'submitted');
        $this->assertThrowsValidation(fn () => $settlement->recordPayment($other, $this->details(100), [$submitted->id => ['amount' => 100]]));
    }

    // ── Claim approval and rejection ─────────────────────────────────────

    public function test_partial_approval_bills_the_gap_to_the_patient(): void
    {
        [$sale, $claim] = $this->claimedSale(400, 320, 80, 'submitted');

        Livewire::test(InsuranceClaimsComponent::class)
            ->call('openStatusModal', $claim->id, 'partially_approved')
            ->set('statusState.approved_amount', '300')
            ->call('applyStatus')
            ->assertHasNoErrors();

        $this->assertSame('300.00', $sale->fresh()->insurer_amount);
        $this->assertSame(20.0, $sale->fresh()->remaining_balance);
        $this->assertSame(300.0, $claim->fresh()->outstandingAmount());
    }

    public function test_rejection_moves_the_whole_share_to_the_patient(): void
    {
        [$sale, $claim] = $this->claimedSale(400, 320, 80, 'submitted');

        Livewire::test(InsuranceClaimsComponent::class)
            ->call('openStatusModal', $claim->id, 'rejected')
            ->set('statusState.rejection_reason', 'Not covered under the member plan')
            ->call('applyStatus')
            ->assertHasNoErrors();

        $sale->refresh();
        $this->assertSame('0.00', $sale->insurer_amount);
        $this->assertSame('partial', $sale->payment_status);
        $this->assertSame(320.0, $sale->remaining_balance);
        $this->assertSame('320.00', $claim->fresh()->shortfall_amount);
    }

    public function test_claims_can_no_longer_be_marked_paid_without_a_payment(): void
    {
        [, $claim] = $this->claimedSale(400, 320, 80, 'approved', 320);

        Livewire::test(InsuranceClaimsComponent::class)
            ->call('openStatusModal', $claim->id, 'paid')
            ->assertDispatched('notify');

        $this->assertSame('approved', $claim->fresh()->status);
    }

    // ── Screens and access ───────────────────────────────────────────────

    public function test_payments_screen_records_a_payment(): void
    {
        [, $claimA] = $this->claimedSale(400, 320, 80, 'submitted');
        [, $claimB] = $this->claimedSale(200, 150, 50, 'submitted');

        Livewire::test(InsurerPaymentsComponent::class)
            ->call('openForm', (string) $this->insurer->id)
            ->set('payment.amount', '400')
            ->set('payment.reference', 'BANK-123')
            ->call('autoAllocate')
            ->assertSet('allocations.' . $claimA->id . '.amount', '320.00')
            ->assertSet('allocations.' . $claimB->id . '.amount', '80.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertSame('paid', $claimA->fresh()->status);
        $this->assertSame('submitted', $claimB->fresh()->status);
        $this->assertSame('BANK-123', InsurerPayment::where('insurer_id', $this->insurer->id)->value('reference'));
    }

    public function test_payments_screen_shows_allocation_errors(): void
    {
        [, $claim] = $this->claimedSale(400, 320, 80, 'submitted');

        Livewire::test(InsurerPaymentsComponent::class)
            ->call('openForm', (string) $this->insurer->id)
            ->set('payment.amount', '300')
            ->set('allocations.' . $claim->id . '.amount', '100')
            ->call('save')
            ->assertHasErrors('allocations');
    }

    public function test_receivables_subtract_what_was_received(): void
    {
        [$sale, $claim] = $this->claimedSale(400, 320, 80, 'submitted');
        app(ClaimSettlement::class)->recordPayment($this->insurer, $this->details(200), [$claim->id => ['amount' => 200]]);

        Livewire::test(InsurerReceivablesComponent::class)
            ->assertSee($sale->transaction_id)
            ->assertSee('120.00')
            ->assertSee('200.00 received');
    }

    public function test_daily_cash_summary_shows_insurer_billing_payments_and_write_offs(): void
    {
        $this->insurer->update(['shortfall_action' => 'write_off']);
        [, $claim] = $this->claimedSale(400, 320, 80, 'submitted');
        app(ClaimSettlement::class)->recordPayment($this->insurer, $this->details(250), [$claim->id => ['amount' => 250, 'settle' => true]]);

        Livewire::test(\App\Livewire\Admin\DailyCashSummaryComponent::class)
            ->set('line', 'clinic')
            // Billed is the insurer's current share: 320 less the 70 written off.
            ->assertViewHas('insurance', function ($insurance) { return $insurance['billed'] == 250 && $insurance['received'] == 250 && $insurance['writtenOff'] == 70; })
            ->assertSee('Received from insurers');
    }

    public function test_payments_need_the_permission(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole(Role::findOrCreate('Cashier', 'web'));

        $this->actingAs($cashier)->get(route('admin.insurance.payments'))->assertForbidden();

        $cashier->givePermissionTo(InsurerPayment::PERMISSION);
        $this->actingAs($cashier->fresh())->get(route('admin.insurance.payments'))->assertOk();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** A sale with an insurer share and its claim at the given status. */
    private function claimedSale(float $total, float $insurerShare, float $patientPaid, string $status, ?float $approved = null): array
    {
        $sale = Sales::create([
            'user_id' => $this->manager->id,
            'patient_id' => $this->patient->id,
            'insurer_id' => $this->insurer->id,
            'transaction_id' => 'TXN-REM-' . strtoupper(bin2hex(random_bytes(4))),
            'total_amount' => $total,
            'insurer_amount' => $insurerShare,
            'amount_paid' => $patientPaid,
            'payment_status' => 'paid',
        ]);
        $claim = app(InsuranceBilling::class)->syncDraftClaim($sale);
        $claim->update(['status' => $status, 'approved_amount' => $approved, 'submission_date' => $status === 'draft' ? null : today()]);

        return [$sale, $claim->fresh()];
    }

    private function details(float $amount): array
    {
        return ['amount' => $amount, 'payment_method' => 'bank_transfer', 'paid_on' => today()->toDateString(), 'reference' => 'REF'];
    }

    private function assertThrowsValidation(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail('Expected a validation error.');
    }
}
