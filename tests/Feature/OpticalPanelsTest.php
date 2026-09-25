<?php

namespace Tests\Feature;

use App\Livewire\Cashier\SalesRecordsComponent;
use App\Livewire\Optical\OpticalPrescriptionsComponent;
use App\Livewire\Optical\PartnerClinicsComponent;
use App\Models\{Clinic, ClinicSubscription, LensOrder, OpticalPartnerClinic, OpticalPrescription, Patient, SubscriptionPlan, User};
use App\Services\OpticalOrderService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Side panels on the optical Prescriptions, Partner Clinics and Sales Records pages. */
class OpticalPanelsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->user = User::factory()->create();
        $clinic = Clinic::create(['name' => 'Panel Optical', 'slug' => 'panel-optical', 'deployment_mode' => 'hosted']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Panel plan', 'code' => 'panel-plan', 'included_branches' => 1, 'included_users' => 5,
            'storage_limit_mb' => 100, 'features' => ['*'], 'base_price' => 10, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        app(TenantContext::class)->set($this->user, $clinic, $branch, [$branch->id]);
        $role = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $this->user->assignRole($role);
        \Illuminate\Support\Facades\DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $this->user->id, 'role_id' => $role->id]);
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($this->user, $branch);
        $this->actingAs($this->user);
    }

    private function patient(string $name = 'Esi Rx', string $phone = '0241117777'): Patient
    {
        return Patient::createWithGeneratedPxNumber(['user_id' => $this->user->id, 'name' => $name, 'contact' => $phone, 'gender' => 'Other']);
    }

    public function test_external_prescription_is_entered_in_a_panel_with_customer_search(): void
    {
        $patient = $this->patient();

        Livewire::test(OpticalPrescriptionsComponent::class)
            ->call('openExternalModal')->assertSet('showExternalModal', true)
            ->set('customerSearch', 'Esi')->assertSee('Esi Rx')
            ->call('pickCustomer', $patient->id)->assertSet('patient_id', $patient->id)
            ->set('prescriber_name', 'Dr. Mensah')->set('prescriber_clinic', 'Korle Bu')
            ->set('od_sphere', '-1.25')->set('os_sphere', '-1.00')->set('os_cylinder', '-0.50')->set('os_axis', '90')
            ->set('rx_date', now()->addDay()->toDateString())->call('saveExternalRx')->assertHasErrors('rx_date')
            ->set('rx_date', now()->toDateString())->call('saveExternalRx')->assertHasNoErrors()
            ->assertSet('showExternalModal', false)->assertSee('Korle Bu')->assertSee('Orders using this prescription')
            ->call('closeRx')->call('setSource', 'clinic')->assertDontSee('Dr. Mensah')
            ->call('setSource', 'external')->assertSee('Dr. Mensah')->assertSee('-1.00 / -0.50 × 90°');

        $this->assertSame('Korle Bu', OpticalPrescription::firstOrFail()->prescriber_clinic);
    }

    public function test_partner_panel_shows_account_and_jobs_and_edits_in_place(): void
    {
        $partner = OpticalPartnerClinic::create(['name' => 'North Eye', 'phone' => '0241110000', 'billing_terms' => 'on_account', 'is_active' => true]);
        $archived = OpticalPartnerClinic::create(['name' => 'Old Partner', 'billing_terms' => 'pay_on_order', 'is_active' => false]);

        Livewire::test(PartnerClinicsComponent::class)
            ->assertSee('North Eye')->assertDontSee('Old Partner')
            ->call('setStatus', 'archived')->assertSee('Old Partner')->assertDontSee('North Eye')
            ->call('restore', $archived->id)->call('setStatus', 'active')->assertSee('Old Partner')
            ->call('openPartner', $partner->id)->assertSee('Recent jobs')->assertSee('New job')->assertSee(route('optical.orders.create', ['partner_id' => $partner->id]), false)
            ->call('edit', $partner->id)->assertSet('showForm', true)->set('phone', '0249998888')->call('save')->assertHasNoErrors()
            ->assertSet('viewPartnerId', $partner->id)->assertSet('showForm', false)->assertSee('0249998888');

        $this->assertTrue($archived->fresh()->is_active);
    }

    public function test_optical_sale_opens_in_a_panel_and_refund_request_is_confirmed_on_the_page(): void
    {
        $order = app(OpticalOrderService::class)->create([
            'patient_id' => $this->patient('Kojo Sale', '0241118888')->id,
            'measurements' => ['od' => ['sph' => '+1.00'], 'os' => ['sph' => '+0.75']],
            'frame_model_number' => 'Frame S', 'frame_price' => 200, 'lens_price' => 100, 'glazing_fee' => 0, 'discount_amount' => 0,
            'paid_amount' => 300, 'payment_method' => 'cash', 'pickup_date' => now()->addWeek()->toDateString(),
        ]);
        $orderSale = \App\Models\Sales::findOrFail($order->sale_id);
        $retail = \App\Models\Sales::create(['business_line' => 'optical', 'user_id' => $this->user->id, 'customer_name' => 'Walk-in buyer',
            'transaction_id' => 'OPT-RETAIL-1', 'total_amount' => 50, 'amount_paid' => 50, 'payment_status' => 'paid']);
        \App\Models\SaleItem::create(['sale_id' => $retail->id, 'prescribed_quantity' => 0, 'dispensed_quantity' => 1, 'selling_price' => 50, 'subtotal' => 50]);

        Livewire::test(SalesRecordsComponent::class, ['line' => 'optical'])->assertSet('businessLine', 'optical')
            ->assertSee($orderSale->transaction_id)->assertSee('OPT-RETAIL-1')
            ->call('openSalePanel', $orderSale->id)->assertSee($order->order_id)->assertSee('use Refund &amp; cancel', false)->assertDontSee('Request refund')
            ->call('openSalePanel', $retail->id)->assertSee('Request refund')
            ->call('initiateRefund', $retail->id)->assertSee('Send for approval')
            ->set('initiateRefundReasonCode', array_key_first(\App\Models\RefundLog::REASON_CODES))->set('initiateRefundReason', 'Customer changed their mind')
            ->call('submitRefundRequest')->assertHasNoErrors()->assertSee('Awaiting manager approval')->assertSee('Refund pending');
    }

    public function test_catalogue_item_panel_uses_reorder_levels_and_links_to_edit(): void
    {
        $category = \App\Models\OpticalCategory::create(['code' => 'FRAMES', 'name' => 'Frames', 'is_active' => true]);
        $low = \App\Models\OpticalProduct::create(['optical_category_id' => $category->id, 'name' => 'Low Frame', 'sku' => 'FRM-LOW', 'selling_price' => 100, 'cost_price' => 60, 'is_active' => true]);
        $fine = \App\Models\OpticalProduct::create(['optical_category_id' => $category->id, 'name' => 'Plenty Frame', 'sku' => 'FRM-OK', 'selling_price' => 80, 'cost_price' => 40, 'is_active' => true]);
        $empty = \App\Models\OpticalProduct::create(['optical_category_id' => $category->id, 'name' => 'Gone Frame', 'sku' => 'FRM-OUT', 'selling_price' => 90, 'cost_price' => 45, 'is_active' => true]);
        \App\Models\OpticalProductStock::create(['optical_product_id' => $low->id, 'quantity' => 8, 'reorder_level' => 10]);
        \App\Models\OpticalProductStock::create(['optical_product_id' => $fine->id, 'quantity' => 3, 'reorder_level' => 2]);
        \App\Models\OpticalProductStock::create(['optical_product_id' => $empty->id, 'quantity' => 0, 'reorder_level' => 2]);

        Livewire::test(\App\Livewire\Optical\OpticalCatalogueComponent::class)
            ->assertSee('Low Frame')->assertSee('Plenty Frame')->assertSee('Gone Frame')
            ->call('setStockFilter', 'low')->assertSee('Low Frame')->assertDontSee('Plenty Frame')->assertDontSee('Gone Frame')
            ->call('setStockFilter', 'out')->assertSee('Gone Frame')->assertDontSee('Low Frame')
            ->call('setStockFilter', '')->call('openProduct', $low->id)
            ->assertSee('At or below the reorder level (10)')->assertSee('40%')->assertSee(route('optical.products', ['edit' => $low->id]), false);

        Livewire::withQueryParams(['edit' => $low->id])->test(\App\Livewire\Optical\OpticalProductsComponent::class)
            ->assertSet('showForm', true)->assertSet('editingId', $low->id)->assertSet('name', 'Low Frame');
    }

    public function test_stock_count_sheet_can_be_closed(): void
    {
        $category = \App\Models\OpticalCategory::create(['code' => 'FRAMES', 'name' => 'Frames', 'is_active' => true]);
        $frame = \App\Models\OpticalProduct::create(['optical_category_id' => $category->id, 'name' => 'Count Frame', 'sku' => 'FRM-CNT', 'selling_price' => 100, 'cost_price' => 60, 'is_active' => true]);
        \App\Models\OpticalProductStock::create(['optical_product_id' => $frame->id, 'quantity' => 4, 'reorder_level' => 2]);
        $count = app(\App\Services\OpticalStockCountService::class)->start('all', null, 'Test count');

        Livewire::test(\App\Livewire\Optical\OpticalStockCountsComponent::class)
            ->call('openCount', $count->id)->assertSet('viewCountId', $count->id)->assertSee('Save progress')
            ->call('closeCount')->assertSet('viewCountId', null)->assertDontSee('Save progress')->assertSee($count->count_number);
    }
}
