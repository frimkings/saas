<?php

namespace Tests\Feature;

use App\Livewire\Scans\ConsultationScansComponent;
use App\Livewire\Scans\ScanCommissionsComponent;
use App\Models\{AttendanceStaff, Clinic, ClinicSubscription, DiagnosticScan, Expense, Patient, SaleItem, ScanCommission, ScanCommissionPayout, ScanOrder, SubscriptionPlan, User};
use App\Services\Attendance\AttendancePayroll;
use App\Services\Scans\ScanCommissionService;
use App\Services\Scans\ScanOrderService;
use App\Support\Feature;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Diagnostic scans, phase 4: doctors order scans from the consultation and are paid their commission with payroll. */
class DiagnosticScanPayrollTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $doctor;
    private Clinic $clinic;
    private $branch;
    private DiagnosticScan $oct;
    private AttendanceStaff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        config(['tenancy.enabled' => true]);
        $this->user = User::factory()->create(['name' => 'Manager Mary']);
        $this->doctor = User::factory()->create(['name' => 'Dr Owusu']);
        $this->clinic = Clinic::create(['name' => 'Scan Centre', 'slug' => 'scan-centre', 'deployment_mode' => 'hosted']);
        $this->branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        foreach ([$this->user, $this->doctor] as $person) {
            $this->clinic->users()->attach($person->id, ['status' => 'active', 'is_default' => true]);
            $this->branch->users()->attach($person->id, ['status' => 'active', 'is_default' => true]);
        }
        $features = ['*', Feature::DIAGNOSTIC_SCANS];
        $plan = SubscriptionPlan::create(['name' => 'Scan plan', 'code' => 'scan-plan', 'included_branches' => 1, 'included_users' => 5,
            'storage_limit_mb' => 100, 'features' => $features, 'base_price' => 10, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addYear(), 'feature_snapshot' => $features]);
        $this->actAs($this->user, 'Manager');

        $this->oct = DiagnosticScan::create(['name' => 'OCT Macula', 'price' => 150, 'per_eye' => true, 'commission_type' => 'percent', 'commission_value' => 10]);
        $this->staff = AttendanceStaff::create(['employee_no' => '001', 'name' => 'Dr Owusu', 'user_id' => $this->doctor->id, 'is_tracked' => true,
            'pay_type' => 'monthly', 'pay_rate' => 2200, 'starts_on' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actAs(User $user, string $roleName): void
    {
        app(TenantContext::class)->set($user, $this->clinic, $this->branch, [$this->branch->id]);
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user->syncRoles([$role]);
        DB::table('branch_user_role')->where('user_id', $user->id)->delete();
        DB::table('branch_user_role')->insert(['branch_id' => $this->branch->id, 'user_id' => $user->id, 'role_id' => $role->id]);
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($user, $this->branch);
        $this->actingAs($user);
    }

    private function patient(string $name = 'Kofi Scan'): Patient
    {
        return Patient::createWithGeneratedPxNumber(['user_id' => $this->user->id, 'name' => $name, 'contact' => '0241234567', 'gender' => 'Male']);
    }

    /** Dr Owusu's order for OCT on both eyes (300), paid in full: 30 commission. */
    private function paidDoctorOrder(): ScanOrder
    {
        $order = app(ScanOrderService::class)->create(['patient_id' => $this->patient()->id, 'source' => 'internal', 'ordered_by' => $this->doctor->id,
            'items' => [['diagnostic_scan_id' => $this->oct->id, 'eye' => 'OU']]]);
        app(ScanOrderService::class)->checkout($order, [['method' => 'cash', 'amount' => 300]]);

        return $order->fresh(['items', 'sale.items']);
    }

    private function payrollLine(string $month): array
    {
        $first = CarbonImmutable::parse($month.'-01');
        $payroll = app(AttendancePayroll::class)->for($first, $first->endOfMonth()->startOfDay());

        return collect($payroll['rows'])->first(fn ($line) => $line['staff']->id === $this->staff->id) + ['_has' => $payroll['commission']];
    }

    public function test_a_doctor_orders_scans_from_the_consultation_and_they_wait_at_the_desk(): void
    {
        $this->actAs($this->doctor, 'Doctor');
        $patient = $this->patient();

        Livewire::test(ConsultationScansComponent::class, ['patientId' => $patient->id])
            ->assertSee('No scans ordered for this patient')
            ->call('toggle')->assertSee('OCT Macula')
            ->call('order')->assertHasErrors('items')
            ->set('chosen.'.$this->oct->id.'.on', true)->set('chosen.'.$this->oct->id.'.eye', 'OD')->set('note', 'Suspected glaucoma')
            ->call('order')->assertHasNoErrors()->assertSet('open', false)
            ->assertSee('OCT Macula (OD)')->assertSee('Waiting to pay');

        $order = ScanOrder::with('items')->sole();
        $this->assertSame(['internal', $this->doctor->id, 'registered', null, 150.0, 'Suspected glaucoma'],
            [$order->source, (int) $order->ordered_by, $order->status, $order->sale_id, (float) $order->total_amount, $order->notes]);
        $this->assertSame('Dr Owusu (ours)', $order->referrerLabel());

        // Once paid at the desk, the doctor earns: 10% of 150.
        app(ScanOrderService::class)->checkout($order, [['method' => 'cash', 'amount' => 150]]);
        $this->assertSame(15.0, (float) ScanCommission::for('staff', $this->doctor->id)->where('status', 'accrued')->sum('amount'));
    }

    public function test_only_the_ordering_doctor_withdraws_and_only_before_billing(): void
    {
        $patient = $this->patient();
        $order = app(ScanOrderService::class)->create(['patient_id' => $patient->id, 'source' => 'internal', 'ordered_by' => $this->doctor->id,
            'items' => [['diagnostic_scan_id' => $this->oct->id, 'eye' => 'OS']]]);

        $other = User::factory()->create();
        $this->clinic->users()->attach($other->id, ['status' => 'active', 'is_default' => true]);
        $this->actAs($other, 'Doctor');
        Livewire::test(ConsultationScansComponent::class, ['patientId' => $patient->id])->assertDontSee('Withdraw')
            ->call('withdraw', $order->id)->assertForbidden();

        $this->actAs($this->doctor, 'Doctor');
        app(ScanOrderService::class)->checkout($order, [['method' => 'cash', 'amount' => 150]]);
        Livewire::test(ConsultationScansComponent::class, ['patientId' => $patient->id])->assertSee('Paid')
            ->call('withdraw', $order->id)->assertHasErrors('withdraw');
        $this->assertSame('registered', $order->fresh()->status);

        $second = app(ScanOrderService::class)->create(['patient_id' => $patient->id, 'source' => 'internal', 'ordered_by' => $this->doctor->id,
            'items' => [['diagnostic_scan_id' => $this->oct->id, 'eye' => 'OD']]]);
        Livewire::test(ConsultationScansComponent::class, ['patientId' => $patient->id])->call('withdraw', $second->id)->assertHasNoErrors()->assertSee('Withdrawn');
        $this->assertSame('cancelled', $second->fresh()->status);
    }

    public function test_the_consultation_panel_needs_the_add_on_and_a_doctor(): void
    {
        $patient = $this->patient();
        $this->actAs($this->user, 'Secretary');
        $this->assertFalse(ConsultationScansComponent::allowed());
        Livewire::test(ConsultationScansComponent::class, ['patientId' => $patient->id])->assertForbidden();

        $this->actAs($this->doctor, 'Doctor');
        $this->assertTrue(ConsultationScansComponent::allowed());
    }

    public function test_payroll_adds_a_doctors_scan_commission_to_gross_pay_and_the_download(): void
    {
        $this->paidDoctorOrder();
        $line = $this->payrollLine('2026-10');
        $this->assertTrue($line['_has']);
        $this->assertSame(30.0, $line['scan_commission']);
        $this->assertSame(round(max(0, $line['basic'] + $line['overtime_pay'] - $line['absence'] - $line['unpaid'] - $line['lateness'] + 30), 2), $line['gross']);
        $this->assertSame(0.0, $this->payrollLine('2026-09')['scan_commission']);

        $this->actAs($this->user, 'Super Admin');
        $csv = app(AttendancePayroll::class)->csv(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
        ob_start();
        $csv->sendContent();
        $content = ob_get_clean();
        $this->assertStringContainsString('Scan commission', $content);
        $this->assertStringContainsString('30.00', $content);

        // Paid by voucher instead: it is no longer in payroll.
        app(ScanCommissionService::class)->payOut('staff', $this->doctor->id, now(), 'cash', null, null);
        $this->assertSame(0.0, $this->payrollLine('2026-10')['scan_commission']);
    }

    public function test_settling_a_month_through_payroll_pays_it_once_without_an_expense(): void
    {
        $order = $this->paidDoctorOrder();
        Carbon::setTestNow('2026-11-02 09:00:00');

        Livewire::test(ScanCommissionsComponent::class)->assertSet('settleMonth', '2026-10')
            ->assertSee('Our doctors: paid with salary')->assertSee('Settle October 2026 through payroll')
            ->call('settlePayroll')->assertHasNoErrors()->assertSee('settled through payroll for 1 doctor');

        $payout = ScanCommissionPayout::sole();
        $this->assertSame(['payroll', 30.0, null, 'Payroll October 2026'], [$payout->route, (float) $payout->amount, $payout->expense_id, $payout->reference]);
        $this->assertSame(0, Expense::count());
        $this->assertSame(0, ScanCommission::where('status', 'accrued')->count());
        // Still in October's payroll, now as settled.
        $this->assertSame(30.0, $this->payrollLine('2026-10')['scan_commission']);

        // Twice is refused.
        $this->expectExceptionMessage('Nothing is left to settle for October 2026.');
        try {
            app(ScanCommissionService::class)->settleThroughPayroll('2026-10');
        } finally {
            // A refund in November: a correction lowers November's payroll, October's stays as paid.
            $saleItem = SaleItem::findOrFail($order->items->first()->sale_item_id);
            $saleItem->update(['refunded_quantity' => $saleItem->dispensed_quantity]);
            $this->assertSame(-30.0, $this->payrollLine('2026-11')['scan_commission']);
            $this->assertSame(30.0, $this->payrollLine('2026-10')['scan_commission']);
        }
    }

    public function test_a_doctor_not_followed_in_attendance_is_paid_by_voucher(): void
    {
        $this->staff->update(['user_id' => null]);
        $this->paidDoctorOrder();
        Carbon::setTestNow('2026-11-02 09:00:00');

        Livewire::test(ScanCommissionsComponent::class)->assertSee('Not followed in Attendance: pay by voucher')
            ->call('settlePayroll')->assertHasErrors('settleMonth');
        $this->assertSame(0, ScanCommissionPayout::count());

        $this->expectException(ValidationException::class);
        app(ScanCommissionService::class)->settleThroughPayroll('2026-12');
    }
}
