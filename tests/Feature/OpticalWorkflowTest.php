<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\Category;
use App\Models\OpticalCategory;
use App\Models\OpticalProduct;
use App\Models\OpticalProductStock;
use App\Models\OpticalProductStockMovement;
use App\Models\SaleItem;
use App\Models\RefundLog;
use App\Models\CashierPatientClearance;
use App\Models\Consultations;
use App\Models\LensOrder;
use App\Models\OpticalLensBlank;
use App\Models\OpticalPrescription;
use App\Models\OpticalService;
use App\Models\OpticalPartnerClinic;
use App\Models\Patient;
use App\Models\PaymentTransaction;
use App\Models\Sales;
use App\Models\SubscriptionPlan;
use App\Models\Product;
use App\Models\Refractions;
use App\Models\User;
use App\Services\OpticalOrderService;
use App\Services\OpticalLensAvailabilityService;
use App\Services\OpticalOrderWorkflowService;
use App\Support\OpticalMode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use App\Livewire\Optical\OpticalCatalogueComponent;
use App\Livewire\Optical\OpticalCategoriesComponent;
use App\Livewire\Optical\OpticalProductsComponent;
use App\Livewire\Optical\OpticalStockManagementComponent;
use App\Livewire\Optical\OpticalReportsComponent;
use App\Livewire\Admin\RefundApprovalsComponent;
use App\Livewire\Optical\PartnerClinicsComponent;
use App\Livewire\Optical\OpticalOrderCreateComponent;
use App\Livewire\Optical\OpticalPosComponent;
use App\Livewire\Admin\ProductsComponent;
use Tests\TestCase;
use Spatie\Permission\Models\Role;

class OpticalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(User $user, string $slug): array
    {
        $clinic = Clinic::create(['name' => $slug, 'slug' => $slug, 'status' => 'active']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $plan = SubscriptionPlan::create([
            'name' => 'Optical Only', 'code' => 'optical-'.$slug, 'features' => ['optical'],
            'base_price' => 0, 'billing_interval' => 'monthly',
        ]);
        ClinicSubscription::create([
            'clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id,
            'status' => 'active', 'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);
        return [$clinic, $branch];
    }

    private function orderData(Patient $patient): array
    {
        return [
            'patient_id' => $patient->id,
            'measurements' => ['od' => ['sph' => '+1.00'], 'os' => ['sph' => '+0.75']],
            'frame_model_number' => 'Frame A', 'frame_price' => 200,
            'lens_price' => 100, 'glazing_fee' => 20, 'discount_amount' => 10,
            'paid_amount' => 50, 'payment_method' => 'cash',
            'pickup_date' => now()->addWeek()->toDateString(),
        ];
    }

    private function pricedService(string $code, float $price, bool $requiresRx = false, bool $requiresFrame = false): OpticalService
    {
        return OpticalService::create([
            'code' => $code, 'name' => ucwords(str_replace('_', ' ', $code)), 'price' => $price,
            'requires_rx' => $requiresRx, 'requires_frame' => $requiresFrame, 'is_active' => true,
        ]);
    }

    public function test_service_only_partner_job_skips_prescription_and_preserves_service_pricing(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'service-center');
        $this->actingAs($user);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Partner Customer', 'contact' => '0240000999', 'gender' => 'Other',
        ]);
        $data = $this->orderData($patient);
        unset($data['measurements']);
        $data['work_type'] = 'service';
        $data['bill_to'] = 'partner';
        $data['partner_clinic_name'] = 'Westside Eye Clinic';
        $transfer = $this->pricedService('lens_transfer', 80, false, true);
        $adjustment = $this->pricedService('frame_adjustment', 15, false, true);
        $data['services'] = [
            ['service_id' => $transfer->id, 'quantity' => 1, 'unit_price' => 1],
            ['service_id' => $adjustment->id, 'quantity' => 2, 'unit_price' => 1],
        ];
        $data['frame_model_number'] = 'Customer frame';
        $data['frame_price'] = 0;
        $data['lens_price'] = 0;
        $data['glazing_fee'] = 0;
        $data['discount_amount'] = 0;
        $data['paid_amount'] = 30;
        $data['docket'] = ['frame_source' => 'customer'];

        $order = app(OpticalOrderService::class)->create($data);
        $this->assertNull($order->optical_prescription_id);
        $this->assertSame([], $order->prescription_snapshot);
        $this->assertSame(0, OpticalPrescription::count());
        $this->assertSame(110.0, $order->total);
        $this->assertSame(80.0, (float) $order->serviceLines()->firstOrFail()->unit_price);
        $this->assertSame(2, $order->serviceLines()->count());
        $this->assertSame('Westside Eye Clinic', $order->partner_clinic_name);
        $this->assertSame(110.0, (float) $order->sale->total_amount);
        $this->assertSame(0, $order->sale->items()->count());
    }

    public function test_service_quote_can_be_activated_and_other_tenant_cannot_read_its_lines(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'service-quote-a');
        $this->actingAs($user);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Job Customer', 'contact' => '0240000888', 'gender' => 'Other',
        ]);
        $data = $this->orderData($patient);
        unset($data['measurements']);
        $data['work_type'] = 'service';
        $cleaning = $this->pricedService('cleaning', 45);
        $data['services'] = [['service_id' => $cleaning->id, 'quantity' => 1]];
        $data['frame_model_number'] = null;
        $data['frame_price'] = $data['lens_price'] = $data['glazing_fee'] = $data['discount_amount'] = 0;
        $quote = app(OpticalOrderService::class)->create($data, true);
        $this->assertNull($quote->optical_prescription_id);
        $this->assertNull($quote->frame_model_number);
        $order = app(OpticalOrderWorkflowService::class)->activateQuotation($quote->id);
        $this->assertSame(45.0, (float) $order->sale->total_amount);
        $this->assertNull($order->stock_reserved_at);
        $lineId = $order->serviceLines()->firstOrFail()->id;

        $this->tenant($user, 'service-quote-b');
        $this->assertNull(\App\Models\OpticalOrderServiceLine::find($lineId));
        $this->assertNull(LensOrder::find($order->id));
    }

    public function test_service_wizard_skips_unneeded_steps_and_requires_rx_for_glazing(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'service-steps');
        $this->actingAs($user);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Workshop Customer', 'contact' => '0240000777', 'gender' => 'Other',
        ]);
        $cleaning = $this->pricedService('cleaning', 25);
        $glazing = $this->pricedService('glazing', 60, true, true);

        Livewire::test(OpticalOrderCreateComponent::class)
            ->call('choosePatient', $patient->id)
            ->set('work_type', 'service')
            ->set('service_lines', [['service_id' => $cleaning->id, 'quantity' => 1]])
            ->call('nextStep')->assertSet('currentStep', 4)->assertHasNoErrors()
            ->call('prevStep')->assertSet('currentStep', 1);

        Livewire::test(OpticalOrderCreateComponent::class)
            ->call('choosePatient', $patient->id)
            ->set('work_type', 'service')
            ->set('service_lines', [['service_id' => $glazing->id, 'quantity' => 1]])
            ->call('nextStep')->assertSet('currentStep', 2)->assertHasNoErrors();
    }

    public function test_manager_sets_service_price_and_catalogue_is_tenant_scoped(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $this->tenant($user, 'priced-catalogue-a');
        $this->actingAs($user);

        Livewire::test(OpticalCatalogueComponent::class)->set('activeTab', 'services');
        $service = OpticalService::where('code', 'frame_adjustment')->firstOrFail();
        $this->assertFalse($service->is_active);
        Livewire::test(OpticalCatalogueComponent::class)
            ->call('editService', $service->id)
            ->set('servicePrice', '35.00')
            ->set('serviceActive', true)
            ->call('saveService')->assertHasNoErrors();
        $this->assertSame(35.0, (float) $service->fresh()->price);
        $this->assertTrue($service->fresh()->is_active);
        $patientA = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Priced Job Customer', 'contact' => '0240000555', 'gender' => 'Other',
        ]);
        $pricedData = $this->orderData($patientA);
        unset($pricedData['measurements']);
        $pricedData['work_type'] = 'service';
        $pricedData['services'] = [['service_id' => $service->id, 'quantity' => 1, 'unit_price' => 0]];
        $pricedData['docket'] = ['frame_source' => 'customer'];
        $pricedData['lens_price'] = $pricedData['glazing_fee'] = $pricedData['discount_amount'] = 0;
        $pricedData['paid_amount'] = 0;
        $order = app(OpticalOrderService::class)->create($pricedData);
        $service->update(['price' => 50]);
        $this->assertSame(35.0, $order->fresh()->total);
        $this->assertSame(35.0, (float) $order->serviceLines()->firstOrFail()->unit_price);

        $this->tenant($user, 'priced-catalogue-b');
        $this->assertNull(OpticalService::find($service->id));
        Livewire::test(OpticalCatalogueComponent::class)->set('activeTab', 'services');
        $this->assertFalse(OpticalService::where('code', 'frame_adjustment')->firstOrFail()->is_active);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Second Tenant Customer', 'contact' => '0240000666', 'gender' => 'Other',
        ]);
        $data = $this->orderData($patient);
        unset($data['measurements']);
        $data['work_type'] = 'service';
        $data['services'] = [['service_id' => $service->id, 'quantity' => 1, 'unit_price' => 0]];
        $this->expectException(ValidationException::class);
        app(OpticalOrderService::class)->create($data);
    }

    public function test_non_manager_cannot_change_service_prices(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'service-staff');
        $this->actingAs($user);
        Livewire::test(OpticalCatalogueComponent::class)
            ->call('addService')->assertForbidden();
        $service = OpticalService::where('code', 'frame_adjustment')->firstOrFail();
        Livewire::test(OpticalCatalogueComponent::class)
            ->call('editService', $service->id)->assertForbidden();
        $this->assertSame(0.0, (float) $service->fresh()->price);
    }

    public function test_optical_only_creates_order_without_consultation_and_records_deposit(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'optical-one');
        $this->actingAs($user);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Walk-in Customer',
            'contact' => '0240000000', 'gender' => 'Other',
        ]);

        $this->assertTrue(OpticalMode::opticalOnly());
        $order = app(OpticalOrderService::class)->create($this->orderData($patient));

        $this->assertNull($order->refraction_id);
        $this->assertSame($patient->id, $order->patient_id);
        $this->assertSame(310.0, $order->total);
        $this->assertSame(50.0, (float) $order->paid_amount);
        $this->assertSame('+1.00', data_get($order->prescription_snapshot, 'od.sph'));
        $this->assertSame('partial', Sales::findOrFail($order->sale_id)->payment_status);
        $this->assertSame(1, PaymentTransaction::where('sale_id', $order->sale_id)->count());

        app(OpticalOrderWorkflowService::class)->recordPayment($order->id, 260, 'momo');
        $this->assertSame('paid', Sales::findOrFail($order->sale_id)->payment_status);
        $this->assertSame(310.0, (float) $order->fresh()->paid_amount);
    }

    public function test_other_subscriber_records_cannot_be_selected_or_read(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        [$clinicA, $branchA] = $this->tenant($user, 'optical-a');
        $this->actingAs($user);
        $patientA = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'A Customer', 'contact' => '111', 'gender' => 'Other',
        ]);
        $orderA = app(OpticalOrderService::class)->create($this->orderData($patientA), true);

        $this->tenant($user, 'optical-b');
        $this->assertNull(Patient::find($patientA->id));
        $this->assertNull(LensOrder::find($orderA->id));
        $this->assertSame(0, OpticalPrescription::count());

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(OpticalOrderService::class)->create($this->orderData($patientA));
    }

    public function test_combined_subscription_can_order_from_authorized_clinic_refraction(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        [$clinic] = $this->tenant($user, 'combined-optical');
        $clinic->subscriptions()->latest()->firstOrFail()->update(['feature_snapshot' => ['clinical', 'optical']]);
        $this->actingAs($user);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Clinic Customer', 'contact' => '0240000001', 'gender' => 'Other',
        ]);
        $clearance = CashierPatientClearance::create([
            'user_id' => $user->id, 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid',
        ]);
        $consultation = Consultations::create([
            'user_id' => $user->id, 'patient_id' => $patient->id,
            'clearance_id' => $clearance->id, 'chiefComplaint' => 'Blurred vision',
        ]);
        $refraction = Refractions::create([
            'user_id' => $user->id, 'consultation_id' => $consultation->id,
            'refractionOD' => '-2.00', 'refractionOS' => '-1.50',
            'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6',
            'subjective_od_sphere' => -2, 'subjective_os_sphere' => -1.5,
            'dispensing_required' => true, 'dispensing_authorized_at' => now(),
            'dispensing_authorized_by' => $user->id,
        ]);

        $this->assertFalse(OpticalMode::opticalOnly());
        $data = $this->orderData($patient);
        $data['refraction_id'] = $refraction->id;
        unset($data['measurements']);
        $order = app(OpticalOrderService::class)->create($data);

        $this->assertSame('clinic', $order->opticalPrescription->source);
        $this->assertSame($refraction->id, $order->opticalPrescription->refraction_id);
        $this->assertSame('-2.00', data_get($order->prescription_snapshot, 'od.sph'));
    }

    public function test_optical_only_pages_render_and_clinical_route_is_blocked(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $user->assignRole($role);
        [$clinic, $branch] = $this->tenant($user, 'optical-ui');
        DB::table('branch_user_role')->insert([
            'user_id' => $user->id, 'branch_id' => $branch->id, 'role_id' => $role->id,
        ]);

        $category = OpticalCategory::firstOrCreate(['clinic_id' => $clinic->id, 'code' => 'frames'], ['name' => 'Frames']);
        $opticalProduct = OpticalProduct::create(['clinic_id' => $clinic->id, 'optical_category_id' => $category->id, 'name' => 'Test Low Stock Frame', 'sku' => 'FRM-LOW-1', 'selling_price' => 100, 'cost_price' => 50, 'is_active' => true]);
        OpticalProductStock::create(['clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'optical_product_id' => $opticalProduct->id, 'quantity' => 2, 'reorder_level' => 5]);

        $this->actingAs($user)->get(route('optical.dashboard'))
            ->assertOk()->assertSee('Optical Dashboard')->assertSee('Test Low Stock Frame')->assertDontSee('Doctor Consultations');
        $this->get(route('optical.partners'))->assertOk()->assertSee('Partner Clinics');
        $this->get(route('optical.customers'))->assertRedirect(route('optical.partners'));
        $this->get(route('optical.prescriptions'))->assertOk()->assertSee('Clinic refractions ready to dispense');
        Sales::create(['user_id' => $user->id, 'business_line' => 'clinic', 'transaction_id' => 'CLINIC-SEPARATE-1', 'total_amount' => 30, 'amount_paid' => 30, 'payment_status' => 'paid']);
        Sales::create(['user_id' => $user->id, 'business_line' => 'optical', 'transaction_id' => 'OPT-SEPARATE-1', 'total_amount' => 40, 'amount_paid' => 40, 'payment_status' => 'paid']);
        $this->get(route('optical.sales'))->assertOk()->assertSee('OPT-SEPARATE-1')->assertDontSee('CLINIC-SEPARATE-1');
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Docket Customer', 'contact' => '0243330000', 'gender' => 'Other',
        ]);
        $quote = app(OpticalOrderService::class)->create($this->orderData($patient), true);
        $this->get(route('optical.orders.create', ['quotation_id' => $quote->id]))
            ->assertOk()->assertSee('Edit Optical Quotation');
        $this->get(route('optical.orders.docket', $quote->id))
            ->assertOk()->assertSee('Optical Order Docket')->assertSee($quote->order_id);
        $this->get(route('doctor.dashboard'))->assertRedirect(route('optical.dashboard'));
    }

    public function test_optical_retail_pos_does_not_offer_clinic_stock(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'optical-pos');
        $this->actingAs($user);
        $category = Category::create(['user_id' => $user->id, 'name' => 'Optical Accessories']);
        $product = Product::create([
            'user_id' => $user->id, 'name' => 'Lens Cleaner', 'category_id' => $category->id,
            'quantity' => 3, 'cost_price' => 10, 'selling_price' => 25,
        ]);

        Livewire::test(OpticalPosComponent::class)->assertDontSee('Lens Cleaner');

        $this->assertSame(3, app(\App\Services\Inventory\BranchInventoryService::class)->quantity($product));
        $this->assertSame(0, Sales::where('business_line', 'optical')->count());
    }

    public function test_shared_lens_receiving_updates_matrix_and_reversal(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $this->tenant($user, 'shared-lenses');
        $this->actingAs($user);
        $form = Livewire::test(OpticalStockManagementComponent::class)->call('openReceipt')
            ->set('stockType', 'lens')->set('lensRange', 'Factory A')->set('lensDesign', 'Bifocal')
            ->set('lensPower', '2.00')->set('quantity', '5')->set('unitCost', '12.50')
            ->set('unitPrice', '20')->set('supplier', 'Factory')->call('save')->assertHasErrors(['lensEye'])
            ->set('lensEye', 'R')->call('save')->assertHasNoErrors();
        $product = OpticalProduct::whereNotNull('lens_key')->firstOrFail();
        $this->assertSame(5, $product->stocks->first()->quantity);
        Livewire::test(OpticalCatalogueComponent::class)->set('activeTab', 'lens-matrix')
            ->set('matrixRange', 'Factory A')->set('matrixDesign', 'Bifocal')->set('matrixDiameter', 65)
            ->assertViewHas('lensBlankStock', fn ($stock) => $stock['0.00|2.00'] === 5)
            ->set('matrixEye', 'L')->assertViewHas('lensBlankStock', fn ($stock) => ! isset($stock['0.00|2.00']));
        $form->set('quantity', '2')->set('unitPrice', '25')->call('save')->assertHasNoErrors();
        $this->assertEquals(20, $product->fresh()->selling_price);
        $form->set('updateSellingPrice', true)->call('save')->assertHasNoErrors();
        $this->assertEquals(25, $product->fresh()->selling_price);
        $movement = OpticalProductStockMovement::latest('id')->first();
        $form->call('reverse', $movement->id)->assertHasNoErrors();
        $this->assertSame(7, $product->stocks()->first()->quantity);
    }

    public function test_bulk_lens_receiving_supports_paste_and_cost_overrides(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $this->tenant($user, 'bulk-lenses');
        $this->actingAs($user);
        Livewire::test(OpticalStockManagementComponent::class)->call('openReceipt')
            ->set('stockType', 'lens')->set('lensRange', 'Factory B')->set('entryMode', 'bulk')
            ->set('bulkPaste', "3\t2")->call('pasteGrid')->assertHasNoErrors()
            ->set('bulkCosts.60.1', '15')->set('unitCost', '10')->set('unitPrice', '30')
            ->set('supplier', 'Factory')->call('save')->assertHasNoErrors();
        $this->assertSame(2, OpticalProduct::whereNotNull('lens_key')->count());
        $this->assertEquals(5, OpticalProductStock::sum('quantity'));
        $this->assertDatabaseHas('optical_product_stock_movements', ['quantity_change' => 2, 'unit_cost' => 15]);
        Livewire::test(OpticalCatalogueComponent::class)->call('openIntake')->assertRedirect();
    }

    public function test_full_lens_grid_is_received_in_a_bounded_number_of_queries(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $this->tenant($user, 'full-grid');
        $this->actingAs($user);
        // 41 SPH rows x 25 CYL columns: a whole single-vision sheet.
        $grid = [];
        for ($r = 40; $r <= 80; $r++) for ($c = 0; $c <= 24; $c++) $grid[$r][$c] = (string) (1 + ($r + $c) % 4);
        $receive = function () use ($grid) {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            Livewire::test(OpticalStockManagementComponent::class)->call('openReceipt')
                ->set('stockType', 'lens')->set('lensRange', 'Factory Grid')->set('entryMode', 'bulk')
                ->set('bulkQuantities', $grid)->set('bulkCosts.60.1', '15')->set('unitCost', '10')->set('unitPrice', '30')
                ->set('supplier', 'Factory')->call('save')->assertHasNoErrors();
            $count = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();
            return $count;
        };
        // New SKUs, then the same powers again as existing stock.
        $this->assertLessThan(200, $receive());
        $this->assertLessThan(200, $receive());
        $expected = 0;
        foreach ($grid as $cells) foreach ($cells as $quantity) $expected += 2 * (int) $quantity;
        $this->assertSame(41 * 25, OpticalProduct::whereNotNull('lens_key')->count());
        $this->assertSame(41 * 25 * 2, OpticalProductStockMovement::where('movement_type', 'receipt')->count());
        $this->assertEquals($expected, OpticalProductStock::sum('quantity'));
        $cell = OpticalProduct::whereNotNull('lens_key')->get()->first(fn ($p) => $p->lens_specs['sphere'] === '0.00' && $p->lens_specs['power'] === '-0.25');
        $this->assertEquals(15, $cell->cost_price);
        $this->assertEquals(30, $cell->selling_price);
        $movements = OpticalProductStockMovement::where('optical_product_id', $cell->id)->orderBy('id')->get();
        $this->assertSame([2, 4], $movements->pluck('balance_after')->all());
        $this->assertEquals(15, $movements->first()->unit_cost);
        $this->assertSame($user->id, $movements->first()->user_id);
    }

    public function test_legacy_lens_migration_preserves_stock_and_history(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        [$clinic, $branch] = $this->tenant($user, 'legacy-lens-migration');
        $this->actingAs($user);
        $blank = OpticalLensBlank::create(['lens_index' => '1.56', 'coating' => 'AR', 'sphere' => 0, 'cylinder' => 0, 'quantity' => 8]);
        DB::table('optical_lens_blank_movements')->insert(['clinic_id' => $clinic->id, 'branch_id' => $branch->id,
            'optical_lens_blank_id' => $blank->id, 'user_id' => $user->id, 'quantity_change' => 8, 'balance_after' => 8,
            'reference' => 'OLD-1', 'created_at' => now(), 'updated_at' => now()]);
        $migration = require database_path('migrations/2026_09_22_000003_move_lens_blank_stock_to_product_ledger.php');
        $migration->up();
        $migration->up();
        $this->assertSame(8, OpticalProductStock::firstOrFail()->quantity);
        $this->assertSame(1, OpticalProductStockMovement::count());
        $this->assertNotNull($blank->fresh()->optical_product_id);
        $result = app(OpticalLensAvailabilityService::class)->check(['od' => ['sph' => 0], 'os' => ['sph' => 0]], '1.56', 'AR', 'Single Vision');
        $this->assertSame('available', $result['status']);
    }

    public function test_matrix_defaults_to_received_stock_and_refreshes_branch_balance(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        [$clinic, $branch] = $this->tenant($user, 'matrix-default-stock');
        $this->actingAs($user);
        $product = app(\App\Services\OpticalLensReceivingService::class)->receive([
            'range' => 'Factory Photo', 'design' => 'Single Vision', 'index' => '1.67', 'coating' => 'Photo AR',
            'diameter' => 65, 'sphere' => '-2.00', 'power' => '-1.00',
        ], 8, ['unit_cost' => 10, 'unit_price' => 20]);
        $matrix = Livewire::test(OpticalCatalogueComponent::class)->set('activeTab', 'lens-matrix')
            ->assertSet('matrixRange', 'Factory Photo')->assertSet('matrixDiameter', 65)
            ->assertSet('matrixIndex', '1.67')->assertViewHas('matrixTotal', 8)
            ->assertViewHas('lensBlankStock', fn ($stock) => $stock['-2.00|-1.00'] === 8)
            ->assertViewHas('matrixSpheres', fn ($rows) => $rows->count() === 1 && (float)$rows->first() === -2.0);
        app(\App\Services\OpticalStockLedgerService::class)->adjust($product, -3, 'Count correction');
        $matrix->call('$refresh')->assertViewHas('matrixTotal', 5);
        $other = $clinic->branches()->create(['code' => 'OTHER', 'name' => 'Other', 'is_active' => true]);
        $other->users()->attach($user->id, ['status' => 'active', 'is_default' => false]);
        app(TenantContext::class)->set($user, $clinic, $other, [$other->id]);
        Livewire::test(OpticalCatalogueComponent::class)->assertViewHas('matrixTotal', 0);
    }

    public function test_excel_upload_previews_before_receiving_any_stock(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $this->tenant($user, 'excel-lens-upload');
        $this->actingAs($user);
        $path = tempnam(sys_get_temp_dir(), 'lens-xlsx-');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Order" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="2"><c r="A2" t="inlineStr"><is><t>SPH</t></is></c><c r="B2"><v>0</v></c><c r="C2"><v>-0.25</v></c></row><row r="3"><c r="A3"><v>0.25</v></c><c r="B3"><v>3</v></c><c r="C3"><v>5</v></c></row></sheetData></worksheet>');
        $zip->close();
        try {
            $upload = UploadedFile::fake()->createWithContent('manufacturer-order.xlsx', file_get_contents($path));
            $form = Livewire::test(OpticalStockManagementComponent::class)->call('openReceipt')
                ->call('downloadLensTemplate')->assertFileDownloaded('SV CLEAR AR LENS ORDER.xlsx')
                ->set('stockType', 'lens')->set('entryMode', 'bulk')->set('lensRange', 'Excel Range')
                ->set('excelFile', $upload);
            $this->assertSame([], $form->errors()->all(), 'Upload: '.json_encode($form->errors()->all()));
            $form->set('excelUnit', 'pieces')->call('previewExcel');
            $this->assertSame([], $form->errors()->all(), 'Preview: '.json_encode($form->errors()->all()));
            $form->assertSet('excelPreview.total', 8);
            $this->assertSame(0, OpticalProductStockMovement::count());
            $form->call('save')->assertHasErrors('excelFile');
            $form->call('applyExcel')->assertHasNoErrors()->assertSet('bulkQuantities.61.1', 5);
            $this->assertSame(0, OpticalProductStockMovement::count());
            $form->set('unitCost', '10')->set('unitPrice', '20')->set('supplier', 'Manufacturer')->call('save')->assertHasNoErrors();
            $this->assertEquals(8, OpticalProductStock::sum('quantity'));
            $this->assertSame(2, OpticalProductStockMovement::count());
            $receipt = \App\Models\OpticalLensImport::firstOrFail();
            $this->assertSame('manufacturer-order.xlsx', $receipt->filename);
            $this->assertEquals(8, $receipt->pieces);
            $this->assertSame(2, $receipt->movements()->count());
            $form->call('save')->assertHasErrors('importReceipt');
            $this->assertEquals(8, OpticalProductStock::sum('quantity'));
            $form->call('viewImport', $receipt->id)->assertSee('manufacturer-order.xlsx')->assertSee('Import #'.$receipt->id);
            $form->call('closeImport')->assertSet('viewImportId', null);
            $form->set('reference', 'NEW-DELIVERY')->set('repeatDeliveryReason', 'Separate weekly delivery from supplier')->call('save')->assertHasNoErrors();
            $this->assertEquals(16, OpticalProductStock::sum('quantity'));
            $form->call('save')->assertHasErrors('importReceipt');
            $this->assertSame(2, \App\Models\OpticalLensImport::count());
            $form->call('reverseImport', $receipt->id)->assertHasNoErrors();
            $this->assertEquals(8, OpticalProductStock::sum('quantity'));
            $form->call('reverseImport', $receipt->id)->assertHasErrors('movement');
        } finally { if (file_exists($path)) unlink($path); }
    }

    public function test_excel_preview_error_is_shown_beside_the_import_controls(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $this->tenant($user, 'excel-lens-error');
        $this->actingAs($user);
        $path = tempnam(sys_get_temp_dir(), 'lens-xlsx-');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="BLUE BLOCK" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="4"><c r="A4" t="inlineStr"><is><t>(+)</t></is></c><c r="B4"><v>0</v></c><c r="C4"><v>-0.25</v></c></row><row r="5"><c r="A5" t="inlineStr"><is><t>+0.00</t></is></c><c r="B5"><v>3</v></c><c r="C5" t="inlineStr"><is><t>s</t></is></c></row></sheetData></worksheet>');
        $zip->close();
        try {
            $html = Livewire::test(OpticalStockManagementComponent::class)->call('openReceipt')
                ->set('stockType', 'lens')->set('entryMode', 'bulk')->set('lensRange', 'Blue Block')
                ->set('excelFile', UploadedFile::fake()->createWithContent('order.xlsx', file_get_contents($path)))
                ->assertSet('excelUnit', 'pairs')->call('previewExcel')
                ->assertHasErrors('excelFile')->html();
            $message = 'Invalid quantity in 1 cell: C5 (&quot;s&quot;)';
            $this->assertStringContainsString($message, $html);
            // Must appear inside the import panel, not only in the error list at the foot of the long form.
            $this->assertLessThan(strpos($html, 'wire:model.live="templateGrid"'), strpos($html, $message));
        } finally { if (file_exists($path)) unlink($path); }
    }

    public function test_lens_import_reversal_is_atomic_and_history_is_branch_scoped(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        [$clinic, $branch] = $this->tenant($user, 'import-history');
        $this->actingAs($user);
        $service = app(\App\Services\OpticalLensImportReceiptService::class);
        $source = ['filename' => 'order.xlsx', 'worksheet' => 'SV BLUE', 'unit' => 'pairs', 'source_quantity' => 5, 'quantities' => [60 => [0 => 4, 1 => 6]]];
        $specs = ['range' => 'Blue', 'design' => 'Single Vision', 'coating' => 'BlueCut', 'index' => '1.56', 'diameter' => 65];
        $details = ['supplier' => 'Factory', 'reference' => 'INV-1', 'batch_number' => 'LOT-1'];
        $receipt = $service->receive($source, $specs, [[0, 0, 4, 10, 20], [0, -.25, 6, 11, 21]], $details, false);
        $lines = $receipt->movements()->orderBy('id')->get();
        app(\App\Services\OpticalStockLedgerService::class)->adjust($lines[1]->product, -1, 'Used lens');
        try { $service->reverse($receipt->id); $this->fail('Insufficient stock must prevent the whole reversal.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('movement', $e->errors()); }
        $this->assertEquals(9, OpticalProductStock::sum('quantity'));
        $this->assertSame(0, OpticalProductStockMovement::where('movement_type', 'reversal')->count());
        $other = $clinic->branches()->create(['code' => 'OTHER', 'name' => 'Other', 'is_active' => true]);
        app(TenantContext::class)->set($user, $clinic, $other, [$branch->id, $other->id]);
        $this->assertSame(0, \App\Models\OpticalLensImport::count());
        $this->assertFalse($service->matches($receipt->fingerprint)->exists());
        try {
            Livewire::test(OpticalStockManagementComponent::class)->call('viewImport', $receipt->id);
            $this->fail('A receipt from another branch must not be accessible.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->assertSame(\App\Models\OpticalLensImport::class, $e->getModel());
        }
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id, $other->id]);
        app(\App\Services\OpticalStockLedgerService::class)->adjust($lines[1]->product, 1, 'Return lens');
        $service->reverse($receipt->id);
        $this->assertEquals(0, OpticalProductStock::sum('quantity'));
        $this->assertFalse($service->matches($receipt->fingerprint)->exists());
        $service->receive($source, $specs, [[0, 0, 4, 10, 20], [0, -.25, 6, 11, 21]], $details, false);
        $this->assertEquals(10, OpticalProductStock::sum('quantity'));
    }

    public function test_lens_replenishment_thresholds_export_and_branch_settings(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        [$clinic, $branch] = $this->tenant($user, 'lens-reorder');
        $this->actingAs($user);
        $specs = ['range' => 'Reorder Range', 'design' => 'Single Vision', 'index' => '1.56', 'coating' => 'BlueCut', 'diameter' => 65];
        $products = [];
        foreach ([0 => 1, 1 => 9, 2 => 10, 3 => 11] as $i => $qty) {
            $products[$i] = app(\App\Services\OpticalLensReceivingService::class)->receive($specs + ['sphere' => number_format($i / 4, 2, '.', ''), 'power' => '0.00'], $qty, ['unit_cost' => 10, 'unit_price' => 20]);
        }
        $this->assertSame('REORDERR-SV-1.56-BLUECUT-P0.25-0.00', $products[1]->sku);
        $this->assertSame('REORDERR-SV-1.56-BLUECUT-0.00-0.00', $products[0]->sku);
        $clash = app(\App\Services\OpticalLensReceivingService::class)->receive(['range' => 'Reorder Range Two'] + $specs + ['sphere' => '0.25', 'power' => '0.00'], 1, ['unit_cost' => 10, 'unit_price' => 20]);
        $this->assertMatchesRegularExpression('/^REORDERR-SV-1\.56-BLUECUT-P0\.25-0\.00-[0-9A-F]{6}$/', $clash->sku);
        app(\App\Services\OpticalStockLedgerService::class)->adjust($products[0], -1, 'Empty stock');
        $service = app(\App\Services\OpticalLensReplenishmentService::class);
        $rows = $service->rows($specs);
        $this->assertSame([10, 6, 5, 0], $rows->pluck('pairs')->all());
        $this->assertSame([true, true, true, false], $rows->pluck('low')->all());
        $form = Livewire::test(OpticalCatalogueComponent::class)->set('activeTab', 'lens-matrix');
        $form->assertSee('data-tp="y"', false)->assertSee('data-tp="r"', false)->assertSee('21 pairs');
        $form->call('editReorderLevels')->set('reorderPairs.'.$products[3]->id, 6)->set('targetPairs.'.$products[3]->id, 12)
            ->call('saveReorderLevels')->assertHasNoErrors();
        $this->assertSame(7, $service->rows($specs)->last()['pairs']);
        $path = tempnam(sys_get_temp_dir(), 'reorder_test_');
        try {
            file_put_contents($path, $service->workbook($specs));
            $reader = app(\App\Services\OpticalLensExcelImportService::class);
            $sheet = $reader->read($path)[0];
            $this->assertSame('pairs', $reader->specifications($sheet)['unit']);
            $preview = $reader->previewTemplate($sheet, 'Single Vision', 'pairs');
            $this->assertSame(56, $preview['total']);
            $this->assertSame(4, count($preview['lines']));
        } finally { unlink($path); }
        $form->call('editReorderLevels')->set('targetPairs.'.$products[3]->id, 5)->call('saveReorderLevels')->assertHasErrors('replenishment');
        $other = $clinic->branches()->create(['code' => 'OTHER', 'name' => 'Other', 'is_active' => true]);
        app(TenantContext::class)->set($user, $clinic, $other, [$branch->id, $other->id]);
        $this->assertCount(0, $service->rows($specs));
    }

    public function test_st_pat_upload_records_the_same_quantity_in_ledger_and_matrix(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $this->tenant($user, 'st-pat-inventory');
        $this->actingAs($user);
        $upload = UploadedFile::fake()->createWithContent('ST PAT.xlsx', file_get_contents(resource_path('templates/st-pat-lens-order.xlsx')));
        $form = Livewire::test(OpticalStockManagementComponent::class)->call('openReceipt')
            ->set('stockType', 'lens')->set('entryMode', 'bulk')->set('lensRange', 'ST PAT Blue')
            ->set('excelFile', $upload)->assertHasNoErrors()->assertSet('excelLayout', 'template')
            ->assertSet('lensCoating', 'BlueCut')->set('excelUnit', 'pieces')
            ->call('previewExcel')->assertHasNoErrors()->assertSet('excelPreview.total', 186);
        $this->assertSame(0, OpticalProductStockMovement::count());
        $form->call('cancelExcel')->assertSet('lensCoating', 'AR')->assertSet('excelFile', null);
        $form->set('excelFile', $upload)->set('excelUnit', 'pieces')->call('previewExcel')->assertHasNoErrors();
        $form->call('applyExcel')->assertHasNoErrors()->assertSet('bulkQuantities.61.0', 10);
        $this->assertSame(0, OpticalProductStockMovement::count());
        $form->set('unitCost', '10')->set('unitPrice', '20')->set('supplier', 'Manufacturer')->call('save')->assertHasNoErrors();
        $this->assertEquals(186, OpticalProductStock::sum('quantity'));
        $this->assertEquals(186, OpticalProductStockMovement::sum('quantity_change'));
        $matrix = Livewire::test(OpticalCatalogueComponent::class)->set('activeTab', 'lens-matrix')
            ->assertViewHas('matrixTotal', 186)
            ->assertViewHas('lensBlankStock', fn ($stock) => $stock['0.25|0.00'] === 10)
            ->assertViewHas('matrixRowTotals', fn ($totals) => $totals['0.25'] === 46);
        $movement = OpticalProductStockMovement::firstOrFail();
        app(\App\Services\OpticalStockLedgerService::class)->reverse($movement->id);
        $matrix->call('$refresh')->assertViewHas('matrixTotal', 186 - $movement->quantity_change);
    }

    public function test_lens_receiving_requires_manager(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'lens-permission');
        $this->actingAs($user);
        Livewire::test(OpticalStockManagementComponent::class)->set('stockType', 'lens')->call('save')->assertForbidden();
        Livewire::test(OpticalCatalogueComponent::class)->call('receiveLensBlanks')->assertForbidden();
    }

    public function test_optical_category_crud_maps_products_and_fills_order_lens_choices(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $this->tenant($user, 'optical-category-crud');
        $this->actingAs($user);
        Category::create(['user_id' => $user->id, 'name' => 'Single Vision Blue AR', 'type' => 'service', 'is_active' => true]);

        Livewire::test(OpticalCategoriesComponent::class)
            ->call('add')
            ->assertSee('Category Name')
            ->assertSee('Category Code / Slug')
            ->assertSee('Default Price Markup')
            ->assertSee('Status')
            ->assertSee('Category Description & Features')
            ->assertDontSee('Refractive index')
            ->assertDontSee('Item group');

        Livewire::test(OpticalCategoriesComponent::class)
            ->call('add')
            ->set('code', 'CAT-SV-BAR')
            ->set('name', 'Single Vision Blue AR')
            ->set('markup', '40')
            ->set('description', 'Blue AR single vision lenses')
            ->call('save')->assertHasNoErrors();
        $category = OpticalCategory::where('code', 'CAT-SV-BAR')->firstOrFail();
        $this->assertSame('single_vision', $category->group);
        $this->assertSame(1, Category::where('name', 'Single Vision Blue AR')->count());
        Livewire::test(ProductsComponent::class)
            ->set('state.optical_category_id', $category->id)
            ->assertSet('state.optical_category_id', null);

        Livewire::test(OpticalOrderCreateComponent::class)
            ->call('chooseOpticalCategory', $category->id)
            ->assertSet('lens_type', 'Single Vision')
            ->assertSet('lens_index', '1.56')
            ->assertSet('stock_coating', 'AR');

        Livewire::test(ProductsComponent::class)
            ->set('state.name', 'Blue AR Lens Blank')
            ->set('state.batch_number', 'BAR001')
            ->set('state.category_id', Category::where('name', 'Single Vision Blue AR')->firstOrFail()->id)
            ->set('state.manufacture_date', '2026-01-01')
            ->set('state.expiry_date', '2028-01-01')
            ->set('state.quantity', 2)
            ->set('state.cost_price', 20)
            ->set('state.selling_price', 40)
            ->call('createProduct')->assertHasNoErrors();
        $product = Product::where('name', 'Blue AR Lens Blank')->firstOrFail();
        $this->assertNull($product->optical_category_id);
        Livewire::test(OpticalPosComponent::class)->assertDontSee('Blue AR Lens Blank');
        Livewire::test(OpticalCategoriesComponent::class)
            ->assertSee('0 SKUs')
            ->call('edit', $category->id)
            ->set('name', 'Single Vision Blue AR Updated')
            ->call('save')->assertHasNoErrors()
            ->call('toggleActive', $category->id);
        $this->assertFalse($category->fresh()->is_active);
        Livewire::test(OpticalCategoriesComponent::class)
            ->call('delete', $category->id)->assertHasNoErrors();
        $this->assertSoftDeleted('optical_categories', ['id' => $category->id]);
    }

    public function test_optical_product_crud_keeps_clinic_products_separate_and_tracks_branch_stock(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        [$clinic, $branch] = $this->tenant($user, 'optical-product-crud');
        $this->actingAs($user);
        $category = OpticalCategory::create(['code' => 'FRAMES', 'name' => 'Frames', 'is_active' => true]);

        Livewire::test(OpticalProductsComponent::class)
            ->call('add')
            ->set('name', 'Gold frame')
            ->set('sku', 'FRM-001')
            ->set('categoryId', (string) $category->id)
            ->set('brand', 'Acme')
            ->set('costPrice', '100')
            ->set('sellingPrice', '150')
            ->set('quantity', '12')
            ->set('reorderLevel', '3')
            ->call('save')->assertHasNoErrors();

        $product = OpticalProduct::where('sku', 'FRM-001')->firstOrFail();
        $this->assertSame(0, Product::where('name', 'Gold frame')->count());
        $this->assertSame(12, OpticalProductStock::where('optical_product_id', $product->id)->firstOrFail()->quantity);
        $this->assertDatabaseHas('optical_product_stock_movements', ['optical_product_id' => $product->id, 'branch_id' => $branch->id, 'quantity_change' => 12]);
        Livewire::test(OpticalCatalogueComponent::class)->assertSee('Gold frame')->assertSee('FRM-001');
        $lensCategory = OpticalCategory::create(['code' => 'LENSES', 'name' => 'Lenses', 'is_active' => true]);
        $lens = OpticalProduct::create([
            'name' => 'Clear lens', 'sku' => 'LNS-002', 'optical_category_id' => $lensCategory->id,
            'brand' => 'Vista', 'specifications' => 'Blue coating', 'cost_price' => 20,
            'selling_price' => 40, 'is_active' => true,
        ]);
        app(\App\Services\OpticalProductInventoryService::class)->setBalance($lens, 1, 3, 'Initial optical stock');
        Livewire::test(OpticalProductsComponent::class)
            ->assertSee('2 products found')
            ->set('search', 'FRM-001')->assertSee('Gold frame')->assertDontSee('Clear lens')
            ->set('search', 'Blue coating')->assertSee('Clear lens')->assertDontSee('Gold frame')
            ->set('search', '')
            ->set('categoryFilter', (string) $lensCategory->id)->assertSee('Clear lens')->assertDontSee('Gold frame')
            ->set('stockFilter', 'low')->assertSee('Clear lens')
            ->call('clearFilters')->assertSee('Gold frame')->assertSee('Clear lens');

        Livewire::test(OpticalProductsComponent::class)
            ->call('edit', $product->id)
            ->set('quantity', '8')
            ->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('optical_product_stock_movements', ['optical_product_id' => $product->id, 'quantity_change' => -4]);
        Livewire::test(OpticalProductsComponent::class)->call('delete', $product->id)->assertHasErrors(['product']);

        $other = User::factory()->create();
        $this->tenant($other, 'optical-product-other');
        $this->actingAs($other);
        $this->assertNull(OpticalProduct::find($product->id));
        Livewire::test(OpticalProductsComponent::class)->assertDontSee('Gold frame')->call('edit', $product->id)->assertForbidden();
    }

    public function test_optical_product_csv_import_export_and_template_are_tenant_scoped(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        [, $branch] = $this->tenant($user, 'optical-csv');
        $this->actingAs($user);
        OpticalCategory::create(['code' => 'CAT-FRM', 'name' => 'Frames', 'is_active' => true]);
        $service = app(\App\Services\OpticalProductCsvService::class);
        $stream = fopen('php://temp', 'w+');
        $service->writeCsv($stream, true);
        rewind($stream);
        $template = stream_get_contents($stream);
        fclose($stream);
        $this->assertStringContainsString('sku,name,category_code,brand,cost_price,selling_price,quantity,reorder_level,status,specifications', $template);
        Livewire::test(OpticalProductsComponent::class)->call('downloadTemplate')->assertFileDownloaded('optical_products_template.csv');

        $csv = "sku,name,category_code,brand,cost_price,selling_price,quantity,reorder_level,status,specifications\nFRM-100,Gold frame,CAT-FRM,Acme,100,150,12,3,active,Gold metal\n";
        Livewire::test(OpticalProductsComponent::class)
            ->call('openImport')->set('importFile', UploadedFile::fake()->createWithContent('products.csv', $csv))
            ->call('importCsv')->assertHasNoErrors();
        $product = OpticalProduct::where('sku', 'FRM-100')->firstOrFail();
        $this->assertSame(12, OpticalProductStock::where('optical_product_id', $product->id)->firstOrFail()->quantity);

        $invalid = "sku,name,category_code,brand,cost_price,selling_price,quantity,reorder_level,status,specifications\nFRM-101,Silver frame,CAT-FRM,Acme,50,75,5,2,active,Silver\nFRM-102,Bad frame,OTHER,Acme,50,75,5,2,active,Bad\n";
        Livewire::test(OpticalProductsComponent::class)
            ->call('openImport')->set('importFile', UploadedFile::fake()->createWithContent('invalid.csv', $invalid))
            ->call('importCsv')->assertHasErrors(['importFile']);
        $this->assertNull(OpticalProduct::where('sku', 'FRM-101')->first());

        $updated = str_replace('12,3,active', '7,2,inactive', $csv);
        Livewire::test(OpticalProductsComponent::class)
            ->call('openImport')->set('importFile', UploadedFile::fake()->createWithContent('update.csv', $updated))
            ->call('importCsv')->assertHasNoErrors();
        $this->assertSame(1, OpticalProduct::where('sku', 'FRM-100')->count());
        $this->assertFalse($product->fresh()->is_active);
        $this->assertSame(7, OpticalProductStock::where('optical_product_id', $product->id)->firstOrFail()->quantity);
        $this->assertDatabaseHas('optical_product_stock_movements', ['branch_id' => $branch->id, 'optical_product_id' => $product->id, 'quantity_change' => -5]);

        $stream = fopen('php://temp', 'w+');
        $service->writeCsv($stream);
        rewind($stream);
        $export = stream_get_contents($stream);
        fclose($stream);
        $lines = preg_split('/\r?\n/', trim($export));
        $this->assertSame(['FRM-100', 'Gold frame', 'CAT-FRM', 'Acme', '100.00', '150.00', '7', '2', 'inactive', 'Gold metal'], str_getcsv($lines[1]));
        Livewire::test(OpticalProductsComponent::class)->call('exportCsv')->assertFileDownloaded('optical_products_'.now()->format('Y-m-d').'.csv');

        $this->tenant($user, 'optical-csv-other');
        $stream = fopen('php://temp', 'w+');
        $service->writeCsv($stream);
        rewind($stream);
        $otherExport = stream_get_contents($stream);
        fclose($stream);
        $this->assertStringNotContainsString('FRM-100', $otherExport);
    }

    public function test_optical_stock_ledger_receives_adjusts_and_reverses_with_branch_isolation(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        [$clinic, $branch] = $this->tenant($user, 'optical-stock-ledger');
        $this->actingAs($user);
        $category = OpticalCategory::create(['code' => 'CAT-FRM', 'name' => 'Frames', 'is_active' => true]);
        $product = OpticalProduct::create([
            'sku' => 'FRM-500', 'name' => 'Test frame', 'optical_category_id' => $category->id,
            'cost_price' => 100, 'selling_price' => 150, 'is_active' => true,
        ]);
        try {
            app(\App\Services\OpticalStockLedgerService::class)->receive($product, -5, ['unit_cost' => 10, 'unit_price' => 20]);
            $this->fail('Expected ValidationException was not thrown for negative receive quantity.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }
        app(\App\Services\OpticalProductInventoryService::class)->setBalance($product, 5, 3, 'Initial optical stock');

        Livewire::test(OpticalStockManagementComponent::class)
            ->call('openReceipt')
            ->set('productSearch', 'FRM-500')->assertSee('Test frame')
            ->call('selectProduct', $product->id)
            ->set('quantity', '10')->set('unitCost', '110')->set('unitPrice', '170')
            ->set('supplier', 'Optical Supply')->set('batchNumber', 'LOT-1')->set('reference', 'GRN-500')
            ->call('save')->assertHasNoErrors();
        $receipt = OpticalProductStockMovement::where('movement_type', 'receipt')->firstOrFail();
        $this->assertSame(10, $receipt->quantity_change);
        $this->assertSame(15, OpticalProductStock::where('optical_product_id', $product->id)->firstOrFail()->quantity);
        $this->assertSame('150.00', $product->fresh()->selling_price);

        Livewire::test(OpticalStockManagementComponent::class)
            ->set('search', 'GRN-500')->assertSee('Test frame')
            ->call('openAdjustment')->call('selectProduct', $product->id)
            ->set('quantity', '3')->set('adjustmentDirection', 'remove')
            ->set('adjustmentReason', 'Physical count correction')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(12, OpticalProductStock::where('optical_product_id', $product->id)->firstOrFail()->quantity);

        Livewire::test(OpticalStockManagementComponent::class)->call('reverse', $receipt->id)->assertHasNoErrors();
        $this->assertSame(2, OpticalProductStock::where('optical_product_id', $product->id)->firstOrFail()->quantity);
        $this->assertDatabaseHas('optical_product_stock_movements', ['reverses_movement_id' => $receipt->id, 'quantity_change' => -10, 'balance_after' => 2]);
        Livewire::test(OpticalStockManagementComponent::class)->call('reverse', $receipt->id)->assertHasErrors(['movement']);
        $ledger = app(\App\Services\OpticalStockLedgerService::class);
        $laterReceipt = $ledger->receive($product, 4, ['unit_cost' => 100, 'unit_price' => 150]);
        $ledger->adjust($product, -5, 'Damaged frame removed');
        Livewire::test(OpticalStockManagementComponent::class)->call('reverse', $laterReceipt->id)->assertHasErrors(['movement']);
        $this->assertSame(1, OpticalProductStock::where('optical_product_id', $product->id)->firstOrFail()->quantity);

        $otherBranch = $clinic->branches()->create(['code' => 'SECOND', 'name' => 'Second', 'is_default' => false, 'is_active' => true]);
        $otherBranch->users()->attach($user->id, ['status' => 'active', 'is_default' => false]);
        app(TenantContext::class)->set($user, $clinic, $otherBranch, [$branch->id, $otherBranch->id]);
        Livewire::test(OpticalStockManagementComponent::class)->assertDontSee('GRN-500');
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(OpticalStockManagementComponent::class)->call('reverse', $receipt->id);
    }

    public function test_optical_pos_sells_separate_sku_and_receipt_names_it(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $this->tenant($user, 'optical-pos-sku');
        $this->actingAs($user);
        $category = OpticalCategory::create(['code' => 'ACC', 'name' => 'Optical Accessories', 'is_active' => true]);
        $product = OpticalProduct::create([
            'sku' => 'ACC-10', 'name' => 'Cleaning spray', 'optical_category_id' => $category->id,
            'cost_price' => 12, 'selling_price' => 20, 'is_active' => true,
        ]);
        app(\App\Services\OpticalProductInventoryService::class)->setBalance($product, 3, 1, 'Initial optical stock');

        Livewire::test(OpticalPosComponent::class)
            ->set('searchTerm', 'ACC-10')->assertSee('Cleaning spray')
            ->call('addToCart', 'o:'.$product->id)->call('completeSale')->assertHasNoErrors();
        $sale = Sales::where('transaction_id', 'like', 'OPOS-%')->firstOrFail();
        $item = SaleItem::where('sale_id', $sale->id)->firstOrFail();
        $this->assertNull($item->product_id);
        $this->assertSame($product->id, $item->optical_product_id);
        $this->assertSame(2, OpticalProductStock::where('optical_product_id', $product->id)->firstOrFail()->quantity);
        $receipt = app(\App\Http\Controllers\Cashier\ReceiptController::class)
            ->show($sale->id, \Illuminate\Http\Request::create('/optical/receipt/'.$sale->id));
        $this->assertStringContainsString('Cleaning spray', $receipt->render());
        Livewire::test(OpticalReportsComponent::class)->assertSee('Cleaning spray')->assertSee('ACC-10');
    }

    public function test_optical_order_quote_activation_and_cancellation_reserve_optical_sku(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $this->tenant($user, 'optical-order-sku');
        $this->actingAs($user);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Optical Buyer', 'contact' => '0240000400', 'gender' => 'Other',
        ]);
        $category = OpticalCategory::create(['code' => 'FRAMES', 'name' => 'Frames', 'is_active' => true]);
        $frame = OpticalProduct::create([
            'sku' => 'FRM-10', 'name' => 'Blue frame', 'optical_category_id' => $category->id,
            'cost_price' => 80, 'selling_price' => 150, 'is_active' => true,
        ]);
        app(\App\Services\OpticalProductInventoryService::class)->setBalance($frame, 5, 2, 'Initial optical stock');

        Livewire::test(OpticalOrderCreateComponent::class)->call('selectFrameOpticalProduct', $frame->id)
            ->assertSet('frame_optical_product_id', $frame->id)->assertSet('frame_price', '150.00');
        $data = $this->orderData($patient);
        $data['frame_optical_product_id'] = $frame->id;
        $data['frame_price'] = 1;
        $data['docket'] = ['frame_source' => 'stock'];
        $quote = app(OpticalOrderService::class)->create($data, true);
        $this->assertEquals(150.0, $quote->frame_price);
        $this->assertSame(5, OpticalProductStock::where('optical_product_id', $frame->id)->firstOrFail()->quantity);

        $order = app(OpticalOrderWorkflowService::class)->activateQuotation($quote->id);
        $this->assertSame($frame->id, $order->frame_optical_product_id);
        $this->assertSame(4, OpticalProductStock::where('optical_product_id', $frame->id)->firstOrFail()->quantity);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $order->sale_id, 'optical_product_id' => $frame->id, 'product_id' => null]);
        $this->assertStringContainsString('FRM-10', view('optical.order-docket', ['order' => $order])->render());

        app(OpticalOrderWorkflowService::class)->cancel($order->id);
        $this->assertSame(5, OpticalProductStock::where('optical_product_id', $frame->id)->firstOrFail()->quantity);

        $lensCategory = OpticalCategory::create(['code' => 'SINGLE-VISION', 'name' => 'Single Vision Lenses', 'is_active' => true]);
        $lens = OpticalProduct::create([
            'sku' => 'LNS-10', 'name' => 'Clear lens', 'optical_category_id' => $lensCategory->id,
            'cost_price' => 60, 'selling_price' => 120, 'is_active' => true,
        ]);
        app(\App\Services\OpticalProductInventoryService::class)->setBalance($lens, 1, 1, 'Initial optical stock');
        Livewire::test(OpticalOrderCreateComponent::class)
            ->set('currentStep', 2)
            ->assertSee('Use stocked lenses')
            ->assertSee('Special order')
            ->assertSee('Customer supplied')
            ->assertDontSee('Find a lens SKU')
            ->assertDontSee('Optical lens category')
            ->assertDontSee('Refractive index');
        Livewire::test(OpticalOrderCreateComponent::class)->call('selectLensOpticalProduct', $lens->id)
            ->assertSet('lens_optical_product_id', $lens->id)->assertSet('lens_fulfilment_source', 'catalogue');
        $lensData = $this->orderData($patient);
        $lensData['docket'] = ['frame_source' => 'customer'];
        $lensData['lens_optical_product_id'] = $lens->id;
        $lensData['lens_fulfilment_source'] = 'catalogue';
        $lensData['lens_price'] = 1;
        $lensData['paid_amount'] = 0;
        $lensOrder = app(OpticalOrderService::class)->create($lensData);
        $this->assertEquals(120, $lensOrder->lens_price);
        $this->assertSame(0, OpticalProductStock::where('optical_product_id', $lens->id)->firstOrFail()->quantity);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $lensOrder->sale_id, 'optical_product_id' => $lens->id]);
        app(OpticalOrderWorkflowService::class)->cancel($lensOrder->id);
        $this->assertSame(1, OpticalProductStock::where('optical_product_id', $lens->id)->firstOrFail()->quantity);
    }

    public function test_optical_pos_refund_restores_optical_sku_stock(): void
    {
        config(['tenancy.enabled' => true]);
        $manager = User::factory()->create();
        $manager->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $this->tenant($manager, 'optical-pos-refund');
        $this->actingAs($manager);
        $category = OpticalCategory::create(['code' => 'ACC', 'name' => 'Optical Accessories', 'is_active' => true]);
        $product = OpticalProduct::create([
            'sku' => 'ACC-20', 'name' => 'Lens cloth', 'optical_category_id' => $category->id,
            'cost_price' => 5, 'selling_price' => 10, 'is_active' => true,
        ]);
        app(\App\Services\OpticalProductInventoryService::class)->setBalance($product, 3, 1, 'Initial optical stock');
        Livewire::test(OpticalPosComponent::class)->call('addToCart', 'o:'.$product->id)->call('completeSale')->assertHasNoErrors();
        $sale = Sales::where('transaction_id', 'like', 'OPOS-%')->firstOrFail();
        $refund = RefundLog::create([
            'sale_id' => $sale->id, 'request_type' => RefundLog::TYPE_REFUND,
            'reason_code' => 'customer_return', 'status' => RefundLog::STATUS_APPROVED,
            'initiated_by' => $manager->id, 'approved_by' => $manager->id,
            'reason' => 'Customer returned the product.', 'initiated_at' => now(), 'approved_at' => now(),
        ]);
        Livewire::test(RefundApprovalsComponent::class)->call('process', $refund->id)->assertDispatched('refund-receipt-ready');
        $this->assertSame(3, OpticalProductStock::where('optical_product_id', $product->id)->firstOrFail()->quantity);
        $this->assertSame(1, SaleItem::where('sale_id', $sale->id)->firstOrFail()->refunded_quantity);
        $this->assertSame($product->id, $refund->fresh()->stock_restoration[0]['optical_product_id']);
    }

    public function test_optical_categories_are_tenant_scoped_and_staff_cannot_edit_them(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $this->tenant($user, 'optical-category-a');
        $this->actingAs($user);
        $category = OpticalCategory::create([
            'name' => 'Private Optical Frame', 'code' => 'CAT-PRIVATE', 'is_active' => true,
        ]);
        $this->tenant($user, 'optical-category-b');
        $this->assertNull(OpticalCategory::find($category->id));
        Livewire::test(OpticalCategoriesComponent::class)->assertDontSee('Private Optical Frame');

        $staff = User::factory()->create();
        $this->tenant($staff, 'optical-category-staff');
        $this->actingAs($staff);
        Livewire::test(OpticalCategoriesComponent::class)->call('add')->assertForbidden();
    }

    private function opticalManager(string $slug): User
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $user->assignRole($role);
        [, $branch] = $this->tenant($user, $slug);
        DB::table('branch_user_role')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'role_id' => $role->id]);
        $this->actingAs($user);
        return $user;
    }

    public function test_optical_expenses_and_profit_and_loss_are_kept_apart_from_the_clinic(): void
    {
        $manager = $this->opticalManager('optical-profit');
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $manager->id, 'name' => 'Profit Customer', 'contact' => '0240001111', 'gender' => 'Other']);
        app(OpticalOrderService::class)->create($this->orderData($patient)); // 200 custom frame + 100 lenses + 20 glazing − 10 discount, 50 paid

        // A stock lens used on a job, and one lens found missing in a count.
        $specs = ['range' => 'Profit Range', 'design' => 'Single Vision', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65];
        $lens = app(\App\Services\OpticalLensReceivingService::class)->receive($specs + ['sphere' => '0.25', 'power' => '0.00'], 3, ['unit_cost' => 12, 'unit_price' => 30]);
        $measurements = ['od' => ['sph' => '+0.25'], 'os' => ['sph' => '+0.25']];
        $option = app(OpticalLensAvailabilityService::class)->stockOptions($measurements)[0];
        $data = $this->orderData($patient);
        $data['measurements'] = $measurements;
        $data['lens_fulfilment_source'] = 'stock';
        $data['docket'] = ['lens_details' => ['stock_key' => $option['key'], 'stock_split' => $option['split']]];
        app(OpticalOrderService::class)->create($data); // 200 frame + 60 lenses + 20 − 10
        app(\App\Services\OpticalStockLedgerService::class)->countCorrection($lens, -1, ['unit_cost' => 12, 'reference' => 'TEST']);

        Livewire::test(\App\Livewire\Admin\ExpensesComponent::class, ['businessLine' => 'optical'])
            ->assertSee('Optical Expenses')
            ->call('addDefaultCategories')->call('openCreate')
            ->set('state.expense_category_id', (string) \App\Models\ExpenseCategory::where('name', 'Rent')->value('id'))
            ->set('state.description', 'Shop rent')->set('state.amount', '150')->call('save')->assertHasNoErrors()
            ->assertSee('Shop rent')->assertSee('150.00');
        $this->assertSame('optical', \App\Models\Expense::where('description', 'Shop rent')->value('business_line'));
        $clinicExpense = \App\Models\Expense::create(['expense_category_id' => \App\Models\ExpenseCategory::where('name', 'Rent')->value('id'),
            'expense_date' => now()->toDateString(), 'description' => 'Clinic rent', 'amount' => 999, 'recorded_by' => $manager->id]);
        $this->assertSame('clinic', $clinicExpense->fresh()->business_line);
        $this->assertSame(['Clinic rent'], \App\Models\Expense::businessLine(\App\Models\Expense::CLINIC)->pluck('description')->all(), 'Clinic screens never see optical expenses.');

        $pl = app(\App\Services\OpticalProfitService::class)->statement(now()->startOfMonth(), now());
        $this->assertSame(580.0, $pl['totalRevenue']);
        $this->assertSame(24.0, $pl['costs']['Stock lenses used'], 'Two stock lenses at cost.');
        $this->assertSame(2, $pl['uncostedFrames']);
        $this->assertSame(556.0, $pl['grossProfit']);
        $this->assertSame(12.0, $pl['losses']['Stock count differences']);
        $this->assertSame(['Rent' => 150.0], $pl['operating'], 'Only optical expenses.');
        $this->assertSame(394.0, $pl['netProfit']);
        $this->assertSame(100.0, $pl['cash']['received']);

        $this->assertSame(1, $pl['uncostedLenses'], 'The custom-lens job has no lens cost; the stock-lens job does.');

        $this->get(route('optical.profit'))->assertOk()->assertSee('Optical Profit &amp; Loss', false)->assertSee('394.00')
            ->assertSee('1 job charged for lenses with no lens cost recorded')->assertSee('% of revenue')->assertSee('Change')
            ->assertSee(e(route('optical.expenses', ['fromDate' => now()->startOfMonth()->toDateString(), 'toDate' => now()->toDateString(), 'categoryId' => \App\Models\ExpenseCategory::where('name', 'Rent')->value('id')])), false);
        Livewire::test(\App\Livewire\Optical\OpticalProfitComponent::class)->set('compare', 'months')
            ->assertSee(now()->subMonthsNoOverflow(2)->format('M Y'))->assertSee(now()->subMonthNoOverflow()->format('M Y'))->assertDontSee('Change</th>', false)
            ->set('compare', 'none')->assertDontSee('Change</th>', false)
            ->set('compare', 'bogus')->assertSet('compare', 'previous');

        // Like-for-like comparisons.
        $previous = fn ($from, $to) => array_map(fn ($date) => $date->toDateString(), \App\Livewire\Optical\OpticalProfitComponent::previousPeriod(\Illuminate\Support\Carbon::parse($from), \Illuminate\Support\Carbon::parse($to)));
        $this->assertSame(['2026-08-01', '2026-08-27'], $previous('2026-09-01', '2026-09-27'), 'Month to date: the same days last month.');
        $this->assertSame(['2026-02-01', '2026-02-28'], $previous('2026-03-01', '2026-03-31'), 'A whole month: the month before.');
        $this->assertSame(['2026-02-01', '2026-02-28'], $previous('2026-03-01', '2026-03-30'), 'Clipped to the end of a shorter month.');
        $this->assertSame(['2026-04-01', '2026-06-30'], $previous('2026-07-01', '2026-09-30'), 'A quarter: the quarter before.');
        $this->assertSame(['2025-01-01', '2025-09-27'], $previous('2026-01-01', '2026-09-27'), 'Year to date: the same dates last year.');
        $this->assertSame(['2026-09-03', '2026-09-09'], $previous('2026-09-10', '2026-09-16'), 'Any other range: the same number of days before.');
        $this->get(route('optical.expenses'))->assertOk()->assertSee('Optical Expenses')->assertSee('Shop rent')->assertDontSee('Clinic rent');
        $this->get(route('optical.expenses', ['fromDate' => now()->subYear()->toDateString()]))->assertOk()->assertSee('Shop rent');
        $this->get(route('optical.expenses.receipt', $clinicExpense))->assertNotFound();
        $staff = User::factory()->create();
        $this->actingAs($staff)->get(route('optical.profit'))->assertForbidden();
    }

    public function test_expenses_record_payee_and_method_and_repeating_bills_come_due(): void
    {
        $manager = $this->opticalManager('optical-expense-repeat');
        $rent = \App\Models\ExpenseCategory::create(['name' => 'Rent', 'section' => 'operating_expense', 'is_active' => true, 'color' => '#0f766e']);
        $page = Livewire::test(\App\Livewire\Admin\ExpensesComponent::class, ['businessLine' => 'optical'])
            ->assertSee('No expenses recorded in these dates');

        // A monthly rent paid in cash from the till, set to repeat.
        $page->call('openCreate')->set('state.amount', '1500')->set('state.payee', 'Mr Mensah (landlord)')
            ->set('state.expense_category_id', (string) $rent->id)->set('state.description', 'Shop rent')
            ->set('state.payment_method', 'cash')->set('state.repeat', true)->set('state.frequency', 'monthly')
            ->call('save')->assertHasNoErrors()->assertSet('showModal', false)
            ->assertSee('Mr Mensah (landlord)')->assertSee('Cash from till')->assertSee('Biggest cost');
        $expense = \App\Models\Expense::where('description', 'Shop rent')->sole();
        $this->assertSame('cash', $expense->payment_method);
        $recurring = \App\Models\RecurringExpense::sole();
        $this->assertSame($recurring->id, $expense->recurring_expense_id);
        $this->assertSame(today()->addMonthNoOverflow()->toDateString(), $recurring->next_due_date->toDateString());
        $page->call('openCreate')->set('state.amount', '20')->set('state.description', 'Bad method')->set('state.payment_method', 'crypto')
            ->call('save')->assertHasErrors('state.payment_method')->set('showModal', false);

        // When it falls due it shows at the top; recording can change the amount and moves it on a month.
        $recurring->update(['next_due_date' => today()->subDay()]);
        $page->call('$refresh')->assertSee('Repeating expenses due')->assertSee('Overdue since')
            ->call('recordRecurring', $recurring->id)->assertSet('state.payee', 'Mr Mensah (landlord)')->assertSet('state.expense_date', today()->subDay()->toDateString())
            ->set('state.amount', '1600')->call('save')->assertHasNoErrors()->assertDontSee('Repeating expenses due');
        $this->assertSame(today()->subDay()->addMonthNoOverflow()->toDateString(), $recurring->fresh()->next_due_date->toDateString());
        $this->assertEquals(1600, \App\Models\Expense::where('recurring_expense_id', $recurring->id)->latest('id')->value('amount'));
        $this->assertSame(1, \App\Models\RecurringExpense::count(), 'Recording a due one does not start another schedule.');

        // Skip moves it on without recording; Stop ends it.
        $recurring->update(['next_due_date' => today()]);
        $page->call('skipRecurring', $recurring->id);
        $this->assertSame(today()->addMonthNoOverflow()->toDateString(), $recurring->fresh()->next_due_date->toDateString());
        $this->assertSame(2, \App\Models\Expense::count());
        $page->call('stopRecurring', $recurring->id);
        $this->assertFalse($recurring->fresh()->is_active);

        // Receipts missing, and search by who was paid.
        $page->set('receipt', 'missing')->assertSee('Shop rent')->set('search', 'Mensah')->assertSee('Shop rent')->set('search', 'nobody')->assertViewHas('expenses', fn ($expenses) => $expenses->total() === 0);

        // Cash paid out of the till comes off the cash expected at the end of the day.
        $cash = app(\App\Services\OpticalReportService::class)->cash(today()->subDay(), today());
        $this->assertEquals(3100, $cash['cashPaidOut']);
        $this->assertEquals(-3100, $cash['cashExpected']);

        // Clinic expenses never see optical repeating bills.
        $this->assertSame(0, \App\Models\RecurringExpense::businessLine('clinic')->count());
    }

    public function test_expense_form_saves_in_one_call_from_the_browser(): void
    {
        $this->opticalManager('optical-expense-one-call');
        $page = Livewire::test(\App\Livewire\Admin\ExpensesComponent::class, ['businessLine' => 'optical'])
            ->assertDontSee('Shop rent');

        // Invalid: nothing saved, the form stays open with its errors.
        $page->call('saveExpense', ['amount' => '', 'description' => ''])->assertHasErrors(['state.amount', 'state.description']);
        $this->assertSame(0, \App\Models\Expense::count());

        // New, repeating monthly.
        $page->call('saveExpense', ['expense_date' => today()->toDateString(), 'amount' => '900', 'payee' => 'Landlord', 'description' => 'Shop rent',
            'payment_method' => 'bank_transfer', 'repeat' => true, 'frequency' => 'monthly'])->assertHasNoErrors()->assertReturned(true)
            ->assertSee('Shop rent')->assertSee('Bank transfer')
            // The row carries its details, so opening it in the browser needs no server call.
            ->assertSee('data-expense="{&quot;id&quot;:', false);
        $expense = \App\Models\Expense::sole();
        $this->assertSame(1, \App\Models\RecurringExpense::count());

        // Edit: changes this expense only, never starts a schedule, and ignores a recurring id.
        $page->call('saveExpense', ['expense_date' => today()->toDateString(), 'amount' => '950', 'payee' => 'Landlord', 'description' => 'Shop rent (Sept)',
            'payment_method' => 'cash', 'repeat' => true], $expense->id, \App\Models\RecurringExpense::sole()->id)->assertReturned(true);
        $this->assertEquals(950, $expense->fresh()->amount);
        $this->assertSame('cash', $expense->fresh()->payment_method);
        $this->assertSame(1, \App\Models\Expense::count());
        $this->assertSame(1, \App\Models\RecurringExpense::count());

        // Recording a due repeating bill moves it on.
        $recurring = \App\Models\RecurringExpense::sole();
        $recurring->update(['next_due_date' => today()]);
        $page->call('saveExpense', ['expense_date' => today()->toDateString(), 'amount' => '900', 'description' => 'Shop rent'], null, $recurring->id)->assertReturned(true);
        $this->assertSame(today()->addMonthNoOverflow()->toDateString(), $recurring->fresh()->next_due_date->toDateString());
        $this->assertSame(2, \App\Models\Expense::where('recurring_expense_id', $recurring->id)->count());
    }

    private function opticalStaff(string $slug, string $roleName): User
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $role = Role::where('name', $roleName)->firstOrFail();
        $user->assignRole($role);
        [, $branch] = $this->tenant($user, $slug);
        DB::table('branch_user_role')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'role_id' => $role->id]);
        // A fresh session, so a previous person's clinic in the same test is not carried over.
        $this->flushSession();
        $this->actingAs($user);
        return $user;
    }

    public function test_optical_menu_opens_pages_without_a_full_reload(): void
    {
        $this->opticalStaff('optical-assistant', 'Optical Assistant');
        $html = $this->get(route('optical.dashboard'))->assertOk()->getContent();

        // Menu pages use wire:navigate; Sales Records (shared script, not navigate-safe yet) loads normally.
        $this->assertMatchesRegularExpression('#<a href="' . preg_quote(route('optical.orders'), '#') . '"\s+wire:navigate#', $html);
        $this->assertDoesNotMatchRegularExpression('#<a href="' . preg_quote(route('optical.sales'), '#') . '"\s+wire:navigate#', $html);
        // The confirm helpers are defined once, not again on every page change.
        $this->assertStringContainsString('<script data-navigate-once>', $html);
    }

    public function test_optical_roles_open_only_their_screens(): void
    {
        // The sales desk: orders, POS and collection, but not the lab, reports or management.
        $this->opticalStaff('optical-assistant', 'Optical Assistant');
        foreach (['optical.dashboard', 'optical.orders', 'optical.orders.create', 'optical.pos', 'optical.collections', 'optical.sales', 'optical.prescriptions', 'optical.partners', 'optical.catalogue'] as $route) {
            $this->assertSame(200, $this->get(route($route))->status(), $route);
        }
        foreach (['optical.lab-workbench', 'optical.stock-counts', 'optical.reports', 'optical.purchasing', 'optical.expenses', 'optical.profit', 'optical.settings'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('optical.dashboard'))->assertSee('Retail POS')->assertDontSee('Lab Workbench')->assertDontSee('Staff &amp; roles', false);
        $this->get(route('admin.users'))->assertForbidden();

        // The lab: workbench, job tracking and stock, but not the till.
        $this->opticalStaff('optical-lab-tech', 'Lab Technician');
        foreach (['optical.lab-workbench', 'optical.jobs', 'optical.catalogue', 'optical.stock-counts'] as $route) {
            $this->get(route($route))->assertOk();
        }
        foreach (['optical.pos', 'optical.orders.create', 'optical.sales', 'optical.reports', 'optical.purchasing'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->assertSame('optical.lab-workbench', Role::where('name', 'Lab Technician')->value('dashboard_route'));

        // An Optician opens everything except management.
        $this->opticalStaff('optical-optician', 'Optician');
        foreach (['optical.orders', 'optical.lab-workbench', 'optical.pos', 'optical.reports', 'optical.stock-counts'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('optical.purchasing'))->assertForbidden();
    }

    public function test_managers_add_staff_but_only_super_admins_hand_out_super_admin(): void
    {
        $manager = $this->opticalManager('optical-staff-admin');
        $this->get(route('optical.dashboard'))->assertSee('Staff &amp; roles', false)->assertSee(e(route('admin.users', ['from' => 'optical'])), false);
        $this->get(route('admin.users', ['from' => 'optical']))->assertOk()->assertSee('Back to Optical');
        $branchId = app(TenantContext::class)->branchId();

        $page = Livewire::test(\App\Livewire\Admin\UserRoleManagerComponent::class)
            ->assertDontSee('<option value="Super Admin">', false)
            ->call('create')->assertSee('Lab Technician')->assertSee('The lab: workbench');
        // Giving someone the Super Admin role is refused.
        $page->call('create')->set('name', 'Would Be Owner')->set('email', 'owner@example.test')
            ->set('password', 'secret123')->set('password_confirmation', 'secret123')
            ->set('selectedBranchIds', [$branchId])->set('defaultBranchId', $branchId)
            ->set('roleAssignmentMode', 'shared')->set('selectedRoles', ['Super Admin'])
            ->call('store')->assertHasErrors('selectedRoles');
        $this->assertDatabaseMissing('users', ['email' => 'owner@example.test']);
        // An optical role is fine.
        $page->set('selectedRoles', ['Lab Technician'])->call('store')->assertHasNoErrors();
        $this->assertTrue(User::where('email', 'owner@example.test')->firstOrFail()->hasRole('Lab Technician'));

        // A Super Admin's account is out of a manager's reach.
        $owner = User::factory()->create();
        $owner->assignRole('Super Admin');
        app(TenantContext::class)->clinic()->users()->attach($owner->id, ['status' => 'active', 'is_default' => true]);
        Livewire::test(\App\Livewire\Admin\UserRoleManagerComponent::class)->call('edit', $owner->id)->assertForbidden();
        Livewire::test(\App\Livewire\Admin\UserRoleManagerComponent::class)->call('openResetPassword', $owner->id)->assertForbidden();
    }

    public function test_panels_close_in_the_browser_and_the_server_catches_up(): void
    {
        $this->opticalManager('optical-local-close');

        // Purchasing ids are locked, so the browser asks these methods to close (the old $set was refused).
        Livewire::test(\App\Livewire\Optical\OpticalPurchasingComponent::class)
            ->call('closeOrder')->assertSet('viewOrderId', null)->assertHasNoErrors()
            ->call('cancelSettle')->assertSet('settleId', null);
        $this->expectsLockedUpdate(fn () => Livewire::test(\App\Livewire\Optical\OpticalPurchasingComponent::class)->set('viewOrderId', 5));

        // The order panel closes in the browser; when the server hears of it, stale errors go.
        $orders =Livewire::test(\App\Livewire\Optical\OpticalOrdersComponent::class);
        $orders->instance()->addError('order', 'Old problem');
        $orders->set('viewOrderId', 12)->set('viewOrderId', null)->assertHasNoErrors();

        // Lens price form: closing in the browser clears what was being edited.
        Livewire::test(\App\Livewire\Optical\LensPriceListComponent::class)
            ->set('pairPrice', '99')->set('editingKey', 'x')->set('editingKey', null)->assertSet('pairPrice', '')->assertSet('rules', []);

        // Awaiting Collection filters in the browser: the page carries every job and what the filters read.
        Livewire::test(\App\Livewire\Optical\OpticalCollectionsComponent::class)
            ->assertSee('awaitingFilter()', false)->assertSee('x-model="search"', false)->assertDontSee('wire:model.live', false);

        // Blank forms open in the browser: drawn with the page, and the header buttons make no call.
        Livewire::test(\App\Livewire\Optical\PartnerClinicsComponent::class)
            ->assertSee('data-partner-form', false)->assertDontSee('wire:click="add"', false);
        Livewire::test(\App\Livewire\Optical\OpticalPurchasingComponent::class)
            ->assertSee('data-order-form', false)->assertSee('data-return-form', false)
            ->assertDontSee('wire:click="openOrderForm"', false)->assertDontSee('wire:click="openReturnForm"', false);

        // Forms that open in the browser are drawn ready, and their buttons fill them without a call.
        Livewire::test(\App\Livewire\Optical\OpticalCategoriesComponent::class)
            ->assertSee('data-category-form', false)->assertSee('openLocal($wire', false)->assertDontSee('wire:click="edit(', false);
        Livewire::test(\App\Livewire\Optical\OpticalCatalogueComponent::class)->call('setTab', 'services')
            ->assertSee('data-service-form', false)->assertDontSee('wire:click="editService(', false);
    }

    private function expectsLockedUpdate(callable $callback): void
    {
        try {
            $callback();
            $this->fail('A locked property was changed from the browser.');
        } catch (\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_optical_period_lock_freezes_its_expenses_and_the_statement_exports(): void
    {
        $manager = $this->opticalManager('optical-period-lock');
        $category = \App\Models\ExpenseCategory::create(['name' => 'Rent', 'section' => 'operating_expense', 'is_active' => true]);
        $from = now()->subMonthNoOverflow()->startOfMonth();
        $to = now()->subMonthNoOverflow()->endOfMonth();
        $expense = \App\Models\Expense::create(['expense_category_id' => $category->id, 'expense_date' => $from->copy()->addDays(4)->toDateString(),
            'description' => 'Shop rent', 'amount' => 300, 'recorded_by' => $manager->id, 'business_line' => 'optical']);

        // A period that has not ended yet cannot be closed.
        Livewire::test(\App\Livewire\Optical\OpticalProfitComponent::class)->assertDontSee('Lock period')
            ->call('lockPeriod')->assertStatus(422);

        Livewire::test(\App\Livewire\Optical\OpticalProfitComponent::class)
            ->set('from', $from->toDateString())->set('to', $to->toDateString())
            ->set('lockNotes', 'Reviewed with accountant')->call('lockPeriod')
            ->assertSee('Period locked')->assertSee('Reviewed with accountant');
        $lock = \App\Models\IncomeStatementPeriodLock::sole();
        $this->assertSame('optical', $lock->business_line);
        $this->assertEquals(-300, $lock->snapshot['netProfit']);
        $locks = app(\App\Services\Finance\PeriodLockService::class);
        $this->assertNull($locks->find('clinic', $from, $to), 'The clinic books stay open.');

        // Expenses in the closed period cannot be changed, added or deleted; later ones can.
        $page = Livewire::test(\App\Livewire\Admin\ExpensesComponent::class, ['businessLine' => 'optical']);
        $page->call('openEdit', $expense->id)->set('state.amount', '999')->call('save')->assertHasErrors('state.expense_date');
        $page->call('deleteExpense', $expense->id);
        $page->call('openCreate')->set('state.description', 'Late bill')->set('state.amount', '50')
            ->set('state.expense_date', $to->toDateString())->call('save')->assertHasErrors('state.expense_date');
        $page->set('state.expense_date', now()->toDateString())->call('save')->assertHasNoErrors();
        $this->assertEquals(300, $expense->fresh()->amount);
        $this->assertNotSoftDeleted($expense);

        $csv = $this->get(route('optical.profit.export', ['format' => 'csv', 'from' => $from->toDateString(), 'to' => $to->toDateString()]))
            ->assertOk()->assertDownload('optical-profit-and-loss-'.$from->toDateString().'-to-'.$to->toDateString().'.csv')->streamedContent();
        $this->assertStringContainsString('Optical Profit & Loss', $csv);
        $this->assertStringContainsString('Rent,300.00', $csv);
        $this->assertStringContainsString('"Net profit",-300.00', $csv);
        $this->assertStringContainsString('Period locked', $csv);
        $this->get(route('optical.profit.export', ['format' => 'pdf', 'from' => $from->toDateString(), 'to' => $to->toDateString()]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        Livewire::test(\App\Livewire\Optical\OpticalProfitComponent::class)
            ->set('from', $from->toDateString())->set('to', $to->toDateString())->call('unlockPeriod')->assertSee('Lock period');
        $this->assertSame(0, \App\Models\IncomeStatementPeriodLock::count());
        $page->call('openEdit', $expense->id)->set('state.amount', '320')->call('save')->assertHasNoErrors();
        $this->assertEquals(320, $expense->fresh()->amount);

        // An optical-only subscriber has one set of books: no switcher, no combined view.
        Livewire::test(\App\Livewire\Optical\OpticalProfitComponent::class)->assertDontSee('Combined');
        $this->get(route('admin.combined-statement'))->assertForbidden();
        // Clinic-only finance pages send them to the optical page that does the same job.
        $this->get(route('admin.reports'))->assertRedirect(route('optical.reports'));
        // Even when the plan has no advanced_reports / expense_tracking (this one is optical only).
        $this->get(route('admin.income-statement'))->assertRedirect(route('optical.profit'));
        $this->get(route('admin.expenses'))->assertRedirect(route('optical.expenses'));
        \Livewire\Livewire::withQueryParams(['line' => 'clinic'])->test(\App\Livewire\Admin\AdminDashboardComponent::class)
            ->assertSet('lineOptions', ['optical'])->assertSet('line', 'optical');
        // Clinic-only pages (cash summary, patient ledger...) send them to the optical dashboard; the admin menu points at optical pages.
        $this->get(route('admin.daily-cash-summary'))->assertRedirect(route('optical.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk()->assertSee(route('optical.profit'))
            ->assertDontSee(route('admin.patient-ledger'))->assertDontSee(route('admin.daily-cash-summary'))->assertDontSee(route('admin.quotations'));

        $this->actingAs(User::factory()->create())->get(route('optical.profit.export', ['format' => 'csv']))->assertForbidden();
    }

    public function test_partner_account_statement_and_one_payment_settling_many_jobs(): void
    {
        $this->opticalManager('optical-partner-account');
        $partner = OpticalPartnerClinic::create(['name' => 'Account Eye Clinic', 'billing_terms' => 'on_account', 'phone' => '0244555666', 'is_active' => true]);
        $service = $this->pricedService('cleaning_acc', 40);
        $job = fn (string $billTo = 'partner', string $wearer = 'Wearer') => app(OpticalOrderService::class)->create([
            'order_source' => 'partner', 'partner_id' => $partner->id, 'bill_to' => $billTo, 'customer_name' => $wearer,
            'work_type' => 'service', 'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'frame_price' => 0, 'lens_price' => 0, 'glazing_fee' => 0, 'discount_amount' => 0,
            'paid_amount' => 0, 'payment_method' => 'cash', 'pickup_date' => now()->addWeek()->toDateString(),
        ]);
        $first = $job('partner', 'Oldest');
        $first->forceFill(['created_at' => now()->subDays(45)])->save();
        $second = $job('partner', 'Middle');
        $third = $job('partner', 'Newest');
        $job('customer', 'Pays own bill'); // not on the partner's account
        $accounts = app(\App\Services\OpticalPartnerAccountService::class);

        $this->assertSame(120.0, $accounts->balance($partner));
        $this->assertSame(['0_30' => 80.0, '31_60' => 40.0, '61_90' => 0.0, '90_plus' => 0.0], $accounts->aging($partner));

        try {
            $accounts->recordPayment($partner, 500, 'bank_transfer');
            $this->fail('Cannot take more than is owed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('paymentAmount', $exception->errors());
        }

        // One payment, settled oldest first.
        $page = Livewire::test(\App\Livewire\Optical\PartnerStatementComponent::class, ['partner' => $partner->id])
            ->assertSee('GH₵ 120.00')
            ->call('openPaymentForm')->set('paymentAmount', '60')->set('paymentMethod', 'bank_transfer')->set('paymentReference', 'GCB-991')
            ->call('recordPayment')->assertHasNoErrors();
        $payment = \App\Models\OpticalPartnerPayment::with('allocations')->firstOrFail();
        $this->assertSame([$first->id => 40.0, $second->id => 20.0], $payment->allocations->mapWithKeys(fn ($a) => [$a->lens_order_id => (float) $a->amount])->all());
        $this->assertEquals(40, $first->fresh()->paid_amount);
        $this->assertStringContainsString($payment->receipt_number, PaymentTransaction::find($payment->allocations->first()->payment_transaction_id)->notes);
        $this->assertSame(60.0, $accounts->balance($partner));

        // Choosing the jobs: amounts must add up to the payment.
        $page->call('openPaymentForm')->set('paymentAmount', '50')->set('allocateManually', true)
            ->assertSet("allocations.{$second->id}", '20.00')->assertSet("allocations.{$third->id}", '30.00')
            ->set("allocations.{$second->id}", '0')->call('recordPayment')->assertHasErrors(['allocations'])
            ->set("allocations.{$third->id}", '40')->set("allocations.{$second->id}", '10')->call('recordPayment')->assertHasNoErrors();
        $this->assertSame([10.0, 0.0], [$accounts->balanceOf($second->fresh()), $accounts->balanceOf($third->fresh())]);

        // Statement for the last 30 days: the older job is in the opening balance, bulk payments are one line each.
        $statement = $accounts->statement($partner, now()->subDays(30), now());
        $this->assertSame(40.0, $statement['opening']);
        $this->assertSame(80.0, $statement['charges']);
        $this->assertSame(110.0, $statement['payments']);
        $this->assertSame(10.0, $statement['closing']);
        $this->assertSame(['charge', 'charge', 'payment', 'payment'], $statement['lines']->pluck('type')->all());
        $this->assertStringContainsString('GCB-991', $statement['lines'][2]['description']);

        $this->get(route('optical.partners.statement.print', ['partner' => $partner->id, 'from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()->assertSee('Statement of account')->assertSee('Account Eye Clinic')->assertSee('GH₵ 10.00');
        Livewire::test(\App\Livewire\Optical\PartnerClinicsComponent::class)->assertSee('10.00')->assertSee(route('optical.partners.statement', $partner->id), false);
    }

    public function test_supplier_order_is_received_in_parts_and_stock_can_be_returned(): void
    {
        $this->opticalManager('optical-purchasing');
        $supplier = \App\Models\Supplier::create(['name' => 'Lens Wholesale Ltd', 'phone' => '0244000111', 'lead_time_days' => 5, 'is_active' => true]);
        $product = app(\App\Services\OpticalLensReceivingService::class)->receive(
            ['range' => 'PO Range', 'design' => 'Single Vision', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65, 'sphere' => '-1.00', 'power' => '0.00'],
            1, ['unit_cost' => 8, 'unit_price' => 25]);
        $stock = fn () => (int) OpticalProductStock::where('optical_product_id', $product->id)->value('quantity');

        $page = Livewire::test(\App\Livewire\Optical\OpticalPurchasingComponent::class)
            ->call('openOrderForm')->set('supplierId', $supplier->id)
            ->call('addDraftProduct', $product->id)->set('draftLines.0.quantity', 5)->set('draftLines.0.unit_cost', '9.50')
            ->call('saveDraft', true)->assertHasNoErrors();
        $order = \App\Models\OpticalPurchaseOrder::firstOrFail();
        $this->assertSame('ordered', $order->status);
        $this->assertSame(now()->addDays(5)->toDateString(), $order->expected_date->toDateString(), 'Defaults to the supplier lead time.');
        $line = $order->lines()->firstOrFail();

        $page->call('viewOrder', $order->id)->set("receiveQty.{$line->id}", '6')->call('receiveOrder')->assertHasErrors(['receive'])
            ->set("receiveQty.{$line->id}", '3')->set('invoiceReference', 'INV-77')->call('receiveOrder')->assertHasNoErrors();
        $this->assertSame(4, $stock());
        $this->assertSame('partially_received', $order->fresh()->status);
        $movement = OpticalProductStockMovement::where('optical_purchase_order_line_id', $line->id)->firstOrFail();
        $this->assertEquals(9.5, $movement->unit_cost);
        $this->assertStringContainsString('INV-77', $movement->reference);
        try {
            app(\App\Services\OpticalStockLedgerService::class)->reverse($movement->id);
            $this->fail('Receipts on a supplier order are not reversed on their own.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('movement', $exception->errors());
        }
        $page->call('viewOrder', $order->id)->call('receiveOrder')->assertHasNoErrors();
        $this->assertSame(6, $stock());
        $this->assertSame('received', $order->fresh()->status);

        // Two lenses arrived scratched: back to the supplier, credit tracked.
        $page->call('openReturnForm', $order->id)->assertSet('returnSupplierId', $supplier->id)
            ->set('returnReason', 'damaged')->set('returnLines.0.quantity', 2)->call('saveReturn')->assertHasNoErrors();
        $return = \App\Models\OpticalSupplierReturn::firstOrFail();
        $this->assertSame(4, $stock());
        $this->assertEquals(19, $return->credit_expected);
        $this->assertSame($order->id, $return->optical_purchase_order_id);
        $this->assertSame(-2, (int) OpticalProductStockMovement::where('optical_supplier_return_id', $return->id)->value('quantity_change'));
        $page->call('openSettle', $return->id)->set('settleStatus', 'credited')->set('settleReference', 'CN-12')->call('settleReturn')->assertHasNoErrors();
        $this->assertSame('credited', $return->fresh()->credit_status);

        try {
            app(\App\Services\OpticalSupplierReturnService::class)->create($supplier->id, 'excess', [['product_id' => $product->id, 'quantity' => 50]]);
            $this->fail('Cannot return more than is in stock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('returnLines', $exception->errors());
        }
        $this->assertSame(4, $stock());
        $this->get(route('optical.purchasing.print', $order->id))->assertOk()->assertSee($order->po_number)->assertSee('Lens Wholesale Ltd');
        $this->get(route('optical.purchasing'))->assertOk()->assertSee('Purchasing');
    }

    public function test_special_order_lenses_are_bought_on_a_supplier_order_and_released_to_the_job(): void
    {
        $user = $this->opticalManager('optical-special-po');
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Special Lens Customer', 'contact' => '0240000999', 'gender' => 'Other']);
        $specs = ['range' => 'Special Range', 'design' => 'Single Vision', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65];
        $receiving = app(\App\Services\OpticalLensReceivingService::class);
        $receiving->receive($specs + ['sphere' => '0.25', 'power' => '0.00'], 1, ['unit_cost' => 10, 'unit_price' => 30]);
        $measurements = ['od' => ['sph' => '+0.25'], 'os' => ['sph' => '-0.50']];
        $option = app(OpticalLensAvailabilityService::class)->stockOptions($measurements)[0];
        $data = $this->orderData($patient);
        $data['measurements'] = $measurements;
        $data['lens_fulfilment_source'] = 'stock';
        $data['docket'] = ['lens_details' => ['stock_key' => $option['key'], 'stock_split' => $option['split'], 'type' => 'Single Vision', 'index' => '1.56', 'stock_coating' => 'AR']];
        $job = app(OpticalOrderService::class)->create($data);
        $outside = app(OpticalOrderService::class)->create($this->orderData($patient)); // whole pair special order
        $supplier = \App\Models\Supplier::create(['name' => 'Rx Lab', 'is_active' => true]);

        $backlog = app(\App\Services\OpticalPurchasingService::class)->specialOrderBacklog();
        $this->assertSame([[$job->id, 'os'], [$outside->id, null]], $backlog->map(fn ($row) => [$row['order']->id, $row['eye']])->all());
        $this->assertStringContainsString('OS SPH -0.50', $backlog->first()['description']);

        $page = Livewire::test(\App\Livewire\Optical\OpticalPurchasingComponent::class)->set('tab', 'special')
            ->assertSee($job->order_id)
            ->set('backlogSelected', ["{$job->id}|os", "{$outside->id}|"])->set('backlogSupplierId', $supplier->id)
            ->set("backlogCosts.{$job->id}|os", '40')->call('orderBacklog')->assertHasNoErrors();
        $this->assertSame(0, app(\App\Services\OpticalPurchasingService::class)->specialOrderBacklog()->count());
        $order = \App\Models\OpticalPurchaseOrder::with('lines')->firstOrFail();
        $this->assertSame([1, 2], $order->lines->pluck('quantity_ordered')->all());
        app(\App\Services\OpticalPurchasingService::class)->place($order->id);

        $workflow = app(OpticalOrderWorkflowService::class);
        $this->expectsValidationOn(fn () => $workflow->transition($job->id, 'In Production'));
        $page->set('tab', 'orders')->call('viewOrder', $order->id)->call('receiveOrder')->assertHasNoErrors();
        $this->assertSame('received', $job->lensLines()->where('eye', 'os')->value('status'));
        $this->assertSame(0, (int) OpticalProductStockMovement::whereNotNull('optical_purchase_order_line_id')->count(), 'Special-order lenses go to the job, not stock.');
        $workflow->transition($job->id, 'In Production');
        $this->assertSame('In Production', $job->fresh()->status);
    }

    private function expectsValidationOn(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a validation error.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_stock_count_is_blind_for_staff_and_manager_approves_the_differences(): void
    {
        $manager = $this->opticalManager('optical-counts');
        $specs = ['range' => 'Count Range', 'design' => 'Single Vision', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65];
        $receiving = app(\App\Services\OpticalLensReceivingService::class);
        $a = $receiving->receive($specs + ['sphere' => '-1.00', 'power' => '0.00'], 5, ['unit_cost' => 10, 'unit_price' => 30]);
        $b = $receiving->receive($specs + ['sphere' => '-1.25', 'power' => '0.00'], 3, ['unit_cost' => 10, 'unit_price' => 30]);
        $c = $receiving->receive($specs + ['sphere' => '-1.50', 'power' => '-0.50'], 2, ['unit_cost' => 10, 'unit_price' => 30]);
        $receiving->receive(['range' => 'Other Range'] + $specs + ['sphere' => '0.00', 'power' => '0.00'], 9, ['unit_cost' => 10, 'unit_price' => 30]);
        $stock = fn ($product) => (int) OpticalProductStock::where('optical_product_id', $product->id)->value('quantity');

        $service = app(\App\Services\OpticalStockCountService::class);
        $rangeIndex = $service->lensRanges()->search(fn ($range) => $range['range'] === 'Count Range');
        $page = Livewire::test(\App\Livewire\Optical\OpticalStockCountsComponent::class)
            ->call('openStartForm')->set('countScope', 'lens_range')->set('countRange', (string) $rangeIndex)
            ->call('startCount')->assertHasNoErrors();
        $count = \App\Models\OpticalStockCount::with('lines')->firstOrFail();
        $this->assertCount(3, $count->lines, 'Only the chosen range is counted.');
        $lineFor = fn ($product) => $count->lines->firstWhere('optical_product_id', $product->id);
        $page->assertViewHas('grid', fn ($grid) => $grid['spheres']->count() === 3 && $grid['powerLabel'] === 'CYL');

        // Stock moves while the count is open: flagged for rechecking.
        app(\App\Services\OpticalStockLedgerService::class)->adjust($b, -1, 'Sold during count');
        $this->assertSame([$b->id => -1], $service->movedSinceStart($count)->all());
        app(\App\Services\OpticalStockLedgerService::class)->adjust($b, 1, 'Correction');

        // Staff count blind and submit.
        $staff = User::factory()->create();
        $this->actingAs($staff);
        Livewire::test(\App\Livewire\Optical\OpticalStockCountsComponent::class)
            ->call('openCount', $count->id)->assertDontSee('exp 5')
            ->call('toggleExpected')->assertForbidden();
        Livewire::test(\App\Livewire\Optical\OpticalStockCountsComponent::class)
            ->call('openCount', $count->id)
            ->set('counts.'.$lineFor($a)->id, '4')->set('counts.'.$lineFor($b)->id, '3')->set('counts.'.$lineFor($c)->id, '')
            ->call('saveCounts', true)->assertHasNoErrors();
        $this->assertSame('submitted', $count->fresh()->status);
        try {
            $service->approve($count->id);
            $this->fail('Staff cannot approve a count.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->actingAs($manager);
        Livewire::test(\App\Livewire\Optical\OpticalStockCountsComponent::class)
            ->call('openCount', $count->id)->assertSeeText('1 difference')->assertSeeText('1 item not counted')
            ->call('approve')->assertHasNoErrors();
        $this->assertSame('approved', $count->fresh()->status);
        $this->assertSame([4, 3, 2], [$stock($a), $stock($b), $stock($c)], 'Only counted differences change stock.');
        $movement = OpticalProductStockMovement::where('optical_stock_count_id', $count->id)->sole();
        $this->assertSame(['count', -1], [$movement->movement_type, $movement->quantity_change]);
    }

    public function test_half_pair_in_stock_is_priced_per_lens_held_then_deducted_at_glazing(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'half-pair');
        $this->actingAs($user);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Half Pair Customer', 'contact' => '0240000456', 'gender' => 'Other',
        ]);
        $specs = ['range' => 'Half Range', 'design' => 'Single Vision', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65];
        $receiving = app(\App\Services\OpticalLensReceivingService::class);
        $right = $receiving->receive($specs + ['sphere' => '0.25', 'power' => '0.00'], 1, ['unit_cost' => 10, 'unit_price' => 30]);
        $left = $receiving->receive($specs + ['sphere' => '-0.50', 'power' => '0.00'], 1, ['unit_cost' => 10, 'unit_price' => 40]);
        app(\App\Services\OpticalStockLedgerService::class)->adjust($left, -1, 'Sold out');
        $stockOf = fn ($product) => (int) OpticalProductStock::where('optical_product_id', $product->id)->value('quantity');
        $measurements = ['od' => ['sph' => '+0.25'], 'os' => ['sph' => '-0.50']];

        $availability = app(OpticalLensAvailabilityService::class);
        $options = $availability->stockOptions($measurements);
        $this->assertCount(1, $options);
        $this->assertSame('partial', $options[0]['status']);
        $this->assertSame(['od' => 'stock', 'os' => 'special_order'], $options[0]['split']);
        // Per-lens prices: the stocked OD lens plus the catalogue price of the OS power.
        $this->assertSame(70.0, $options[0]['price']);

        Livewire::test(OpticalOrderCreateComponent::class)
            ->call('choosePatient', $patient->id)
            ->set('currentStep', 2)
            ->call('checkLensAvailabilityFromClient', $measurements)
            ->assertSet('lensAvailability.status', 'partial')
            ->assertSet('lens_price', 70.0)
            ->call('nextStepWithLens', ['fulfilment' => 'stock'] + $measurements)
            ->assertHasNoErrors()->assertSet('currentStep', 3);

        $data = $this->orderData($patient);
        $data['measurements'] = $measurements;
        $data['lens_fulfilment_source'] = 'stock';
        $data['lens_price'] = 1; // client price is ignored for stock lenses
        $data['docket'] = ['lens_details' => ['stock_key' => $options[0]['key'], 'stock_split' => $options[0]['split']]];
        $order = app(OpticalOrderService::class)->create($data);

        $this->assertEquals(70, $order->lens_price);
        $this->assertSame(['od' => 'held', 'os' => 'ordered'], $order->lensLines()->pluck('status', 'eye')->all());
        $this->assertSame(1, $stockOf($right), 'Stock is only held when the order is placed.');
        $this->assertSame([], $availability->stockOptions($measurements), 'A held lens is not offered to another order.');

        $workflow = app(OpticalOrderWorkflowService::class);
        try {
            $workflow->transition($order->id, 'In Production');
            $this->fail('Glazing must wait for the special-order lens.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('OS', $exception->errors()['status'][0]);
        }
        $this->assertSame(1, $stockOf($right));

        $workflow->transition($order->id, 'Sent to Lab');
        $this->assertSame(0, $stockOf($right), 'Stock leaves the ledger when glazing starts.');
        $this->assertSame('consumed', $order->lensLines()->where('eye', 'od')->value('status'));
        $workflow->receiveSpecialOrderLens($order->id, 'os');
        $workflow->transition($order->id, 'In Production');
        $this->assertSame('In Production', $order->fresh()->status);

        // Same power in both eyes with one lens on the shelf: one eye from
        // stock, the other special-ordered. Cancelling releases the hold.
        $receiving->receive($specs + ['sphere' => '0.25', 'power' => '0.00'], 1, ['unit_cost' => 10, 'unit_price' => 30]);
        $same = ['od' => ['sph' => '+0.25'], 'os' => ['sph' => '+0.25']];
        $option = $availability->stockOptions($same)[0];
        $this->assertSame(['od' => 'stock', 'os' => 'special_order'], $option['split']);
        $data['measurements'] = $same;
        $data['docket'] = ['lens_details' => ['stock_key' => $option['key'], 'stock_split' => $option['split']]];
        $data['paid_amount'] = 0;
        $second = app(OpticalOrderService::class)->create($data);
        $this->assertSame([], $availability->stockOptions($same));
        $workflow->cancel($second->id);
        $this->assertSame('released', $second->lensLines()->where('eye', 'od')->value('status'));
        $this->assertSame(1, $stockOf($right));
        $this->assertSame('partial', $availability->stockOptions($same)[0]['status']);
    }

    public function test_progressive_stock_is_per_eye_and_matched_on_sph_and_add_without_cyl(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'progressive-stock');
        $this->actingAs($user);
        $receiving = app(\App\Services\OpticalLensReceivingService::class);
        $pal = ['range' => 'PAL Range', 'design' => 'Progressive', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 70];
        $details = ['unit_cost' => 40, 'unit_price' => 120];

        try {
            $receiving->receive($pal + ['sphere' => '1.00', 'power' => '2.00'], 1, $details);
            $this->fail('Progressive lenses must be received for one eye.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lensEye', $exception->errors());
        }
        $right = $receiving->receive($pal + ['eye' => 'R', 'sphere' => '1.00', 'power' => '2.00'], 1, $details);
        $wrongEye = $receiving->receive($pal + ['eye' => 'R', 'sphere' => '1.50', 'power' => '2.00'], 3, $details);
        $left = $receiving->receive($pal + ['eye' => 'L', 'sphere' => '1.50', 'power' => '2.00'], 1, ['unit_cost' => 40, 'unit_price' => 130]);
        $this->assertNotSame($right->id, $receiving->receive($pal + ['eye' => 'L', 'sphere' => '1.00', 'power' => '2.00'], 1, $details)->id, 'R and L are separate SKUs.');
        // Single vision stock whose CYL equals the ADD must never fill a progressive Rx.
        $receiving->receive(['range' => 'SV Range', 'design' => 'Single Vision', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65, 'sphere' => '1.00', 'power' => '2.00'], 5, $details);

        $availability = app(OpticalLensAvailabilityService::class);
        $rx = ['od' => ['sph' => '+1.00', 'add' => '+2.00'], 'os' => ['sph' => '+1.50', 'add' => '+2.00']];
        $options = $availability->stockOptions($rx);
        $this->assertCount(1, $options);
        $this->assertSame('Progressive', $options[0]['lens_type']);
        $this->assertSame('available', $options[0]['status']);
        $this->assertSame($right->id, $options[0]['od_product_id']);
        $this->assertSame($left->id, $options[0]['os_product_id'], 'The OS lens comes from left-eye stock, not the right-eye +1.50.');
        $this->assertSame(250.0, $options[0]['price']);
        $this->assertSame('add', $options[0]['eyes']['od']['power_type']);

        // Astigmatism in one eye: that eye is made to order, the other still comes from stock.
        $rx['os']['cyl'] = '-0.50';
        $rx['os']['axis'] = '90';
        $option = $availability->stockOptions($rx)[0];
        $this->assertSame(['od' => 'stock', 'os' => 'special_order'], $option['split']);
        $this->assertStringContainsString('CYL -0.50', $option['eyes']['os']['reason']);

        // A single vision Rx is not matched against progressive stock (old bug: ADD compared with CYL).
        $sv = ['od' => ['sph' => '+1.50', 'cyl' => '+2.00', 'axis' => '90'], 'os' => ['sph' => '+1.50', 'cyl' => '+2.00', 'axis' => '90']];
        $this->assertSame([], $availability->stockOptions($sv));
        $this->assertSame('Single Vision', $availability->stockOptions(['od' => ['sph' => '+1.00', 'cyl' => '+2.00'], 'os' => ['sph' => '+1.00', 'cyl' => '+2.00']])[0]['lens_type']);
        $this->assertSame(3, (int) OpticalProductStock::where('optical_product_id', $wrongEye->id)->value('quantity'));
    }

    public function test_manager_refunds_and_cancels_a_paid_order_keeping_a_fee(): void
    {
        config(['tenancy.enabled' => true]);
        $staff = User::factory()->create();
        [$clinic, $branch] = $this->tenant($staff, 'optical-order-refund');
        $this->actingAs($staff);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $staff->id, 'name' => 'Refund Customer', 'contact' => '0240000111', 'gender' => 'Other',
        ]);
        $order = app(OpticalOrderService::class)->create($this->orderData($patient)); // total 310, paid 50
        $workflow = app(OpticalOrderWorkflowService::class);

        try {
            $workflow->cancel($order->id);
            $this->fail('A paid order must be refunded, not just cancelled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }
        try {
            $workflow->refundAndCancel($order->id, 50, 'service_cancelled', 'Customer changed their mind');
            $this->fail('Only managers may refund.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $staff->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        Livewire::test(\App\Livewire\Optical\OpticalOrdersComponent::class)
            ->call('confirmDeleteOrder', $order->id)
            ->assertSet('showRefundModal', true)->assertSet('refundAmount', '50.00')
            ->set('refundAmount', '60')->set('refundReasonCode', 'service_cancelled')->set('refundReason', 'Customer changed their mind')
            ->call('refundOrder')->assertHasErrors(['refundAmount'])
            ->set('refundAmount', '20')->call('refundOrder')->assertHasNoErrors();

        $order->refresh();
        $this->assertSame('Cancelled', $order->status);
        $this->assertEquals(30, $order->cancellation_fee);
        $this->assertEquals(30, $order->paid_amount);
        $log = \App\Models\RefundLog::findOrFail($order->refund_log_id);
        $this->assertSame(\App\Models\RefundLog::STATUS_PROCESSED, $log->status);
        $this->assertEquals(20, $log->refunded_amount);
        $sale = Sales::findOrFail($order->sale_id);
        $this->assertEquals(30, $sale->amount_paid);
        $this->assertFalse($sale->is_refunded);
        DB::table('branch_user_role')->insert(['user_id' => $staff->id, 'branch_id' => $branch->id, 'role_id' => Role::where('name', 'Manager')->value('id')]);
        $this->get(route('refunds.receipt', $log))->assertOk();
    }

    public function test_collection_sets_warranty_and_renewal_and_remakes_are_free_or_charged(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'optical-remakes');
        $this->actingAs($user);
        \App\Models\OpticalSetting::create(['warranty_months' => 6, 'min_deposit_percentage' => 0]);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Remake Customer', 'contact' => '0240000222', 'gender' => 'Other',
        ]);
        $service = app(OpticalOrderService::class);
        $workflow = app(OpticalOrderWorkflowService::class);
        $order = $service->create($this->orderData($patient));

        $remakeData = $this->orderData($patient) + ['remake' => ['of' => $order->id, 'reason' => 'defect', 'charge' => 'free']];
        try {
            $service->create($remakeData);
            $this->fail('Only ready or collected glasses can be remade.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('remake', $exception->errors());
        }

        $workflow->transition($order->id, 'In Production');
        $workflow->transition($order->id, 'Ready for Collection');
        $workflow->recordPayment($order->id, $order->total - 50, 'cash');
        $workflow->transition($order->id, 'Collected');
        $order->refresh();
        $this->assertSame(now()->addMonthsNoOverflow(6)->toDateString(), $order->warranty_expires_at->toDateString());
        $this->assertSame(now()->addYear()->toDateString(), $order->renewal_date->toDateString());
        $this->assertTrue($order->isUnderWarranty());

        // The form suggests a free remake for a defect under warranty.
        Livewire::withQueryParams(['remake_of' => $order->id])->test(OpticalOrderCreateComponent::class)
            ->assertSet('remakeOfId', $order->id)->assertSet('frame_source', 'customer')->assertSet('frame_price', 0)
            ->assertSet('patient_id', $patient->id)
            ->set('remake_reason', 'defect')->assertSet('remake_charge', 'free')
            ->set('remake_reason', 'rx_change')->assertSet('remake_charge', 'charged');

        $remakeData['paid_amount'] = 0;
        $free = $service->create($remakeData);
        $this->assertSame($order->id, $free->remake_of_id);
        $this->assertSame(0.0, $free->total);
        $this->assertSame('free', $free->remake_charge);

        $charged = $service->create(['remake' => ['of' => $order->id, 'reason' => 'rx_change', 'charge' => 'charged']] + $this->orderData($patient));
        $this->assertSame(310.0, $charged->total);
        try {
            $service->create($remakeData, true);
            $this->fail('Remakes are orders, not quotations.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('remake', $exception->errors());
        }

        // Optical orders reach their customer for renewal reminders without a clinic refraction.
        $walkIn = LensOrder::create(['order_id' => 'OPT-WALKIN', 'status' => 'Collected', 'order_source' => 'walk_in',
            'customer_name' => 'Walk In', 'customer_phone' => '0240000333', 'frame_price' => 0, 'lens_price' => 0,
            'renewal_date' => now()->addDays(30)->toDateString(), 'pickUpDate' => now()->toDateString(), 'user_id' => $user->id]);
        $this->assertSame(['name' => 'Walk In', 'phone' => '0240000333', 'patient_id' => null], $walkIn->renewalRecipient());
        $this->assertSame('Remake Customer', $order->renewalRecipient()['name']);
        $this->artisan('sms:spectacle-renewal-reminders')->assertSuccessful();
        $this->assertSame('pending', $walkIn->fresh()->renewal_approval_status);
    }

    public function test_prescription_order_prices_lenses_per_eye_and_services_like_the_saved_order(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'priced-services');
        $this->actingAs($user);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Priced Customer', 'contact' => '0240000789', 'gender' => 'Other',
        ]);
        $specs = ['range' => 'Price Range', 'design' => 'Single Vision', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65];
        $receiving = app(\App\Services\OpticalLensReceivingService::class);
        $receiving->receive($specs + ['sphere' => '0.25', 'power' => '0.00'], 1, ['unit_cost' => 10, 'unit_price' => 25]);
        $left = $receiving->receive($specs + ['sphere' => '-0.50', 'power' => '0.00'], 1, ['unit_cost' => 10, 'unit_price' => 25]);
        app(\App\Services\OpticalStockLedgerService::class)->adjust($left, -1, 'Sold out');
        $glazing = $this->pricedService('glazing_priced', 20);
        $tint = $this->pricedService('tint_priced', 15);
        $measurements = ['od' => ['sph' => '+0.25'], 'os' => ['sph' => '-0.50']];

        $form = Livewire::test(OpticalOrderCreateComponent::class)
            ->call('choosePatient', $patient->id)
            ->set('currentStep', 2)
            ->call('checkLensAvailabilityFromClient', $measurements)
            ->assertSet('lensAvailability.status', 'partial')
            ->set('frame_model_number', 'Priced Frame')->set('frame_price', 30)
            ->set('currentStep', 5)
            ->call('addService', $glazing->id)->call('addService', $tint->id)->call('addService', $tint->id)
            ->assertCount('service_lines', 2)
            ->set('service_lines.1.quantity', 2)
            // Discount is optional: a blank field means no discount.
            ->set('discount_amount', '')
            ->call('nextStep')
            ->assertHasNoErrors()->assertSet('currentStep', 6)->assertSet('discount_amount', 0)
            ->set('currentStep', 5)
            ->set('discount_amount', 200)
            ->call('nextStep')
            ->assertHasErrors(['discount_amount'])
            ->set('discount_amount', 5)
            ->call('nextStep')
            ->assertHasNoErrors()->assertSet('currentStep', 6);

        // Frame 30 + OD 25 + OS 25 + glazing 20 + tint 2 × 15 − discount 5.
        $pricing = $form->instance()->priceBreakdown();
        $this->assertSame(125.0, $pricing['total']);
        $this->assertSame(['OD lens · branch stock', 'OS lens · special order'], array_slice(array_column($pricing['lines'], 'label'), 1, 2));
        // Service lines carry what the page needs to recount them as the quantity is typed.
        $tintLine = collect($pricing['lines'])->firstWhere('name', 'Tint Priced');
        $this->assertSame(['unit' => 15.0, 'service_index' => 1], ['unit' => $tintLine['unit'], 'service_index' => $tintLine['service_index']]);
        $this->assertFalse($pricing['free']);
        $form->set('currentStep', 5)->assertSee('orderPricing(', false)->assertSee('wire:model="discount_amount"', false)->set('currentStep', 6);

        $form->set('paid_amount', 50)->set('currentStep', 7)->call('createOrder')->assertHasNoErrors();
        $order = LensOrder::where('status', 'Pending')->latest('id')->firstOrFail();
        $this->assertSame(125.0, $order->total);
        $this->assertEquals(50, $order->lens_price);
        $this->assertEquals(50, $order->service_total);
        $this->assertEquals(0, $order->glazing_fee);
        $this->assertSame(['Glazing Priced', 'Tint Priced'], $order->serviceLines()->orderBy('id')->pluck('description')->all());
        $this->assertArrayNotHasKey('name', json_decode($order->notes, true)['lab']);
    }

    public function test_combined_rx_lens_check_and_order_reservation_use_current_branch_stock(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        [$clinic, $branch] = $this->tenant($user, 'lens-availability');
        $this->actingAs($user);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Lens Customer', 'contact' => '0240000123', 'gender' => 'Other',
        ]);
        $blank = OpticalLensBlank::create([
            'lens_index' => '1.56', 'coating' => 'AR', 'sphere' => '-2.00',
            'cylinder' => '-1.00', 'quantity' => 1, 'reorder_level' => 0,
        ]);
        $measurements = [
            'od' => ['sph' => '-2.00', 'cyl' => '-1.00', 'axis' => 90],
            'os' => ['sph' => '-2.00', 'cyl' => '-1.00', 'axis' => 120],
        ];
        $availability = app(OpticalLensAvailabilityService::class);
        $this->assertSame('outside_sourcing', $availability->check($measurements, '1.56', 'AR', 'Single Vision')['status']);
        $blank->update(['quantity' => 2]);
        $this->assertSame('available', $availability->check($measurements, '1.56', 'AR', 'Single Vision')['status']);
        $this->assertSame('not_verifiable', $availability->check($measurements, '1.56', 'AR', 'Progressive')['status']);

        Livewire::test(OpticalOrderCreateComponent::class)
            ->call('choosePatient', $patient->id)
            ->set('currentStep', 2)
            ->call('checkLensAvailabilityFromClient', [
                'od' => ['sph' => '-2.00', 'cyl' => '-1.00', 'axis' => '90'],
                'os' => ['sph' => '-2.00', 'cyl' => '-1.00', 'axis' => '120'],
            ])->assertSet('lensAvailability.status', 'available')
            ->call('nextStepWithLens', [
                'fulfilment' => 'stock',
                'od' => ['sph' => '-2.00', 'cyl' => '-1.00', 'axis' => '90'],
                'os' => ['sph' => '-2.00', 'cyl' => '-1.00', 'axis' => '120'],
            ])->assertHasNoErrors()->assertSet('currentStep', 3)
            ->assertSet('lensAvailability.status', 'available');

        $data = $this->orderData($patient);
        $data['measurements'] = $measurements;
        $data['lens_fulfilment_source'] = 'stock';
        $data['docket'] = ['lens_details' => [
            'type' => 'Single Vision', 'index' => '1.56', 'stock_coating' => 'AR', 'color' => 'White',
        ]];
        $quote = app(OpticalOrderService::class)->create($data, true);
        $this->assertSame(2, $blank->fresh()->quantity);
        $blank->update(['quantity' => 1]);
        try {
            app(OpticalOrderWorkflowService::class)->activateQuotation($quote->id);
            $this->fail('Expected an insufficient-stock validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lens_stock', $exception->errors());
        }
        $this->assertSame('Quotation', $quote->fresh()->status);
        $this->assertSame(1, $blank->fresh()->quantity);
        $blank->update(['quantity' => 2]);
        $order = app(OpticalOrderWorkflowService::class)->activateQuotation($quote->id);
        $this->assertSame(0, $blank->fresh()->quantity);
        $this->assertCount(1, $order->fresh()->lens_blank_allocations);
        app(OpticalOrderWorkflowService::class)->cancel($order->id);
        $this->assertSame(2, $blank->fresh()->quantity);

        $otherBranch = $clinic->branches()->create(['code' => 'OTHER', 'name' => 'Other', 'is_active' => true]);
        $otherBranch->users()->attach($user->id, ['status' => 'active', 'is_default' => false]);
        app(TenantContext::class)->set($user, $clinic, $otherBranch, [$otherBranch->id]);
        $this->assertSame('outside_sourcing', $availability->check($measurements, '1.56', 'AR', 'Single Vision')['status']);
    }

    public function test_partner_registry_is_separate_from_existing_patient_registry(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'optical-partner-form');
        $this->actingAs($user);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Existing Patient', 'contact' => '0240000002',
            'gender' => 'Female', 'dob' => '1995-09-05', 'civil_status' => 'Single',
            'occupation' => 'Optometrist',
        ]);
        Livewire::test(PartnerClinicsComponent::class)
            ->call('add')
            ->set('name', 'Referral Eye Centre')
            ->set('contactPerson', 'Partner Coordinator')
            ->set('phone', '0241112222')
            ->set('billingTerms', 'on_account')
            ->call('save')
            ->assertHasNoErrors();
        $partner = OpticalPartnerClinic::where('name', 'Referral Eye Centre')->firstOrFail();
        Livewire::test(PartnerClinicsComponent::class)
            ->call('edit', $partner->id)
            ->set('phone', '0243334444')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('0243334444', $partner->fresh()->phone);
        $this->assertSame('Existing Patient', $patient->fresh()->name);
        $this->assertSame(1, Patient::count());
        $this->assertSame(1, OpticalPartnerClinic::count());

        Livewire::withQueryParams(['partner_id' => $partner->id])
            ->test(OpticalOrderCreateComponent::class)
            ->assertSet('order_source', 'partner')
            ->assertSet('partner_id', $partner->id)
            ->assertSet('partner_clinic_name', 'Referral Eye Centre');

        Livewire::test(OpticalOrderCreateComponent::class)
            ->dispatch('optical-order-source-changed', source: 'partner')
            ->assertSet('order_source', 'partner')
            ->assertSee('Find partner clinic')
            ->assertSee('Referral Eye Centre')
            ->set('customerSearch', 'Referral Eye')
            ->assertSee('Referral Eye Centre')
            ->call('choosePartner', $partner->id)
            ->assertSet('partner_id', $partner->id)
            ->assertSet('partner_clinic_name', 'Referral Eye Centre');

        Livewire::test(OpticalOrderCreateComponent::class)
            ->call('nextStepWithRequester', [
                'order_source' => 'partner', 'work_type' => 'prescription',
                'bill_to' => 'customer', 'partner_id' => $partner->id,
                'customer_name' => '', 'customer_phone' => '', 'reference' => 'PARTNER-101',
            ])
            ->assertHasErrors(['customer_name'])
            ->call('nextStepWithRequester', [
                'order_source' => 'partner', 'work_type' => 'prescription',
                'bill_to' => 'customer', 'partner_id' => $partner->id,
                'customer_name' => 'Partner Wearer', 'customer_phone' => '0240000000', 'reference' => 'PARTNER-101',
            ])
            ->assertHasNoErrors()
            ->assertSet('currentStep', 2);
    }

    public function test_partner_and_walk_in_work_orders_need_no_patient_record_and_use_standard_prices(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'partner-work');
        $this->actingAs($user);
        $partner = OpticalPartnerClinic::create(['name' => 'North Eye Clinic', 'billing_terms' => 'on_account', 'is_active' => true]);
        $service = $this->pricedService('cleaning', 40);
        $base = [
            'work_type' => 'service', 'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'frame_price' => 0, 'lens_price' => 0, 'glazing_fee' => 0, 'discount_amount' => 0,
            'paid_amount' => 0, 'payment_method' => 'cash', 'pickup_date' => now()->addWeek()->toDateString(),
        ];
        $quote = app(OpticalOrderService::class)->create($base + [
            'order_source' => 'partner', 'partner_id' => $partner->id, 'bill_to' => 'partner',
            'docket' => ['reference' => 'NORTH-442'],
        ], true);
        $this->assertNull($quote->patient_id);
        $this->assertSame('North Eye Clinic', $quote->display_customer_name);
        $this->assertSame(40.0, $quote->total);
        $order = app(OpticalOrderWorkflowService::class)->activateQuotation($quote->id);
        $this->assertSame('North Eye Clinic', $order->sale->customer_name);
        app(OpticalOrderWorkflowService::class)->transition($order->id, 'In Production');
        app(OpticalOrderWorkflowService::class)->transition($order->id, 'Ready for Collection');
        app(OpticalOrderWorkflowService::class)->transition($order->id, 'Collected');
        $this->assertSame('Collected', $order->fresh()->status);

        $walkIn = app(OpticalOrderService::class)->create($base + [
            'order_source' => 'walk_in', 'customer_name' => 'Walk-in Wearer', 'customer_phone' => '0245556666',
        ]);
        $this->assertNull($walkIn->patient_id);
        $this->assertSame('Walk-in Wearer', $walkIn->display_customer_name);
        $this->assertSame(40.0, $walkIn->total);
        $this->assertSame(0, Patient::count());
    }

    public function test_partner_prescription_snapshot_does_not_create_patient_and_partner_cannot_cross_tenants(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $user->assignRole($role);
        [, $branch] = $this->tenant($user, 'external-rx-a');
        DB::table('branch_user_role')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'role_id' => $role->id]);
        $this->actingAs($user);
        $partner = OpticalPartnerClinic::create(['name' => 'External Eye Clinic', 'is_active' => true]);
        $service = $this->pricedService('lens_fitting', 70, true, true);
        $data = [
            'order_source' => 'partner', 'partner_id' => $partner->id, 'bill_to' => 'partner',
            'work_type' => 'service', 'services' => [['service_id' => $service->id, 'quantity' => 1]],
            'measurements' => ['od' => ['sph' => '-2.00'], 'os' => ['sph' => '-1.50']],
            'frame_model_number' => 'Clinic-supplied frame', 'frame_price' => 0, 'lens_price' => 0,
            'glazing_fee' => 0, 'discount_amount' => 0, 'paid_amount' => 0,
            'pickup_date' => now()->addWeek()->toDateString(),
            'docket' => ['frame_source' => 'customer', 'reference' => 'EXT-55'],
        ];
        $order = app(OpticalOrderService::class)->create($data, true);
        $this->assertNull($order->patient_id);
        $this->assertNull($order->optical_prescription_id);
        $this->assertSame('-2.00', data_get($order->prescription_snapshot, 'od.sph'));
        $this->get(route('optical.orders.docket', $order->id))->assertOk()->assertSee('-2.00');
        $this->assertSame(0, Patient::count());

        $this->tenant($user, 'external-rx-b');
        $this->assertNull(OpticalPartnerClinic::find($partner->id));
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(OpticalOrderService::class)->create($data, true);
    }

    public function test_wizard_search_reference_and_conditional_axis_validation(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'optical-wizard-search');
        $this->actingAs($user);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Unique Search Customer',
            'contact' => '0245550000', 'gender' => 'Other',
        ]);

        Livewire::test(OpticalOrderCreateComponent::class)
            ->call('setStep', 2)
            ->assertSet('currentStep', 1)
            ->assertHasErrors();

        Livewire::test(OpticalOrderCreateComponent::class)
            ->set('order_source', 'in_clinic')
            ->set('customerSearch', 'Unique Search')
            ->assertSee('Unique Search Customer')
            ->call('choosePatient', $patient->id)
            ->assertSet('patient_id', $patient->id)
            ->set('reference', 'JOB-123')
            ->set('currentStep', 2)
            ->set('rx_od_sph', '-2.00')
            ->set('rx_os_sph', '-1.50')
            ->set('rx_od_cyl', '-0.75')
            ->call('nextStep')
            ->assertHasErrors(['rx_od_axis'])
            ->set('rx_od_axis', '181')
            ->call('nextStep')
            ->assertHasErrors(['rx_od_axis'])
            ->set('rx_od_axis', '180')
            ->set('rx_od_sph', '-104.00')
            ->call('nextStep')
            ->assertHasErrors(['rx_od_sph' => 'between'])
            ->assertSet('currentStep', 2)
            ->set('rx_od_sph', '-2.10')
            ->call('nextStep')
            ->assertHasErrors(['rx_od_sph'])
            ->set('rx_od_sph', '-2.00')
            ->set('rx_os_add', '9')
            ->call('nextStep')
            ->assertHasErrors(['rx_os_add' => 'between'])
            ->set('rx_os_add', '')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('currentStep', 3)
            ->set('frame_model_number', 'Search Frame')
            ->call('saveAsQuotation')
            ->assertHasNoErrors();

        $quote = LensOrder::where('status', 'Quotation')->firstOrFail();
        $this->assertSame('JOB-123', data_get(json_decode($quote->notes, true), 'reference'));
        $this->assertSame(180, (int) data_get($quote->prescription_snapshot, 'od.axis'));

        $invalid = $this->orderData($patient);
        $invalid['measurements']['od']['cyl'] = '-0.75';
        $this->expectException(ValidationException::class);
        app(OpticalOrderService::class)->create($invalid, true);
    }

    public function test_quotation_can_be_edited_without_creating_another_order(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'optical-quote-edit');
        $this->actingAs($user);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Quote Customer', 'contact' => '0245550001', 'gender' => 'Other',
        ]);
        $quote = app(OpticalOrderService::class)->create($this->orderData($patient), true);
        $data = $this->orderData($patient);
        $data['frame_model_number'] = 'Revised Frame';
        $data['docket'] = ['reference' => 'REVISED-1'];

        app(OpticalOrderService::class)->updateQuotation($quote->id, $data);

        $this->assertSame(1, LensOrder::count());
        $this->assertSame('Revised Frame', $quote->fresh()->frame_model_number);
        $this->assertSame('REVISED-1', data_get(json_decode($quote->fresh()->notes, true), 'reference'));
        $this->assertNull($quote->fresh()->sale_id);
    }

    public function test_customer_supplied_frame_has_no_frame_charge_or_stock_movement(): void
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->tenant($user, 'optical-own-frame');
        $this->actingAs($user);
        $patient = Patient::createWithGeneratedPxNumber([
            'user_id' => $user->id, 'name' => 'Own Frame Customer',
            'contact' => '0245550002', 'gender' => 'Other',
        ]);
        $category = Category::create(['user_id' => $user->id, 'name' => 'Optical Frames']);
        $product = Product::create([
            'user_id' => $user->id, 'name' => 'Clinic Frame', 'category_id' => $category->id,
            'quantity' => 3, 'cost_price' => 50, 'selling_price' => 200,
        ]);

        Livewire::test(OpticalOrderCreateComponent::class)
            ->call('choosePatient', $patient->id)
            ->set('currentStep', 3)
            ->set('frame_source', 'customer')
            ->set('frame_product_id', $product->id)
            ->set('frame_price', 200)
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('frame_product_id', null)
            ->assertSet('frame_price', 0)
            ->assertSet('currentStep', 4);

        $data = $this->orderData($patient);
        $data['frame_product_id'] = $product->id;
        $data['frame_model_number'] = '';
        $data['docket'] = ['frame_source' => 'customer'];
        $order = app(OpticalOrderService::class)->create($data);

        $this->assertNull($order->frame_product_id);
        $this->assertSame(0.0, (float) $order->frame_price);
        $this->assertSame('Customer-supplied frame', $order->frame_model_number);
        $this->assertSame(3, app(\App\Services\Inventory\BranchInventoryService::class)->quantity($product));
        $this->assertSame(110.0, $order->total);
    }

    /** An order for stock lenses in its own lens range; returns [order, +0.25 lens product, patient]. */
    private function stockLensOrder(string $slug, array $measurements, int $shelf, int $paid = 0, array $extraPowers = []): array
    {
        $user = $this->opticalManager($slug);
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Customer '.$slug, 'contact' => '0240007777', 'gender' => 'Other']);
        $specs = ['range' => 'Range '.$slug, 'design' => 'Single Vision', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65];
        $receiving = app(\App\Services\OpticalLensReceivingService::class);
        $lens = $receiving->receive($specs + ['sphere' => '0.25', 'power' => '0.00'], $shelf, ['unit_cost' => 10, 'unit_price' => 30]);
        foreach ($extraPowers as $sphere) {
            // In the catalogue but out of stock, so it is special-ordered.
            $product = $receiving->receive($specs + ['sphere' => $sphere, 'power' => '0.00'], 1, ['unit_cost' => 12, 'unit_price' => 35]);
            app(\App\Services\OpticalStockLedgerService::class)->adjust($product, -1, 'Sold out');
        }
        $option = app(OpticalLensAvailabilityService::class)->stockOptions($measurements)[0];
        $data = $this->orderData($patient);
        $data['measurements'] = $measurements;
        $data['paid_amount'] = $paid;
        $data['lens_fulfilment_source'] = 'stock';
        $data['docket'] = ['lens_details' => ['stock_key' => $option['key'], 'stock_split' => $option['split'], 'type' => 'Single Vision', 'index' => '1.56', 'stock_coating' => 'AR']];
        return [app(OpticalOrderService::class)->create($data), $lens, $patient];
    }

    public function test_lenses_held_for_an_order_cannot_be_sold_at_the_pos(): void
    {
        [$order, $lens] = $this->stockLensOrder('held-pos', ['od' => ['sph' => '+0.25'], 'os' => ['sph' => '+0.25']], 3);
        $inventory = app(\App\Services\OpticalProductInventoryService::class);
        $this->assertSame(1, $inventory->available($lens), 'Three on the shelf, two held.');

        $pos = Livewire::test(\App\Livewire\Optical\OpticalPosComponent::class)->call('addToCart', 'o:'.$lens->id)->assertHasNoErrors();
        $this->expectsValidationOn(fn () => $pos->instance()->addToCart('o:'.$lens->id));
        $pos->call('completeSale')->assertHasNoErrors();
        $this->assertSame(2, (int) OpticalProductStock::where('optical_product_id', $lens->id)->value('quantity'));
        $this->expectsValidationOn(fn () => $inventory->decrease($lens, 1, 'Another sale'));

        app(OpticalOrderWorkflowService::class)->transition($order->id, 'In Production');
        $this->assertSame(0, (int) OpticalProductStock::where('optical_product_id', $lens->id)->value('quantity'), 'Both held lenses were still there to glaze.');
    }

    public function test_cut_catalogue_lenses_are_written_off_not_restocked(): void
    {
        $user = $this->opticalManager('catalogue-cut');
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Catalogue Customer', 'contact' => '0240007778', 'gender' => 'Other']);
        $category = OpticalCategory::create(['code' => 'SV', 'name' => 'Single Vision Lenses', 'is_active' => true]);
        $lens = OpticalProduct::create(['optical_category_id' => $category->id, 'name' => 'SV Catalogue Lens', 'sku' => 'SV-CAT', 'selling_price' => 100, 'cost_price' => 40, 'is_active' => true]);
        app(\App\Services\OpticalProductInventoryService::class)->setBalance($lens, 5, 1, 'Opening');
        $shelf = fn () => (int) OpticalProductStock::where('optical_product_id', $lens->id)->value('quantity');
        $data = $this->orderData($patient);
        $data['lens_fulfilment_source'] = 'catalogue';
        $data['lens_optical_product_id'] = $lens->id;
        $workflow = app(OpticalOrderWorkflowService::class);

        // Cancelled before glazing: the lens goes back.
        $early = app(OpticalOrderService::class)->create(['paid_amount' => 0] + $data);
        $workflow->cancel($early->id);
        $this->assertSame(5, $shelf());

        // Cancelled after glazing: the lens was cut for this customer.
        $late = app(OpticalOrderService::class)->create($data);
        $workflow->transition($late->id, 'In Production');
        $workflow->refundAndCancel($late->id, 50, array_key_first(\App\Models\RefundLog::REASON_CODES), 'Customer changed mind after glazing');
        $this->assertSame(4, $shelf());
        $this->assertNotNull($late->fresh()->lenses_scrapped_at);
        $pl = app(\App\Services\OpticalProfitService::class)->statement(now()->startOfMonth(), now());
        $this->assertSame(40.0, $pl['losses']['Lenses cut for cancelled jobs']);
    }

    public function test_special_order_lens_for_a_cancelled_job_goes_into_stock(): void
    {
        [$order] = $this->stockLensOrder('cancelled-special', ['od' => ['sph' => '+0.25'], 'os' => ['sph' => '-0.50']], 1, 0, ['-0.50']);
        $osProduct = $order->lensLines()->where('eye', 'os')->value('optical_product_id');
        $this->assertNotNull($osProduct);
        $supplier = \App\Models\Supplier::create(['name' => 'Rx Lab', 'is_active' => true]);
        $purchasing = app(\App\Services\OpticalPurchasingService::class);
        $placed = $purchasing->createDraft($supplier->id, [['lens_order_id' => $order->id, 'eye' => 'os', 'quantity' => 1, 'unit_cost' => 40]]);
        $purchasing->place($placed->id);

        app(OpticalOrderWorkflowService::class)->cancel($order->id);
        $this->assertSame('released', $order->lensLines()->where('eye', 'os')->value('status'), 'No longer awaited.');
        $this->assertSame(0, $purchasing->specialOrderBacklog()->count());

        $purchasing->receive($placed->id, [$placed->lines->first()->id => 1]);
        $this->assertSame(1, (int) OpticalProductStock::where('optical_product_id', $osProduct)->value('quantity'), 'The lens that arrived is on the shelf.');
        $this->assertEquals(40, OpticalProductStockMovement::whereNotNull('optical_purchase_order_line_id')->value('unit_cost'));
    }

    public function test_cancelling_a_job_removes_its_lens_from_draft_supplier_orders(): void
    {
        [$order] = $this->stockLensOrder('cancelled-draft', ['od' => ['sph' => '+0.25'], 'os' => ['sph' => '-0.50']], 1);
        $supplier = \App\Models\Supplier::create(['name' => 'Rx Lab', 'is_active' => true]);
        $draft = app(\App\Services\OpticalPurchasingService::class)->createDraft($supplier->id, [['lens_order_id' => $order->id, 'eye' => 'os', 'quantity' => 1, 'unit_cost' => 40]]);
        app(OpticalOrderWorkflowService::class)->cancel($order->id);
        $this->assertSame(0, $draft->lines()->count());
    }

    public function test_glasses_cannot_be_ready_while_a_special_order_lens_is_awaited(): void
    {
        [$order] = $this->stockLensOrder('awaited-ready', ['od' => ['sph' => '+0.25'], 'os' => ['sph' => '-0.50']], 1);
        $workflow = app(OpticalOrderWorkflowService::class);
        $workflow->transition($order->id, 'Sent to Lab');
        try {
            $workflow->transition($order->id, 'Ready for Collection');
            $this->fail('Ready must wait for the special-order lens.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('OS', $exception->errors()['status'][0]);
        }
        $workflow->receiveSpecialOrderLens($order->id, 'os');
        $workflow->transition($order->id, 'Ready for Collection');
        $this->assertSame('Ready for Collection', $order->fresh()->status);
    }

    public function test_converting_a_quotation_takes_the_required_deposit(): void
    {
        $user = $this->opticalManager('quote-deposit');
        \App\Models\OpticalSetting::create(['warranty_months' => 0, 'min_deposit_percentage' => 50]);
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Quote Customer', 'contact' => '0240007779', 'gender' => 'Other']);
        $quote = app(OpticalOrderService::class)->create($this->orderData($patient), true); // total 310
        $workflow = app(OpticalOrderWorkflowService::class);

        $this->expectsValidationOn(fn () => $workflow->activateQuotation($quote->id));
        // The panel asks for the deposit, starting at the minimum.
        $panel = Livewire::test(\App\Livewire\Optical\OpticalOrdersComponent::class)->call('convertQuotationToOrder', $quote->id)
            ->assertSet('showConvertModal', true)->assertSet('convertDeposit', '155.00')
            ->set('convertDeposit', '100')->call('confirmConvertQuotation')->assertHasErrors('paid_amount');
        $this->assertSame('Quotation', $quote->fresh()->status);

        $panel->set('convertDeposit', '155')->set('convertMethod', 'momo')->call('confirmConvertQuotation')->assertHasNoErrors();
        $order = $quote->fresh();
        $this->assertSame('Pending', $order->status);
        $this->assertEquals(155, $order->paid_amount);
        $this->assertSame('momo', \App\Models\PaymentTransaction::where('sale_id', $order->sale_id)->value('payment_method'));
    }

    public function test_a_sale_during_a_stock_count_is_not_taken_off_twice(): void
    {
        $this->opticalManager('count-during-sale');
        $category = OpticalCategory::create(['code' => 'FRM', 'name' => 'Frames', 'is_active' => true]);
        $frame = OpticalProduct::create(['optical_category_id' => $category->id, 'name' => 'Count Frame', 'sku' => 'CNT-1', 'selling_price' => 100, 'cost_price' => 40, 'is_active' => true]);
        $inventory = app(\App\Services\OpticalProductInventoryService::class);
        $inventory->setBalance($frame, 10, 1, 'Opening');
        $counts = app(\App\Services\OpticalStockCountService::class);
        $count = $counts->start('frames');

        $inventory->decrease($frame, 2, 'Sold while counting'); // 8 left
        $line = $count->lines()->firstOrFail();
        $counts->saveCounts($count->id, [$line->id => '8']); // counted after the sale: nothing missing
        $this->assertSame(0, $line->fresh()->variance());
        $counts->submit($count->id);
        $counts->approve($count->id);
        $this->assertSame(8, (int) OpticalProductStock::where('optical_product_id', $frame->id)->value('quantity'));
    }

    public function test_expired_quotations_need_a_price_decision_when_converted(): void
    {
        $user = $this->opticalManager('quote-expiry');
        \App\Models\OpticalSetting::create(['warranty_months' => 0, 'min_deposit_percentage' => 0, 'quote_validity_days' => 5]);
        $category = OpticalCategory::create(['code' => 'FRM', 'name' => 'Frames', 'is_active' => true]);
        $frame = OpticalProduct::create(['optical_category_id' => $category->id, 'name' => 'Quote Frame', 'sku' => 'QF-1', 'selling_price' => 200, 'cost_price' => 80, 'is_active' => true]);
        app(\App\Services\OpticalProductInventoryService::class)->setBalance($frame, 3, 1, 'Opening');
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Expiry Customer', 'contact' => '0240007780', 'gender' => 'Other']);
        $data = ['frame_optical_product_id' => $frame->id, 'docket' => ['frame_source' => 'stock']] + $this->orderData($patient);
        $keep = app(OpticalOrderService::class)->create($data, true);
        $reprice = app(OpticalOrderService::class)->create($data, true);
        $this->assertSame(today()->addDays(5)->toDateString(), $keep->quote_valid_until->toDateString());

        $this->travel(6)->days(); // the test subscription runs a month
        $frame->update(['selling_price' => 260]);
        $this->assertTrue($keep->fresh()->isQuoteExpired());
        $workflow = app(OpticalOrderWorkflowService::class);
        $this->expectsValidationOn(fn () => $workflow->activateQuotation($keep->id));

        Livewire::test(\App\Livewire\Optical\OpticalOrdersComponent::class)
            ->call('convertQuotationToOrder', $keep->id)->assertSee('This quotation expired on')
            ->call('confirmConvertQuotation')->assertHasErrors('convertPricing')
            ->set('convertPricing', 'keep')->call('confirmConvertQuotation')->assertHasNoErrors();
        $this->assertEquals(200, $keep->fresh()->frame_price, 'Quoted price kept.');

        $workflow->activateQuotation($reprice->id, 0, 'cash', true);
        $this->assertEquals(260, $reprice->fresh()->frame_price, 'Charged at the current price.');
        $this->assertEquals(260, \App\Models\Sales::find($reprice->fresh()->sale_id)->items()->value('selling_price'));
    }

    public function test_send_to_lab_records_the_lab_and_overdue_jobs_are_tracked(): void
    {
        $user = $this->opticalManager('lab-tracking');
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Lab Customer', 'contact' => '0240007781', 'gender' => 'Other']);
        $lab = \App\Models\Supplier::create(['name' => 'Crystal Lab', 'is_active' => true, 'lead_time_days' => 3]);
        $order = app(OpticalOrderService::class)->create(['pickup_date' => now()->addDays(10)->toDateString()] + $this->orderData($patient));

        Livewire::test(\App\Livewire\Optical\OpticalOrdersComponent::class)
            ->call('openSendToLab', $order->id)->assertSet('showSendToLabModal', true)
            ->set('labSupplierId', (string) $lab->id)->assertSet('expectedBack', today()->addDays(3)->toDateString())
            ->call('sendToLab')->assertHasNoErrors();
        $order->refresh();
        $this->assertSame('Sent to Lab', $order->status);
        $this->assertSame($lab->id, $order->lab_supplier_id);
        $this->assertSame(today()->addDays(3)->toDateString(), $order->expected_back_at->toDateString());

        $tracking = app(\App\Services\OpticalJobTrackingService::class);
        $this->assertCount(0, $tracking->overdue());
        $this->travel(5)->days();
        $overdue = $tracking->overdue();
        $this->assertCount(1, $overdue);
        $this->assertSame('Not back from Crystal Lab', $overdue->first()['reason']);
        $this->assertSame(2, $overdue->first()['days']);
        $this->get(route('optical.jobs'))->assertOk()->assertSee('Not back from Crystal Lab')->assertSee($order->order_id);
        $this->get(route('optical.dashboard'))->assertOk()->assertSee('Jobs need attention');

        // Ready two days late: turnaround for Crystal Lab shows it.
        app(OpticalOrderWorkflowService::class)->transition($order->id, 'Ready for Collection');
        $turnaround = $tracking->turnaround(now()->subMonth(), now())->firstWhere('lab', 'Crystal Lab');
        $this->assertSame(1, $turnaround['jobs']);
        $this->assertSame(0.0, (float) $turnaround['on_time']);
        $this->assertSame(1, $turnaround['late']);
        $this->assertEquals(5.0, $turnaround['avg_lab_days']);
    }

    public function test_stuck_jobs_can_release_the_lenses_they_hold(): void
    {
        \App\Models\OpticalSetting::create(['warranty_months' => 0, 'min_deposit_percentage' => 0, 'stuck_job_days' => 7]);
        [$order, $lens] = $this->stockLensOrder('stuck-release', ['od' => ['sph' => '+0.25'], 'os' => ['sph' => '+0.25']], 2);
        $inventory = app(\App\Services\OpticalProductInventoryService::class);
        $tracking = app(\App\Services\OpticalJobTrackingService::class);
        $this->assertCount(0, $tracking->stuck());

        $this->travel(8)->days();
        $stuck = $tracking->stuck();
        $this->assertCount(1, $stuck);
        $this->assertCount(2, $stuck->first()['held']);
        $this->assertSame(0, $inventory->available($lens));

        Livewire::test(\App\Livewire\Optical\OpticalJobsComponent::class)->call('releaseLenses', $order->id)->assertSee('2 held lenses released');
        $this->assertSame(2, $inventory->available($lens), 'Free to sell again.');
        $this->assertSame(['od' => 'released', 'os' => 'released'], $order->lensLines()->pluck('status', 'eye')->all());

        // One is sold; the job then takes the other at glazing but cannot get two.
        $inventory->decrease($lens, 1, 'Sold at the counter');
        try {
            app(OpticalOrderWorkflowService::class)->transition($order->id, 'In Production');
            $this->fail('Only one lens is free for a two-lens job.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('released', $exception->errors()['status'][0]);
        }
        $this->assertSame(1, (int) OpticalProductStock::where('optical_product_id', $lens->id)->value('quantity'), 'Nothing was taken when glazing was refused.');

        $inventory->increase($lens, 1, 'Restocked');
        app(OpticalOrderWorkflowService::class)->transition($order->id, 'In Production');
        $this->assertSame(0, (int) OpticalProductStock::where('optical_product_id', $lens->id)->value('quantity'));
        $this->assertSame(['od' => 'consumed', 'os' => 'consumed'], $order->lensLines()->pluck('status', 'eye')->all());
    }

    public function test_job_tracking_lists_each_job_once_with_every_reason_and_can_set_a_new_date(): void
    {
        \App\Models\OpticalSetting::create(['warranty_months' => 0, 'min_deposit_percentage' => 0, 'stuck_job_days' => 7]);
        $user = $this->opticalManager('job-attention');
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Late Customer', 'contact' => '0240007790', 'gender' => 'Other']);
        $late = app(OpticalOrderService::class)->create(['pickup_date' => now()->addDays(2)->toDateString()] + $this->orderData($patient));
        $onTime = app(OpticalOrderService::class)->create(['pickup_date' => now()->addDays(40)->toDateString()] + $this->orderData($patient));

        // Ten days on: the first job is late and stuck, the second only stuck.
        $this->travel(10)->days();
        $rows = app(\App\Services\OpticalJobTrackingService::class)->attention();
        $this->assertCount(2, $rows);
        $this->assertSame($late->id, $rows->first()['order']->id, 'Worst first.');
        $this->assertSame(['late', 'stuck'], $rows->first()['filters']);
        $this->assertSame(['stuck'], $rows->last()['filters']);

        $page = Livewire::test(\App\Livewire\Optical\OpticalJobsComponent::class)
            ->assertSee('Pickup 8 days late')->assertSee('No change for 10 days')->assertSee('8 to 30 days behind');
        $this->assertSame(1, substr_count($page->html(), 'job-'.$late->id.'"'), 'Listed once, not in two tables.');
        $page->call('setFilter', 'late')->assertSee($late->order_id)->assertDontSee($onTime->order_id);

        // A new promised date needs a reason; the job then stops counting as late.
        $page->call('openReschedule', $late->id)->set('newPickupDate', today()->addDays(4)->toDateString())->set('rescheduleReason', '')
            ->call('saveReschedule')->assertHasErrors('rescheduleReason')
            ->set('rescheduleReason', 'Lens back-ordered at the lab')->call('saveReschedule')->assertHasNoErrors()
            ->assertSee('New pickup date for '.$late->order_id)->assertSee('Tell the customer on WhatsApp');
        $this->assertSame(today()->addDays(4)->toDateString(), \Illuminate\Support\Carbon::parse($late->fresh()->pickUpDate)->toDateString());
        $this->assertNull(app(\App\Services\OpticalJobTrackingService::class)->attention()->firstWhere('order.id', $late->id)['late']);
        $this->assertDatabaseHas('audit_trails', ['event' => 'optical.order_rescheduled']);
    }

    public function test_reports_show_sales_money_received_and_aged_balances_with_exports(): void
    {
        $user = $this->opticalManager('optical-reports');
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Report Customer', 'contact' => '0240007111', 'gender' => 'Other']);
        $recent = app(OpticalOrderService::class)->create($this->orderData($patient)); // 310 total, 50 cash
        $old = app(OpticalOrderService::class)->create($this->orderData($patient));
        DB::table('lens_orders')->where('id', $old->id)->update(['created_at' => now()->subDays(45)]);
        app(\App\Services\OpticalOrderWorkflowService::class)->recordPayment($recent->id, 30, 'momo');

        $service = app(\App\Services\OpticalReportService::class);
        $sales = $service->sales(today(), today());
        $this->assertSame(1, $sales['jobs']);
        $this->assertEquals(310, $sales['revenue']);
        $this->assertEquals(200, $sales['categories']['Frames']);
        $this->assertEquals(120, $sales['categories']['Lenses & coatings']);
        $this->assertEquals(10, $sales['discounts']);

        // Money counts the day it is received: both deposits and today's MoMo payment.
        $cash = $service->cash(today(), today());
        $this->assertEquals(130, $cash['received']);
        $this->assertEquals(100, $cash['byMethod']['Cash']);
        $this->assertEquals(30, $cash['byMethod']['Mobile Money']);
        $this->assertEquals(130, $cash['byStaff'][$user->name]);

        // Owed is every open balance today, whenever ordered, by age.
        $owed = $service->owed(today(), today());
        $this->assertEquals(490, $owed['total']);
        $this->assertEquals(230, $owed['ages']['0-30']['amount']);
        $this->assertEquals(260, $owed['ages']['31-60']['amount']);
        $this->assertEquals(230, $owed['fromPeriod']);
        $this->assertNull(\App\Services\OpticalReportService::change(310, 0));
        $this->assertEquals(50.0, \App\Services\OpticalReportService::change(150, 100));

        Livewire::test(OpticalReportsComponent::class)->set('fromDate', today()->toDateString())->set('toDate', today()->toDateString())
            ->assertSee('Owed to you')->assertSee('31–60 days')->assertSee($old->order_id)->assertSee('Mobile Money')->assertSee('Report Customer');

        $range = ['from' => today()->toDateString(), 'to' => today()->toDateString()];
        $this->get(route('optical.reports.export', ['report' => 'end-of-day', 'format' => 'print'] + $range))->assertOk()->assertSee('Net takings')->assertSee('Mobile Money');
        $this->get(route('optical.reports.export', ['report' => 'owed', 'format' => 'print']))->assertOk()->assertSee('31–60 days')->assertSee($old->order_id);
        $csv = $this->get(route('optical.reports.export', ['report' => 'sales', 'format' => 'csv'] + $range))->assertOk()->streamedContent();
        $this->assertStringContainsString('Net sales', $csv);
        $this->get(route('optical.reports.export', ['report' => 'owed', 'format' => 'pdf']))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('optical.reports.export', ['report' => 'nope', 'format' => 'pdf']))->assertNotFound();
    }

    public function test_manager_can_close_an_abandoned_job_keeping_the_deposit(): void
    {
        $user = $this->opticalManager('job-abandon');
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Gone Customer', 'contact' => '0240007791', 'gender' => 'Other']);
        $order = app(OpticalOrderService::class)->create(['pickup_date' => now()->subDays(40)->toDateString()] + $this->orderData($patient)); // 310 total, 50 paid

        Livewire::test(\App\Livewire\Optical\OpticalJobsComponent::class)
            ->assertSee('Over 30 days behind')
            ->call('openAbandon', $order->id)->set('abandonReason', 'short')->call('confirmAbandon')->assertHasErrors('abandonReason')
            ->set('abandonReason', 'Customer unreachable for six weeks')->call('confirmAbandon')->assertHasNoErrors()
            ->assertSee('closed as abandoned')->assertDontSee('job-'.$order->id.'"', false);

        $order->refresh();
        $this->assertSame('Cancelled', $order->status);
        $this->assertEquals(50, $order->cancellation_fee);
        $this->assertSame('Abandoned: Customer unreachable for six weeks', $order->cancellation_reason);
        $sale = \App\Models\Sales::findOrFail($order->sale_id);
        $this->assertEquals(50, $sale->total_amount, 'The sale stays on record at the deposit kept.');
        $this->assertSame('paid', $sale->payment_status);

        Livewire::test(\App\Livewire\Optical\OpticalReportsComponent::class)
            ->set('fromDate', today()->subDay()->toDateString())->set('toDate', today()->toDateString())
            ->assertSee('Abandoned jobs closed')->assertViewHas('depositsKept', 50.0)->assertViewHas('cancellationFees', 50.0);

        // Only a manager can close a job.
        $staff = User::factory()->create();
        $staff->assignRole(Role::firstOrCreate(['name' => 'Receptionist', 'guard_name' => 'web']));
        $other = app(OpticalOrderService::class)->create($this->orderData($patient));
        $this->actingAs($staff);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(OpticalOrderWorkflowService::class)->closeAbandoned($other->id, 'Customer never came back at all');
    }

    public function test_turnaround_separates_in_house_from_unrecorded_labs_and_uses_the_median(): void
    {
        $user = $this->opticalManager('turnaround-median');
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Median Customer', 'contact' => '0240007792', 'gender' => 'Other']);
        $workflow = app(OpticalOrderWorkflowService::class);
        $inHouse = collect([2, 3, 60])->map(function ($days) use ($patient, $workflow) {
            $order = app(OpticalOrderService::class)->create($this->orderData($patient));
            $order->forceFill(['created_at' => now()->subDays($days)])->save();
            $workflow->transition($order->id, 'In Production');
            $workflow->transition($order->id, 'Ready for Collection');
            return $order;
        });
        $unrecorded = app(OpticalOrderService::class)->create($this->orderData($patient));
        $workflow->transition($unrecorded->id, 'Sent to Lab');
        $workflow->transition($unrecorded->id, 'Ready for Collection');

        $rows = app(\App\Services\OpticalJobTrackingService::class)->turnaround(now()->subDay(), now());
        $house = $rows->firstWhere('lab', 'In-house');
        $this->assertSame(3, $house['jobs']);
        $this->assertEquals(3.0, $house['median_days'], 'One 60-day job does not drag the typical figure.');
        $this->assertEquals(21.7, $house['avg_days']);
        $this->assertSame(1, $rows->firstWhere('lab', 'Lab not recorded')['jobs']);
        $this->get(route('optical.reports'))->assertOk()->assertSee('Turnaround by lab')->assertSee('Lab not recorded');
    }

    public function test_pos_records_the_customer_discount_and_bank_transfer(): void
    {
        $manager = $this->opticalManager('pos-extras');
        \App\Models\OpticalSetting::create(['warranty_months' => 0, 'min_deposit_percentage' => 0, 'pos_max_discount_percent' => 10]);
        $category = OpticalCategory::create(['code' => 'ACC', 'name' => 'Accessories', 'is_active' => true]);
        $spray = OpticalProduct::create(['optical_category_id' => $category->id, 'name' => 'Lens Spray', 'sku' => 'SPR-1', 'selling_price' => 50, 'cost_price' => 20, 'is_active' => true]);
        app(\App\Services\OpticalProductInventoryService::class)->setBalance($spray, 10, 1, 'Opening');

        // Staff: up to 10% off. A manager can give more.
        $staff = User::factory()->create();
        $staff->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Optician', 'guard_name' => 'web']));
        $pos = Livewire::actingAs($staff)->test(\App\Livewire\Optical\OpticalPosComponent::class)
            ->call('addToCart', 'o:'.$spray->id)->call('addToCart', 'o:'.$spray->id)
            ->set('customerName', 'Ama Mensah')->set('customerPhone', '024 123 4567')
            ->set('discount', '15')->call('completeSale')->assertHasErrors('discount');
        $pos->set('discount', '10')->set('paymentMethod', 'bank_transfer')->call('completeSale')->assertHasNoErrors();

        $sale = \App\Models\Sales::where('transaction_id', 'like', 'OPOS-%')->sole();
        $this->assertSame('Ama Mensah', $sale->customer_name);
        $this->assertSame('024 123 4567', $sale->customer_phone);
        $this->assertEquals(90, $sale->total_amount);
        $this->assertEquals(10, $sale->discount_amount);
        $this->assertSame('bank_transfer', \App\Models\PaymentTransaction::where('sale_id', $sale->id)->value('payment_method'));
        $this->actingAs($manager)->get(route('optical.receipt', $sale->id))->assertOk()->assertSee('Ama Mensah')->assertSee('024 123 4567');
    }

    public function test_bench_sheet_prints_every_filtered_job_with_its_rx_and_frame(): void
    {
        $manager = $this->opticalManager('bench-sheet');
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $manager->id, 'name' => 'Bench Customer', 'contact' => '0240002222', 'gender' => 'Other']);
        $orders = collect(range(1, 14))->map(fn () => app(OpticalOrderService::class)->create($this->orderData($patient)));
        $ready = $orders->last();
        $ready->update(['status' => 'Ready']);

        // More jobs than one workbench page, all printed with the details the bench needs and no prices.
        $sheet = $this->get(route('optical.lab-workbench.print'))->assertOk()
            ->assertSee('Lab Bench Sheet · All open work')->assertSee('13 jobs')
            ->assertSee($orders->first()->order_id)->assertSee($orders[12]->order_id)->assertDontSee($ready->order_id)
            ->assertSee('Right (OD)')->assertSee('+1.00')->assertSee('Frame A')->assertSee('QC passed');
        $this->assertStringNotContainsString('200.00', $sheet->getContent());

        $this->get(route('optical.lab-workbench.print', ['stage' => 'ready']))->assertOk()
            ->assertSee('Ready for pickup')->assertSee('1 job')->assertSee($ready->order_id);
        $this->get(route('optical.lab-workbench.print', ['stage' => 'nonsense', 'searchTerm' => 'no such job']))->assertOk()
            ->assertSee('All open work')->assertSee('No jobs match these workbench filters.');
    }
}
