<?php

namespace Tests\Feature;

use App\Livewire\Admin\AuditTrailViewerComponent;
use App\Livewire\Admin\ExpensesComponent;
use App\Livewire\Optical\OpticalPosComponent;
use App\Livewire\Optical\OpticalProductsComponent;
use App\Livewire\Optical\OpticalSettingsComponent;
use App\Livewire\Optical\PartnerClinicsComponent;
use App\Models\{AuditTrail, Clinic, ClinicSubscription, LensOrder, OpticalCategory, OpticalPartnerClinic, Patient, SubscriptionPlan, User};
use App\Services\OpticalOrderService;
use App\Services\OpticalOrderWorkflowService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Optical money, price and set-up changes reach the audit trail, which the optical menu can show on its own. */
class OpticalAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->user = User::factory()->create(['name' => 'Ama Owusu']);
        $clinic = Clinic::create(['name' => 'Audit Optical', 'slug' => 'audit-optical', 'status' => 'active']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        // An optical shop with every extra, the audit trail included.
        $plan = SubscriptionPlan::create(['name' => 'Optical all', 'code' => 'optical-all', 'features' => ['optical', '*'],
            'base_price' => 0, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth()]);
        app(TenantContext::class)->set($this->user, $clinic, $branch, [$branch->id]);
        $role = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $this->user->assignRole($role);
        \Illuminate\Support\Facades\DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $this->user->id, 'role_id' => $role->id]);
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($this->user, $branch);
        $this->actingAs($this->user);
    }

    private function events(): array
    {
        return AuditTrail::orderBy('id')->pluck('event')->all();
    }

    public function test_order_discounts_payments_quotation_changes_and_cancellations_are_audited(): void
    {
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $this->user->id, 'name' => 'Kwesi Boateng', 'contact' => '0240000000', 'gender' => 'Other']);
        $data = ['patient_id' => $patient->id, 'measurements' => ['od' => ['sph' => '+1.00'], 'os' => ['sph' => '+0.75']],
            'frame_model_number' => 'Frame A', 'frame_price' => 200, 'lens_price' => 100, 'glazing_fee' => 20, 'discount_amount' => 10,
            'paid_amount' => 50, 'payment_method' => 'cash', 'pickup_date' => now()->addWeek()->toDateString()];
        $order = app(OpticalOrderService::class)->create($data);
        app(OpticalOrderWorkflowService::class)->recordPayment($order->id, 60, 'momo');

        $discount = AuditTrail::where('event', 'optical.discount_given')->firstOrFail();
        $this->assertSame($this->user->id, $discount->user_id);
        $this->assertEquals(10, $discount->new_values['discount_amount']);
        [$deposit, $payment] = AuditTrail::where('event', 'optical.payment_recorded')->orderBy('id')->get()->all();
        $this->assertEquals(50, $deposit->new_values['paid_amount']);
        $this->assertEquals([60, 'momo', 110], [$payment->new_values['amount'], $payment->new_values['payment_method'], $payment->new_values['paid_amount']]);

        // A quotation edited to a bigger discount, then converted.
        $quote = app(OpticalOrderService::class)->create(array_merge($data, ['discount_amount' => 0, 'paid_amount' => 0]), true);
        app(OpticalOrderService::class)->updateQuotation($quote->id, array_merge($data, ['discount_amount' => 40, 'paid_amount' => 0]));
        $edit = AuditTrail::where('event', 'optical.quotation_edited')->firstOrFail();
        $this->assertEquals([0, 40], [$edit->old_values['discount_amount'], $edit->new_values['discount_amount']]);
        app(OpticalOrderWorkflowService::class)->activateQuotation($quote->id, 100, 'cash');
        $converted = AuditTrail::where('event', 'optical.quotation_converted')->firstOrFail();
        $this->assertSame($quote->order_id, $converted->old_values['order_id']);
        $this->assertSame($quote->fresh()->order_id, $converted->new_values['order_id']);

        // Cancelling an unpaid order ("delete" on the orders page) is kept even without the audit extra.
        $unpaid = LensOrder::create(['order_id' => 'OPT-UNPAID', 'status' => 'Pending', 'order_source' => 'walk_in', 'customer_name' => 'Walk In',
            'frame_model_number' => 'Frame', 'frame_price' => 80, 'lens_price' => 0, 'pickUpDate' => now()->toDateString(), 'user_id' => $this->user->id]);
        app(OpticalOrderWorkflowService::class)->cancel($unpaid->id);
        $cancel = AuditTrail::where('event', 'optical.order_cancelled')->firstOrFail();
        $this->assertSame(['Pending', 'Cancelled'], [$cancel->old_values['status'], $cancel->new_values['status']]);
    }

    public function test_products_prices_settings_partners_pos_and_expenses_are_audited(): void
    {
        $category = OpticalCategory::create(['code' => 'FRM', 'name' => 'Frames', 'is_active' => true]);
        $products = Livewire::test(OpticalProductsComponent::class)->call('add')->set('name', 'Gold frame')->set('sku', 'FRM-001')
            ->set('categoryId', (string) $category->id)->set('costPrice', '100')->set('sellingPrice', '250')->set('quantity', '3')
            ->call('save')->assertHasNoErrors();
        $product = \App\Models\OpticalProduct::where('sku', 'FRM-001')->firstOrFail();
        $products->call('edit', $product->id)->set('sellingPrice', '300')->call('save')->assertHasNoErrors();
        $products->call('edit', $product->id)->call('save')->assertHasNoErrors(); // nothing changed, nothing recorded
        $change = AuditTrail::where('event', 'optical.product_updated')->sole();
        $this->assertEquals(['selling_price' => 250], array_map('floatval', $change->old_values));
        $this->assertEquals(300, $change->new_values['selling_price']);

        Livewire::test(OpticalPosComponent::class)->call('addToCart', 'o:'.$product->id)->set('discount', '20')->call('completeSale')->assertHasNoErrors();
        $sale = AuditTrail::where('event', 'optical.pos_sale')->firstOrFail();
        $this->assertEquals([280, 20], [$sale->new_values['total_amount'], $sale->new_values['discount_amount']]);
        $this->assertSame(['1 x Gold frame'], $sale->new_values['items']);

        Livewire::test(OpticalSettingsComponent::class)->set('pos_max_discount_percent', 25)->call('saveSettings')->assertHasNoErrors();
        $this->assertSame(25, AuditTrail::where('event', 'like', 'optical.settings_%')->latest('id')->firstOrFail()->new_values['pos_max_discount_percent']);

        $partner = OpticalPartnerClinic::create(['name' => 'Eye Partner', 'billing_terms' => 'on_account', 'is_active' => true]);
        Livewire::test(PartnerClinicsComponent::class)->call('archive', $partner->id);
        $this->assertSame(['is_active' => false], AuditTrail::where('event', 'optical.partner_clinic_updated')->firstOrFail()->new_values);

        Livewire::test(ExpensesComponent::class, ['businessLine' => 'optical'])->call('addDefaultCategories')->call('openCreate')
            ->set('state.expense_category_id', (string) \App\Models\ExpenseCategory::where('name', 'Rent')->value('id'))
            ->set('state.description', 'Shop rent')->set('state.amount', '150')->call('save')->assertHasNoErrors();
        $this->assertContains('optical.expense.created', $this->events());

        $products->call('edit', $product->id)->set('quantity', '0')->call('save')->call('delete', $product->id);
        $this->assertContains('optical.product_archived', $this->events());
    }

    public function test_optical_menu_opens_the_audit_trail_showing_optical_and_staff_events_only(): void
    {
        AuditTrail::record('optical.pos_sale', 'Retail sale OPOS-1');
        AuditTrail::record('staff.added', 'Added staff member Kofi');
        AuditTrail::record('patient.updated', 'Clinic patient record changed');

        $this->get(route('optical.audit-trail'))->assertOk()->assertSee('Optical Suite');
        $this->get(route('optical.login-history'))->assertOk()->assertSee('Optical Suite');
        $this->get(route('optical.dashboard'))->assertSee(route('optical.audit-trail'), false);

        $page = Livewire::withQueryParams([])->test(AuditTrailViewerComponent::class)->set('area', 'optical')
            ->assertSee('Retail sale OPOS-1')->assertSee('Added staff member Kofi')->assertDontSee('Clinic patient record changed');
        $page->set('area', 'clinic')->assertSee('Clinic patient record changed')->assertDontSee('Retail sale OPOS-1');
        $page->set('area', '')->assertSee('Retail sale OPOS-1')->assertSee('Clinic patient record changed');
    }
}
