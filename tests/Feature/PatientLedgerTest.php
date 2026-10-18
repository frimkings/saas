<?php

namespace Tests\Feature;

use App\Livewire\Admin\PatientLedgerComponent;
use App\Models\InsuranceClaim;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\PaymentTransaction;
use App\Models\RefundLog;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PatientLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $this->actingAs($this->user);
        $this->patient = Patient::factory()->create(['name' => 'Ama Mensah']);
    }

    private function sale(string $ref, array $items, array $extra = []): Sales
    {
        $gross = array_sum($items);
        $sale = Sales::create(array_merge(['user_id' => $this->user->id, 'patient_id' => $this->patient->id,
            'business_line' => 'clinic', 'transaction_id' => $ref, 'total_amount' => $gross, 'amount_paid' => 0,
            'payment_status' => 'unpaid'], $extra));
        foreach ($items as $price) {
            SaleItem::create(['sale_id' => $sale->id, 'prescribed_quantity' => 1, 'dispensed_quantity' => 1, 'selling_price' => $price]);
        }

        return $sale;
    }

    private function pay(Sales $sale, float $amount, string $method = 'cash'): void
    {
        PaymentTransaction::create(['sale_id' => $sale->id, 'amount' => $amount, 'payment_method' => $method, 'collected_by' => $this->user->id]);
    }

    private function ledger()
    {
        return Livewire::test(PatientLedgerComponent::class)->call('selectPatient', $this->patient->id);
    }

    public function test_a_patient_can_be_found_and_their_ledger_opens(): void
    {
        $sale = $this->sale('TX-1', [100]);
        $this->pay($sale, 60, 'momo');

        Livewire::test(PatientLedgerComponent::class)
            ->set('patientSearch', 'Ama')->assertSee('Ama Mensah')
            ->call('selectPatient', $this->patient->id)
            ->assertOk()->assertSee('TX-1')
            ->assertSet('patientId', $this->patient->id);

        $this->assertSame(40.0, $this->ledger()->viewData('summary')['balance']);
        $this->get(route('admin.patient-ledger'))->assertOk();
    }

    public function test_discount_shows_as_its_own_line_after_the_full_price(): void
    {
        $sale = $this->sale('TX-2', [200], ['total_amount' => 180, 'discount_type' => 'percentage', 'discount_value' => 10, 'discount_amount' => 20]);
        $this->pay($sale, 180);

        $page = $this->ledger()->assertSee('Discount (10%)');
        $entries = $page->viewData('entries');
        $this->assertSame(200.0, $entries->firstWhere('type', 'charge')['debit']);
        $this->assertSame(20.0, $entries->firstWhere('type', 'discount')['credit']);
        $this->assertSame(['total_charges' => 200.0, 'total_payments' => 180.0, 'total_discounts' => 20.0, 'total_credits' => 200.0, 'balance' => 0.0],
            $page->viewData('summary'));
    }

    public function test_a_processed_refund_leaves_the_balance_where_it_was(): void
    {
        $insurer = Insurer::create(['name' => 'NHIS']);
        $sale = $this->sale('TX-3', [100, 50], ['insurer_id' => $insurer->id, 'insurer_amount' => 60]);
        SaleItem::where('sale_id', $sale->id)->where('subtotal', 100)->update(['insurer_amount' => 60]);
        $this->pay($sale, 90);
        $returned = SaleItem::where('sale_id', $sale->id)->where('subtotal', 100)->value('id');

        // What processing the refund leaves behind: the insurer's share of the item comes off the sale.
        $sale->update(['insurer_amount' => 0]);
        RefundLog::create(['sale_id' => $sale->id, 'sale_item_ids' => [$returned], 'request_type' => RefundLog::TYPE_REFUND,
            'status' => RefundLog::STATUS_PROCESSED, 'refund_number' => 'RF-1', 'refunded_amount' => 40, 'reason' => 'Wrong drops',
            'reason_code' => 'wrong_item', 'initiated_by' => $this->user->id, 'processed_at' => now(),
            'original_payment_references' => [['payment_method' => 'cash', 'amount' => 90]]]);
        // Approved but not yet processed: nothing has happened to the account.
        RefundLog::create(['sale_id' => $sale->id, 'request_type' => RefundLog::TYPE_REFUND, 'status' => RefundLog::STATUS_APPROVED,
            'reason' => 'Pending', 'reason_code' => 'other', 'initiated_by' => $this->user->id]);

        $page = $this->ledger()->assertSee('Returned — RF-1')->assertSee('Money returned (Cash)')->assertDontSee('Pending');
        $this->assertSame(60.0, $page->viewData('entries')->firstWhere('type', 'insurance')['credit'], 'The insurer share at the time of sale.');
        $this->assertSame(0.0, $page->viewData('summary')['balance']);
    }

    public function test_insurer_shortfall_moved_to_the_patient_is_owed_by_them(): void
    {
        $insurer = Insurer::create(['name' => 'Acacia']);
        $sale = $this->sale('TX-4', [100], ['insurer_id' => $insurer->id, 'insurer_amount' => 50]);
        $this->pay($sale, 50);
        $sale->update(['insurer_amount' => 30]);
        InsuranceClaim::create(['patient_id' => $this->patient->id, 'insurer_id' => $insurer->id, 'sale_id' => $sale->id,
            'claim_amount' => 50, 'status' => 'paid', 'shortfall_amount' => 20, 'shortfall_action' => 'bill_patient',
            'payment_date' => now()->toDateString(), 'created_by' => $this->user->id]);

        $page = $this->ledger()->assertSee('Not paid by Acacia');
        $this->assertSame(50.0, $page->viewData('entries')->firstWhere('type', 'insurance')['credit']);
        $this->assertSame(20.0, $page->viewData('summary')['balance']);
    }

    public function test_a_date_range_opens_with_the_balance_brought_forward(): void
    {
        $this->travelTo(now()->subDays(10));
        $this->sale('TX-OLD', [70]);
        $this->travelBack();
        $sale = $this->sale('TX-NEW', [30]);
        $this->pay($sale, 30);

        $page = $this->ledger()->set('fromDate', now()->subDays(2)->toDateString())
            ->assertSee('Balance brought forward')->assertDontSee('TX-OLD');
        $entries = $page->viewData('entries');
        $this->assertSame(70.0, $entries->first()['balance']);
        $this->assertSame(70.0, $page->viewData('summary')['balance']);
        $this->assertSame(30.0, $page->viewData('summary')['total_charges']);
    }

    public function test_the_ledger_downloads_as_a_pdf(): void
    {
        $this->sale('TX-5', [100], ['total_amount' => 90, 'discount_type' => 'fixed', 'discount_value' => 10, 'discount_amount' => 10]);

        $this->ledger()->call('printPdf')->assertFileDownloaded('Ledger-' . $this->patient->pxnumber . '.pdf');
    }
}
