<?php

namespace Tests\Feature;

use App\Http\Controllers\DiscountApprovalNoticeController;
use App\Livewire\POSComponent;
use App\Models\{Category, DiscountApprovalRequest, Product, Sales, User};
use App\Support\ClinicNavigation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Live bugs after the 2026-10 release: a direct purchase's discount request was deleted as
 * "stale" before a manager saw it, and admins had no menu link to the front-desk pages.
 */
class DirectPurchaseDiscountAndAdminDeskTest extends TestCase
{
    use DatabaseTransactions;

    public function test_direct_purchase_discount_reaches_the_manager_and_can_be_used_at_checkout(): void
    {
        foreach (['Cashier', 'Manager', 'Super Admin'] as $role) Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');
        $manager = User::factory()->create();
        $manager->assignRole('Manager');

        $this->actingAs($cashier);
        $frames = Category::factory()->create(['user_id' => $cashier->id, 'name' => 'Frames']);
        $frame = Product::factory()->create(['user_id' => $cashier->id, 'category_id' => $frames->id, 'quantity' => 5, 'cost_price' => 40, 'selling_price' => 200]);

        $till = Livewire::test(POSComponent::class)
            ->call('selectDirectPurchaseMode')->set('directCustomerName', 'Walk-in Ama')
            ->call('addToCart', $frame->id)
            ->set('discountType', 'percentage')->set('discountValue', 10)
            ->call('requestDiscountApproval');
        $requestId = $till->get('pendingDiscountApprovalId');
        $this->assertNotNull($requestId);

        // The manager's page poll: the request is shown, not deleted as stale.
        $this->actingAs($manager);
        $notices = new DiscountApprovalNoticeController();
        $this->assertSame($requestId, $notices->pendingNotice()['id'] ?? null);
        $response = $notices->approve(Request::create('/', 'POST'), DiscountApprovalRequest::findOrFail($requestId));
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame(DiscountApprovalRequest::STATUS_APPROVED, DiscountApprovalRequest::find($requestId)->status);

        // Back at the till: the approval arrives and the sale goes through at the discounted price.
        $this->actingAs($cashier);
        $till->call('checkDiscountApprovalStatus')->assertSet('discountApproved', true)
            ->set('newPaymentMethod', 'cash')->set('newPaymentAmount', 180)->call('addPayment');
        $key = $till->get('checkoutIdempotencyKey');
        $till->call('checkout')->assertHasNoErrors();

        $sale = Sales::where('idempotency_key', $key)->firstOrFail();
        $this->assertEquals(20.0, (float) $sale->discount_amount);
        $this->assertEquals(180.0, (float) $sale->amount_paid);
    }

    public function test_an_old_direct_purchase_request_still_counts_as_stale(): void
    {
        $request = new DiscountApprovalRequest(['patient_id' => null]);
        $request->created_at = now()->subHours(DiscountApprovalRequest::DIRECT_PURCHASE_OPEN_HOURS + 1);
        $this->assertFalse($request->isDirectPurchaseStillOpen());
        $request->created_at = now()->subHour();
        $this->assertTrue($request->isDirectPurchaseStillOpen());
    }

    public function test_admins_clinical_menu_links_to_the_front_desk_pages(): void
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);
        $this->get(route('secretary.patient-clearance')); // a front-desk page: the clinical workspace

        $routes = collect(ClinicNavigation::admin())->flatMap(fn ($item) => isset($item['items']) ? $item['items'] : [$item])->pluck('route');
        foreach (['secretary.patient-clearance', 'secretary.spectacles', 'cashier.seller-desk'] as $route) {
            $this->assertContains($route, $routes);
        }
        $this->get(route('secretary.patient-clearance'))->assertOk()->assertSee('Patient Clearance');
    }
}
