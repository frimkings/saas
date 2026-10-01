<?php

namespace Tests\Feature;

use App\Models\{Clinic, DiscountApprovalRequest, Patient, Product, User};
use App\Support\ApprovalCounts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** The cached approvals badge refreshes on change, and the indexed searches match the plain LIKE ones. */
class CachedCountsAndSearchTest extends TestCase
{
    use RefreshDatabase, GivesClinicsAccess;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->user = User::factory()->create();
        $clinic = $this->activeClinic(['name' => 'Count Clinic', 'slug' => 'count-clinic', 'deployment_mode' => 'hosted']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        app(TenantContext::class)->set($this->user, $clinic, $branch, [$branch->id]);
    }

    private function discountRequest(): DiscountApprovalRequest
    {
        return DiscountApprovalRequest::create(['cashier_id' => $this->user->id, 'discount_type' => 'percentage', 'discount_value' => 10,
            'discount_amount' => 20, 'gross_amount' => 200, 'final_amount' => 180, 'cart_snapshot' => [], 'status' => DiscountApprovalRequest::STATUS_PENDING]);
    }

    public function test_approval_counts_are_cached_and_refresh_when_a_request_changes(): void
    {
        $this->assertSame(0, ApprovalCounts::total());

        DB::enableQueryLog();
        ApprovalCounts::total();
        $this->assertSame([], DB::getQueryLog(), 'A second read comes from the cache.');

        $request = $this->discountRequest();
        $this->assertSame(1, ApprovalCounts::pending()['discount']);

        $request->update(['status' => DiscountApprovalRequest::STATUS_APPROVED]);
        $this->assertSame(0, ApprovalCounts::total());
    }

    public function test_quick_patient_search_matches_name_phone_and_number(): void
    {
        $ama = Patient::createWithGeneratedPxNumber(['user_id' => $this->user->id, 'name' => 'Ama Owusu', 'contact' => '0241112222', 'gender' => 'Female']);
        $kofi = Patient::createWithGeneratedPxNumber(['user_id' => $this->user->id, 'name' => 'Kofi Mensah', 'contact' => '0559998888', 'gender' => 'Male']);

        $this->assertSame([$ama->id], Patient::quickSearch('owu')->pluck('id')->all());
        $this->assertSame([$kofi->id], Patient::quickSearch('99988')->pluck('id')->all());
        $this->assertSame([$kofi->id], Patient::quickSearch($kofi->pxnumber)->pluck('id')->all());
        $this->assertCount(2, Patient::quickSearch('')->get());
        $this->assertCount(0, Patient::quickSearch('zzz')->get());
    }

    public function test_product_search_matches_name_or_batch(): void
    {
        $drops = Product::create(['user_id' => $this->user->id, 'name' => 'Timolol drops', 'batch_number' => 'TM-01', 'quantity' => 5, 'selling_price' => 10, 'cost_price' => 5]);
        $frame = Product::create(['user_id' => $this->user->id, 'name' => 'Ray frame', 'batch_number' => 'FR-77', 'quantity' => 2, 'selling_price' => 50, 'cost_price' => 20]);

        $this->assertSame([$drops->id], Product::searchNameOrBatch('molo')->pluck('id')->all());
        $this->assertSame([$frame->id], Product::searchNameOrBatch('fr-7')->pluck('id')->all());
        $this->assertCount(2, Product::searchNameOrBatch('')->get());
    }
}
