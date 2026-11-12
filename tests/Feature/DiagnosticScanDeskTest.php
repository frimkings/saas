<?php

namespace Tests\Feature;

use App\Livewire\Scans\ScanDeskComponent;
use App\Models\{Category, Clinic, ClinicSubscription, DiagnosticScan, Patient, PaymentTransaction, Product, Sales, ScanOrder, ScanPartnerClinic, ScanPartnerDoctor, SubscriptionPlan, User};
use App\Services\Scans\ScanOrderService;
use App\Support\Feature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Diagnostic scans, phase 2: the Scan Desk registers referred scans, bills them and follows them to the report. */
class DiagnosticScanDeskTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Clinic $clinic;
    private DiagnosticScan $oct;
    private DiagnosticScan $vft;
    private ScanPartnerClinic $partner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->user = User::factory()->create(['name' => 'Desk Ama']);
        $this->clinic = Clinic::create(['name' => 'Scan Centre', 'slug' => 'scan-centre', 'deployment_mode' => 'hosted']);
        $branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $this->clinic->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $features = ['clinical', Feature::DIAGNOSTIC_SCANS];
        $plan = SubscriptionPlan::create(['name' => 'Scan plan', 'code' => 'scan-plan', 'included_branches' => 1, 'included_users' => 5,
            'storage_limit_mb' => 100, 'features' => $features, 'base_price' => 10, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth(), 'feature_snapshot' => $features]);
        app(TenantContext::class)->set($this->user, $this->clinic, $branch, [$branch->id]);
        $role = Role::firstOrCreate(['name' => 'Secretary', 'guard_name' => 'web']);
        $this->user->assignRole($role);
        \Illuminate\Support\Facades\DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $this->user->id, 'role_id' => $role->id]);
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($this->user, $branch);
        $this->actingAs($this->user);

        $this->oct = DiagnosticScan::create(['name' => 'OCT Macula', 'code' => 'OCT-M', 'price' => 150, 'per_eye' => true, 'commission_type' => 'percent', 'commission_value' => 10]);
        $this->vft = DiagnosticScan::create(['name' => 'Visual Field', 'price' => 200, 'commission_type' => 'fixed', 'commission_value' => 20]);
        $this->partner = ScanPartnerClinic::create(['code' => 'RC-001', 'name' => 'Hilltop Eye Clinic']);
    }

    private function patient(string $name = 'Kofi Scan'): Patient
    {
        return Patient::createWithGeneratedPxNumber(['user_id' => $this->user->id, 'name' => $name, 'contact' => '0241234567', 'gender' => 'Male']);
    }

    public function test_a_referred_new_patient_is_registered_billed_scanned_and_reported(): void
    {
        $doctor = ScanPartnerDoctor::create(['scan_partner_clinic_id' => $this->partner->id, 'name' => 'Dr Mensah']);

        $page = Livewire::test(ScanDeskComponent::class)
            ->call('add')->set('patientSearch', 'Abena New')->call('startNewPatient')->assertSet('newName', 'Abena New')
            ->set('newPhone', '0201112222')->set('newGender', 'Female')
            ->set('source', 'partner')->set('partnerId', $this->partner->id)->set('partnerDoctorId', $doctor->id)
            ->set('referrerNote', 'Ref letter 77')->set('shareConsent', true)
            ->call('addLine', $this->oct->id)->call('addLine', $this->vft->id)
            ->assertSee('500.00') // OCT both eyes 2 × 150, plus VFT 200
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false);

        $order = ScanOrder::with('items', 'patient')->firstOrFail();
        $this->assertSame('Abena New', $order->patient->name);
        $this->assertNotEmpty($order->patient->pxnumber);
        $this->assertSame('partner', $order->source);
        $this->assertSame($doctor->id, $order->scan_partner_doctor_id);
        $this->assertTrue($order->share_consent);
        $this->assertSame($this->user->id, $order->consent_by);
        $this->assertEquals(500, $order->total_amount);
        $this->assertSame(['OU', null], $order->items->pluck('eye')->all());
        $this->assertSame([2, 1], $order->items->pluck('quantity')->all());
        $this->assertMatchesRegularExpression('/^SCN-\d{4}-00001$/', $order->order_number);

        // Billed in full: a normal clinic sale on the scans' hidden products.
        $page->assertSet('viewOrderId', $order->id)->assertSet('payments.0.amount', '500.00')
            ->call('checkout')->assertHasNoErrors()->assertSee('Paid in full');
        $order->refresh();
        $sale = Sales::with('items.product.category')->findOrFail($order->sale_id);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('finalized', $sale->bill_status);
        $this->assertEquals(500, $sale->total_amount);
        $this->assertEquals(500, $sale->amount_paid);
        $this->assertSame($order->patient_id, $sale->patient_id);
        $this->assertEquals(500, PaymentTransaction::where('sale_id', $sale->id)->sum('amount'));
        $this->assertCount(2, $sale->items);
        $this->assertTrue($sale->items->every(fn ($item) => $item->product->category->type === Product::SCAN_CATEGORY_TYPE));
        $this->assertSame('OU', $sale->items->first()->eye);
        $this->assertNotNull($order->items()->first()->sale_item_id);

        // Through the work.
        $page->call('markReported')->assertHasErrors('status');
        $page->call('markScanned')->assertHasNoErrors()->call('markReported')->assertHasNoErrors();
        $order->refresh();
        $this->assertSame('reported', $order->status);
        $this->assertNotNull($order->scanned_at);
        $this->assertSame($this->user->id, $order->reported_by);
        $page->call('undoStep');
        $this->assertSame('scanned', $order->fresh()->status);
    }

    public function test_part_payment_leaves_the_balance_owed_and_the_order_can_be_scanned(): void
    {
        $order = app(ScanOrderService::class)->create(['patient_id' => $this->patient()->id, 'source' => 'self',
            'items' => [['diagnostic_scan_id' => $this->vft->id]]]);

        Livewire::test(ScanDeskComponent::class)->call('openOrder', $order->id)
            ->set('payments.0.amount', '50')->call('checkout')->assertHasNoErrors()->assertSee('Part payment recorded')
            ->assertSee('Collect the balance')->call('markScanned')->assertHasNoErrors();

        $sale = $order->fresh()->sale;
        $this->assertSame('partial', $sale->payment_status);
        $this->assertSame('open', $sale->bill_status);
        $this->assertEquals(50, $sale->amount_paid);
        $this->assertNotNull($sale->expires_at);
        $this->assertSame('scanned', $order->fresh()->status);
    }

    public function test_checkout_rules(): void
    {
        $order = app(ScanOrderService::class)->create(['patient_id' => $this->patient()->id, 'source' => 'self',
            'items' => [['diagnostic_scan_id' => $this->vft->id]]]);
        $page = Livewire::test(ScanDeskComponent::class)->call('openOrder', $order->id);

        $page->set('payments.0.amount', '250')->call('checkout')->assertHasErrors('payments');
        $page->set('payments.0.amount', '0')->call('checkout')->assertHasErrors('payments');
        $page->call('markScanned')->assertHasErrors('status');
        $this->assertNull($order->fresh()->sale_id);

        $page->set('payments.0.amount', '120')->call('addPayment')->set('payments.1.amount', '80')->call('checkout')->assertHasNoErrors();
        $this->assertSame(2, PaymentTransaction::where('sale_id', $order->fresh()->sale_id)->count());

        $this->expectException(ValidationException::class);
        app(ScanOrderService::class)->checkout($order->fresh(), [['method' => 'cash', 'amount' => 200]]);
    }

    public function test_an_unbilled_order_can_be_cancelled_with_a_reason_but_a_billed_one_cannot(): void
    {
        $open = app(ScanOrderService::class)->create(['patient_id' => $this->patient()->id, 'source' => 'self', 'items' => [['diagnostic_scan_id' => $this->vft->id]]]);
        Livewire::test(ScanDeskComponent::class)->call('openOrder', $open->id)
            ->call('cancelOrder')->assertHasErrors('cancelReason')
            ->set('cancelReason', 'Patient left')->call('cancelOrder')->assertHasNoErrors()->assertSee('Patient left');
        $this->assertSame('cancelled', $open->fresh()->status);
        $this->expectException(ValidationException::class);
        app(ScanOrderService::class)->checkout($open->fresh(), [['method' => 'cash', 'amount' => 200]]);
    }

    public function test_billed_orders_are_refunded_not_cancelled(): void
    {
        $order = app(ScanOrderService::class)->create(['patient_id' => $this->patient()->id, 'source' => 'self', 'items' => [['diagnostic_scan_id' => $this->vft->id]]]);
        app(ScanOrderService::class)->checkout($order, [['method' => 'cash', 'amount' => 200]]);
        $this->expectException(ValidationException::class);
        app(ScanOrderService::class)->cancel($order->fresh(), 'Changed mind');
    }

    public function test_order_form_validation(): void
    {
        Livewire::test(ScanDeskComponent::class)->call('add')
            ->call('save')->assertHasErrors(['partnerId'])
            ->set('source', 'self')->call('save')->assertHasErrors(['patientId'])
            ->call('startNewPatient')->call('save')->assertHasErrors(['newName', 'newPhone', 'newGender'])
            ->set('newName', 'Yaw')->set('newPhone', '0200000000')->set('newGender', 'Male')->call('save')->assertHasErrors(['items'])
            ->set('source', 'internal')->call('addLine', $this->vft->id)->call('save')->assertHasErrors(['orderedBy']);
        $this->assertSame(0, ScanOrder::count());

        // Archived scans and partners can't be ordered.
        $this->vft->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        app(ScanOrderService::class)->create(['patient_id' => $this->patient()->id, 'source' => 'self', 'items' => [['diagnostic_scan_id' => $this->vft->id]]]);
    }

    public function test_internal_orders_name_one_of_the_clinics_doctors(): void
    {
        $doctor = User::factory()->create(['name' => 'Dr Owusu']);
        $this->clinic->users()->attach($doctor->id, ['status' => 'active', 'is_default' => true]);
        $doctor->assignRole(Role::firstOrCreate(['name' => 'Doctor', 'guard_name' => 'web']));
        $patient = $this->patient();

        Livewire::test(ScanDeskComponent::class)->call('add')->call('pickPatient', $patient->id)
            ->set('source', 'internal')->assertSee('Dr Owusu')->set('orderedBy', $doctor->id)
            ->call('addLine', $this->oct->id)->set('lines.0.eye', 'OD')->call('save')->assertHasNoErrors();

        $order = ScanOrder::with('items')->firstOrFail();
        $this->assertSame('internal', $order->source);
        $this->assertSame($doctor->id, $order->ordered_by);
        $this->assertFalse($order->share_consent);
        $this->assertEquals(150, $order->total_amount);
        $this->assertSame('Dr Owusu (ours)', $order->referrerLabel());
    }

    public function test_scan_products_stay_off_the_till_and_the_product_pages(): void
    {
        $order = app(ScanOrderService::class)->create(['patient_id' => $this->patient()->id, 'source' => 'self', 'items' => [['diagnostic_scan_id' => $this->vft->id]]]);
        app(ScanOrderService::class)->checkout($order, [['method' => 'cash', 'amount' => 200]]);
        $product = Product::findOrFail($this->vft->fresh()->product_id);
        Category::create(['user_id' => $this->user->id, 'name' => 'Drops', 'type' => 'drug', 'is_active' => true]);

        $this->assertSame('Scan: Visual Field', $product->name);
        $this->assertTrue($product->made_to_order);
        $this->assertFalse($product->inActiveCategory());
        $this->assertFalse(Product::query()->inActiveCategory()->whereKey($product->id)->exists());
        $this->assertFalse(Product::notScans()->whereKey($product->id)->exists());

        // A price change reaches the product on the next sale; the earlier sale keeps its price.
        $this->vft->update(['price' => 220]);
        $next = app(ScanOrderService::class)->create(['patient_id' => $this->patient('Ama')->id, 'source' => 'self', 'items' => [['diagnostic_scan_id' => $this->vft->id]]]);
        app(ScanOrderService::class)->checkout($next, [['method' => 'cash', 'amount' => 220]]);
        $this->assertEquals(220, $product->fresh()->selling_price);
        $this->assertSame(1, Product::where('category_id', $product->category_id)->count());
        $this->assertEquals(200, $order->fresh()->sale->total_amount);

        Livewire::test(\App\Livewire\Admin\CategoryComponent::class)->assertSee('Drops')->assertDontSee('Diagnostic Scans');
    }

    public function test_stage_lists(): void
    {
        $service = app(ScanOrderService::class);
        $unbilled = $service->create(['patient_id' => $this->patient('Unbilled Una')->id, 'source' => 'self', 'items' => [['diagnostic_scan_id' => $this->vft->id]]]);
        $toScan = $service->create(['patient_id' => $this->patient('ToScan Tom')->id, 'source' => 'self', 'items' => [['diagnostic_scan_id' => $this->vft->id]]]);
        $service->checkout($toScan, [['method' => 'cash', 'amount' => 200]]);

        Livewire::test(ScanDeskComponent::class)->assertSee('Unbilled Una')->assertSee('ToScan Tom')
            ->call('setStage', 'unbilled')->assertSee('Unbilled Una')->assertDontSee('ToScan Tom')
            ->call('setStage', 'to_scan')->assertSee('ToScan Tom')->assertDontSee('Unbilled Una')
            ->set('search', 'Una')->call('setStage', 'all')->assertSee('Unbilled Una')->assertDontSee('ToScan Tom');
        $this->assertNotNull($unbilled);
    }
}
