<?php

namespace Tests\Feature;

use App\Livewire\ReportsComponent;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\Sales;
use App\Models\User;
use App\Services\Insurance\ClaimSettlement;
use App\Services\Insurance\InsuranceBilling;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** Insurer figures on the Clinical Sales Reports screen. */
class ReportsInsuranceTest extends TestCase
{
    use GivesClinicsAccess, DatabaseTransactions;

    private User $admin;
    private Patient $patient;
    private Insurer $insurer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startOfflineTrial();
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($this->admin);

        $this->insurer = Insurer::create(['name' => 'Report Health ' . uniqid(), 'scheme_type' => 'Private', 'active' => true, 'shortfall_action' => 'write_off']);
        $this->patient = Patient::factory()->create(['user_id' => $this->admin->id, 'insurer_id' => $this->insurer->id]);
    }

    public function test_tiles_split_the_bills_and_show_insurer_money_in_owed_and_written_off(): void
    {
        $this->sale(100, 0, 100);                     // cash patient
        $insured = $this->sale(400, 320, 80);         // insurer share 320
        $claim = app(InsuranceBilling::class)->syncDraftClaim($insured);
        $claim->update(['status' => 'submitted', 'submission_date' => today()]);
        app(ClaimSettlement::class)->recordPayment($this->insurer,
            ['amount' => 250, 'payment_method' => 'bank_transfer', 'paid_on' => today()->toDateString()],
            [$claim->id => ['amount' => 250, 'settle' => true]]);   // 70 written off

        $component = $this->report();
        $insurance = $component->viewData('insurance');

        $this->assertEquals(430, $component->viewData('summary')['total_sales']); // 500 less the 70 written off
        $this->assertEquals(250, $insurance['billed']);
        $this->assertEquals(180, $insurance['patient']);
        $this->assertEquals(250, $insurance['received']);
        $this->assertEquals(70, $insurance['writtenOff']);
        $this->assertEquals(0, $insurance['owedNow']);
        $component->assertSee('Billed to insurers')->assertSee('Received from insurers');
    }

    public function test_insured_filter(): void
    {
        $this->sale(100, 0, 100);
        $this->sale(400, 320, 80);

        $this->assertSame(1, $this->report()->set('insuranceFilter', 'insured')->viewData('summary')['count']);
        $this->assertEquals(100, $this->report()->set('insuranceFilter', 'uninsured')->viewData('summary')['total_sales']);
    }

    public function test_payment_methods_include_money_received_from_insurers(): void
    {
        $sale = $this->sale(400, 320, 80);
        $claim = app(InsuranceBilling::class)->syncDraftClaim($sale);
        $claim->update(['status' => 'submitted', 'submission_date' => today()]);
        app(ClaimSettlement::class)->recordPayment($this->insurer,
            ['amount' => 320, 'payment_method' => 'bank_transfer', 'paid_on' => today()->toDateString()],
            [$claim->id => ['amount' => 320]]);

        $methods = $this->report()->set('analyticsView', 'payments')->viewData('paymentMethods');

        $this->assertEquals(320, $methods->firstWhere('label', 'Insurer payments')->total);
    }

    public function test_export_has_insurer_and_patient_columns(): void
    {
        $sale = $this->sale(400, 320, 50, 'partial');

        $response = $this->report()->instance()->exportCsv();
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('Insurer,"Insurer Share","Patient Share","Amount Paid","Patient Balance"', $csv);
        $this->assertStringContainsString($sale->transaction_id, $csv);
        $this->assertStringContainsString('"' . $this->insurer->name . '",320.00,80.00,50.00,30.00', $csv);
    }

    public function test_clinics_without_insurance_see_no_insurance_panel(): void
    {
        Insurer::query()->delete();
        $this->sale(100, 0, 100);

        $this->assertNull($this->report()->viewData('insurance'));
    }

    private function report()
    {
        return Livewire::test(ReportsComponent::class)
            ->set('fromDate', today()->toDateString())
            ->set('toDate', today()->toDateString());
    }

    private function sale(float $total, float $insurer, float $paid, string $status = 'paid'): Sales
    {
        return Sales::create([
            'user_id' => $this->admin->id, 'patient_id' => $this->patient->id, 'business_line' => 'clinic',
            'insurer_id' => $insurer > 0 ? $this->insurer->id : null,
            'transaction_id' => 'RPT-' . strtoupper(bin2hex(random_bytes(4))),
            'total_amount' => $total, 'insurer_amount' => $insurer, 'amount_paid' => $paid,
            'payment_status' => $status, 'profit' => 0,
        ]);
    }
}
