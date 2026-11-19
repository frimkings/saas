<?php

namespace Tests\Feature;

use App\Http\Controllers\DiscountApprovalNoticeController;
use App\Livewire\Admin\DiscountApprovalsComponent;
use App\Livewire\Admin\DiscountLimitsComponent;
use App\Livewire\Admin\RefundApprovalsComponent;
use App\Livewire\POSComponent;
use App\Models\{Category, DiscountApprovalRequest, Product, RefundLog, Sales, Setting, User};
use App\Support\Access\DiscountLimits;
use App\Support\Access\MakerChecker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Roles phase 3: discount limits per role at each till, and nobody approves their own request
 * (the Owner excepted).
 */
class MoneyRulesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Super Admin', 'Manager', 'Secretary', 'Cashier', 'Optical Assistant'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        Setting::getSettings()->update(['discount_limits' => null]);
        Setting::forgetCached();
    }

    private function person(string ...$roles): User
    {
        $user = User::factory()->create();
        $user->assignRole($roles);

        return $user->fresh();
    }

    private function tillWithFrame(User $seller, float $discountPercent)
    {
        $this->actingAs($seller);
        $frames = Category::factory()->create(['user_id' => $seller->id, 'name' => 'Frames']);
        $frame = Product::factory()->create(['user_id' => $seller->id, 'category_id' => $frames->id, 'quantity' => 5, 'cost_price' => 40, 'selling_price' => 200]);

        return Livewire::test(POSComponent::class)
            ->call('selectDirectPurchaseMode')->set('directCustomerName', 'Walk-in Ama')
            ->call('addToCart', $frame->id)
            ->set('discountType', 'percentage')->set('discountValue', $discountPercent)
            ->call('requestDiscountApproval');
    }

    public function test_defaults_keep_todays_behaviour(): void
    {
        $this->assertSame(100, DiscountLimits::forUser($this->person('Super Admin'), DiscountLimits::CLINICAL));
        $this->assertSame(100, DiscountLimits::forUser($this->person('Manager'), DiscountLimits::CLINICAL));
        $this->assertSame(0, DiscountLimits::forUser($this->person('Cashier'), DiscountLimits::CLINICAL));
        $this->assertSame(10, DiscountLimits::forUser($this->person('Cashier'), DiscountLimits::OPTICAL));
        $this->assertSame(10, DiscountLimits::forUser($this->person('Optician'), DiscountLimits::OPTICAL), 'other roles get the till default');
    }

    public function test_someone_with_two_roles_gets_the_higher_limit(): void
    {
        DiscountLimits::save(['Secretary' => ['clinical' => 5, 'optical' => 5], 'Cashier' => ['clinical' => 15, 'optical' => 3]]);

        $both = $this->person('Secretary', 'Cashier');
        $this->assertSame(15, DiscountLimits::forUser($both, DiscountLimits::CLINICAL));
        $this->assertSame(5, DiscountLimits::forUser($both, DiscountLimits::OPTICAL));
        $this->assertTrue(DiscountLimits::allows($both, DiscountLimits::CLINICAL, 30, 200));
        $this->assertFalse(DiscountLimits::allows($both, DiscountLimits::CLINICAL, 30.01, 200));
    }

    public function test_a_discount_within_the_cashiers_limit_applies_at_once(): void
    {
        DiscountLimits::save(['Cashier' => ['clinical' => 10, 'optical' => 10]]);
        $cashier = $this->person('Cashier');

        $till = $this->tillWithFrame($cashier, 10)->assertSet('discountApproved', true);
        $this->assertSame($cashier->id, (int) DiscountApprovalRequest::findOrFail($till->get('pendingDiscountApprovalId'))->approved_by);
    }

    public function test_a_discount_over_the_limit_waits_for_approval(): void
    {
        DiscountLimits::save(['Cashier' => ['clinical' => 10, 'optical' => 10]]);

        $till = $this->tillWithFrame($this->person('Cashier'), 15)->assertSet('discountApproved', false);
        $this->assertSame(DiscountApprovalRequest::STATUS_PENDING, DiscountApprovalRequest::findOrFail($till->get('pendingDiscountApprovalId'))->status);
    }

    public function test_a_manager_cannot_approve_their_own_discount_but_another_manager_can(): void
    {
        DiscountLimits::save(['Manager' => ['clinical' => 5, 'optical' => 5]]);
        $maker = $this->person('Manager');
        $requestId = $this->tillWithFrame($maker, 20)->get('pendingDiscountApprovalId');

        $this->actingAs($maker);
        Livewire::test(DiscountApprovalsComponent::class)->call('approveRequest', $requestId);
        $this->assertSame(DiscountApprovalRequest::STATUS_PENDING, DiscountApprovalRequest::find($requestId)->status);
        $this->assertNull((new DiscountApprovalNoticeController())->pendingNotice(), 'no pop-up for your own request');
        $response = (new DiscountApprovalNoticeController())->approve(Request::create('/', 'POST'), DiscountApprovalRequest::findOrFail($requestId));
        $this->assertSame(403, $response->getStatusCode());

        $this->actingAs($this->person('Manager'));
        Livewire::test(DiscountApprovalsComponent::class)->call('approveRequest', $requestId);
        $this->assertSame(DiscountApprovalRequest::STATUS_APPROVED, DiscountApprovalRequest::find($requestId)->status);
    }

    public function test_a_manager_cannot_sign_off_their_own_discount_at_the_till(): void
    {
        DiscountLimits::save(['Manager' => ['clinical' => 5, 'optical' => 5]]);
        $manager = $this->person('Manager');

        $this->tillWithFrame($manager, 20)
            ->set('approvalEmail', $manager->email)->set('approvalPassword', 'password')
            ->call('approveDiscount')
            ->assertSet('approvalError', MakerChecker::MESSAGE)
            ->assertSet('discountApproved', false);
    }

    public function test_the_owner_may_approve_their_own_request(): void
    {
        $owner = $this->person('Super Admin');
        $this->assertNull(MakerChecker::problem($owner, $owner->id));
        $this->assertTrue(MakerChecker::isSelfApproval($owner, $owner->id));
        $this->assertNotNull(MakerChecker::problem($this->person('Manager', 'Cashier'), null) ?? MakerChecker::problem($m = $this->person('Manager'), $m->id));
    }

    public function test_a_manager_cannot_approve_their_own_refund(): void
    {
        $manager = $this->person('Manager');
        $this->actingAs($manager);
        $sale = Sales::create(['user_id' => $manager->id, 'transaction_id' => 'TXN-MAKER-CHECKER', 'total_amount' => 100,
            'amount_paid' => 100, 'payment_status' => 'paid']);
        $log = RefundLog::create(['sale_id' => $sale->id, 'sale_item_ids' => [], 'status' => RefundLog::STATUS_PENDING,
            'initiated_by' => $manager->id, 'request_type' => RefundLog::TYPE_REFUND, 'reason' => 'Wrong item', 'initiated_at' => now()]);

        Livewire::test(RefundApprovalsComponent::class)->call('confirmApprove', $log->id);
        $this->assertSame(RefundLog::STATUS_PENDING, $log->fresh()->status);
    }

    public function test_only_the_owner_sets_limits(): void
    {
        $this->actingAs($this->person('Manager'));
        Livewire::test(DiscountLimitsComponent::class)->assertForbidden();

        $this->actingAs($this->person('Super Admin'));
        Livewire::test(DiscountLimitsComponent::class)
            ->set('limits.Cashier.clinical', 12)->set('limits.Optical Assistant.optical', 7)
            ->call('save')->assertHasNoErrors();
        $this->assertSame(12, DiscountLimits::forRole('Cashier', DiscountLimits::CLINICAL));
        $this->assertSame(7, DiscountLimits::forRole('Optical Assistant', DiscountLimits::OPTICAL));

        Livewire::test(DiscountLimitsComponent::class)->set('limits.Cashier.clinical', 120)->call('save')->assertHasErrors();
    }
}
