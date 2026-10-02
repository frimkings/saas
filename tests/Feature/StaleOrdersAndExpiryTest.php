<?php

namespace Tests\Feature;

use App\Livewire\Admin\ReminderSettingsComponent;
use App\Livewire\AttentionPanelComponent;
use App\Livewire\OldOrdersComponent;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\LensOrder;
use App\Models\OpticalProduct;
use App\Models\OpticalProductStock;
use App\Models\OpticalStockLot;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\OpticalStockLedgerService;
use App\Services\Reminders\AttentionItems;
use App\Support\Tenancy\BranchRoleManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Needs attention: old orders go to a tidy-up list; expiring stock is flagged and a Super Admin removes expired stock. */
class StaleOrdersAndExpiryTest extends TestCase
{
    use DatabaseTransactions;

    private Clinic $clinic;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-10-02 10:00:00'));
        $this->clinic = Clinic::create(['name' => 'Tidy Clinic', 'slug' => 'tidy-' . Str::random(6), 'deployment_mode' => 'hosted']);
        $this->branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Tidy plan', 'code' => 'tidy-' . Str::random(6), 'included_branches' => 1, 'included_users' => 5,
            'storage_limit_mb' => 100, 'features' => ['*'], 'base_price' => 10, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        $this->user = $this->staff('Super Admin');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $this->clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $this->branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $role = Role::findOrCreate($role, 'web');
        $user->assignRole($role);
        DB::table('branch_user_role')->insert(['branch_id' => $this->branch->id, 'user_id' => $user->id, 'role_id' => $role->id]);
        app(TenantContext::class)->set($user, $this->clinic, $this->branch, [$this->branch->id]);
        app(BranchRoleManager::class)->hydrate($user, $this->branch);
        $this->actingAs($user);

        return $user;
    }

    private function order(string $status, string $pickUp, ?string $readyAt = null, float $price = 0): LensOrder
    {
        return LensOrder::create(['order_id' => 'OPT-' . strtoupper(Str::random(8)), 'status' => $status, 'order_source' => 'walk_in',
            'customer_name' => 'Walk In', 'customer_phone' => '0240000333', 'frame_price' => $price, 'lens_price' => 0,
            'pickUpDate' => $pickUp, 'user_id' => $this->user->id, 'ready_at' => $readyAt ? Carbon::parse($readyAt) : null]);
    }

    /** A clinic product with branch stock and, optionally, batches: [expiry, quantity received]. */
    private function product(string $name, int $stock, array $lots = [], ?string $productExpiry = null): array
    {
        $id = DB::table('products')->insertGetId(['clinic_id' => $this->clinic->id, 'user_id' => $this->user->id, 'name' => $name, 'quantity' => $stock,
            'cost_price' => 5, 'selling_price' => 10, 'expiry_date' => $productExpiry, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branch_inventory_items')->insert(['clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'product_id' => $id,
            'quantity' => $stock, 'reorder_level' => 0, 'is_active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $lotIds = [];
        foreach ($lots as [$expiry, $quantity]) {
            $lotIds[] = DB::table('inventory_lots')->insertGetId(['uuid' => (string) Str::uuid(), 'clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id,
                'product_id' => $id, 'batch_number' => 'B' . count($lotIds), 'expiry_date' => $expiry, 'unit_cost' => 5, 'opening_quantity' => $quantity,
                'quantity' => $quantity, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$id, $lotIds];
    }

    public function test_old_orders_leave_the_daily_list_and_are_tidied_up_as_collected(): void
    {
        $recent = $this->order('In Lab', '2026-09-22');                                   // 10 days late
        $old = $this->order('Pending', '2026-03-13');                                      // 203 days late
        $oldReady = $this->order('Ready', '2026-06-01', readyAt: '2026-06-03 09:00', price: 300);
        $items = app(AttentionItems::class);

        $groups = $items->groups('optical');
        $this->assertSame([$recent->id], $groups['order_late']['items']->pluck('id')->all());
        $this->assertArrayNotHasKey('order_uncollected', $groups);
        $this->assertEqualsCanonicalizing([$old->id, $oldReady->id], $items->staleOrders('optical')->pluck('id')->all());
        $this->assertSame(2, $items->counts('optical')['stale']);

        // The panel points to the tidy-up list; the list marks the ticked orders collected.
        Livewire::test(AttentionPanelComponent::class, ['line' => 'optical'])->assertSeeHtml('<strong>2</strong> older open orders')->assertSee('Tidy up');
        Livewire::withQueryParams([])->test(OldOrdersComponent::class)
            ->set('line', 'optical')
            ->assertSee($old->order_id)
            ->assertSee('300.00 still to pay')
            ->call('markCollected')
            ->assertHasErrors('selected')
            ->call('selectAll')
            ->set('note', 'Collected before tracking')
            ->call('markCollected')
            ->assertHasNoErrors()
            ->assertSee('No old open orders');

        $old->refresh();
        $this->assertSame('Collected', $old->status);
        // Backdated, so no aftercare or feedback message goes out as if collected today.
        $this->assertSame('2026-03-13', $old->collected_at->toDateString());
        $this->assertSame('2027-03-13', $old->renewal_date->toDateString());
        $this->assertSame('2026-06-03', $oldReady->refresh()->collected_at->toDateString());
        $this->assertSame('In Lab', $recent->refresh()->status);
        $this->assertDatabaseHas('audit_trails', ['event' => 'spectacles.status_changed', 'auditable_id' => $old->id]);
        $this->assertSame(0, $items->counts('optical')['stale']);
    }

    public function test_stop_chasing_days_are_the_clinics_own(): void
    {
        Livewire::test(ReminderSettingsComponent::class)
            ->set('uncollected_days', 3)->set('stale_days', 3)->call('save')->assertHasErrors('stale_days')
            ->set('stale_days', 300)->set('expiry_days', 60)->call('save')->assertHasNoErrors();
        $this->assertSame(300, (int) Setting::getSettings()->reminder_stale_days);

        $old = $this->order('Pending', '2026-03-13');
        $this->assertSame([$old->id], app(AttentionItems::class)->groups('optical')['order_late']['items']->pluck('id')->all());
        $this->product('Saline', 8, [['2026-11-20', 8]]);   // 49 days away: inside a 60-day window
        $this->product('Gel', 8, [['2026-12-20', 8]]);      // 79 days away: outside it
        $expiring = $this->stockGroups()['stock_expiring'];
        $this->assertSame('Expiring within 2 months', $expiring['title']);
        $this->assertCount(1, $expiring['items']);
    }

    public function test_expiring_and_expired_clinic_stock_with_what_is_left_on_the_shelf(): void
    {
        // 15 on the shelf across two batches: the later batch holds 10, so about 5 are left of the early one.
        [, [$early, $late]] = $this->product('Timolol drops', 15, [['2026-10-20', 10], ['2027-04-01', 10]]);
        [, [$expired]] = $this->product('Atropine', 5, [['2026-09-15', 5]]);
        [$noBatches] = $this->product('Saline', 8, [], '2026-12-01');
        [, [$soldOut]] = $this->product('Sold out drops', 0, [['2026-10-10', 4]]);

        $groups = $this->stockGroups();
        $this->assertSame([$expired], $groups['stock_expired']['items']->pluck('id')->all());
        $this->assertStringContainsString('expired 15 Sep 2026', $groups['stock_expired']['items'][0]['detail']);
        $this->assertSame([$early], $groups['stock_expiring_soon']['items']->pluck('id')->all());
        $this->assertStringContainsString('About 5 left', $groups['stock_expiring_soon']['items'][0]['detail']);
        $this->assertSame([['product', $noBatches]], $groups['stock_expiring']['items']->map(fn ($i) => [$i['type'], $i['id']])->all());
        $this->assertSame('Expiring within 3 months', $groups['stock_expiring']['title']);
        $this->assertNotContains($late, collect($groups)->flatMap(fn ($g) => $g['items']->pluck('id'))->all());
        $this->assertNotContains($soldOut, collect($groups)->flatMap(fn ($g) => $g['items']->pluck('id'))->all());

        // "Noted" hides a batch until its next stage; expired stock cannot be marked done.
        $items = app(AttentionItems::class);
        $items->act('stock_expiring', $noBatches, 'done', 'Selling first', 'product');
        $this->assertArrayNotHasKey('stock_expiring', $this->stockGroups());
        $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
        $this->assertSame([$noBatches], $this->stockGroups()['stock_expiring_soon']['items']->pluck('id')->all());
        try {
            $items->act('stock_expired', $expired, 'done', null, 'inventory_lot');
            $this->fail('Expired stock was marked done.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_only_a_super_admin_removes_expired_stock(): void
    {
        [$productId, [$expired]] = $this->product('Atropine', 5, [['2026-09-15', 5]]);

        $this->staff('Secretary');
        Livewire::test(AttentionPanelComponent::class, ['line' => 'clinic'])->assertSee('Expired stock')->assertDontSee('Remove from stock');
        try {
            app(AttentionItems::class)->writeOff('inventory_lot', $expired, 'Bin');
            $this->fail('A secretary removed stock.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->staff('Super Admin');
        Livewire::test(AttentionPanelComponent::class, ['line' => 'clinic'])
            ->call('start', 'stock_expired', 'inventory_lot', $expired, 'writeoff')
            ->assertSee('Remove 5 from stock')
            ->set('note', 'Disposed of')
            ->call('confirm')
            ->assertDontSee('Expired stock');

        $this->assertSame(0, (int) DB::table('branch_inventory_items')->where('product_id', $productId)->value('quantity'));
        $this->assertSame(0, (int) DB::table('inventory_lots')->where('id', $expired)->value('quantity'));
        $this->assertDatabaseHas('stock_movements', ['product_id' => $productId, 'movement_type' => 'expired', 'quantity' => 5, 'quantity_after' => 0]);
        $this->assertDatabaseHas('audit_trails', ['event' => 'stock.expired_written_off']);
    }

    public function test_optical_stock_received_with_an_expiry_date_is_flagged_and_written_off(): void
    {
        $category = DB::table('optical_categories')->insertGetId(['clinic_id' => $this->clinic->id, 'code' => 'CL', 'name' => 'Contact lenses',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $lens = OpticalProduct::create(['clinic_id' => $this->clinic->id, 'optical_category_id' => $category, 'sku' => 'CL-' . Str::random(5),
            'name' => 'Monthly contact lenses', 'cost_price' => 20, 'selling_price' => 40, 'is_active' => true]);
        $frame = OpticalProduct::create(['clinic_id' => $this->clinic->id, 'optical_category_id' => $category, 'sku' => 'FR-' . Str::random(5),
            'name' => 'Frame', 'cost_price' => 20, 'selling_price' => 40, 'is_active' => true]);
        $ledger = app(OpticalStockLedgerService::class);
        $ledger->receive($lens, 6, ['batch_number' => 'L1', 'expiry_date' => '2026-10-25', 'unit_cost' => 20, 'unit_price' => 40]);
        $ledger->receive($frame, 3, ['unit_cost' => 20, 'unit_price' => 40]);   // frames do not expire

        $lot = OpticalStockLot::sole();
        $this->assertSame(6, $lot->quantity);
        $groups = app(AttentionItems::class)->groups('optical');
        $this->assertSame([$lot->id], $groups['stock_expiring_soon']['items']->pluck('id')->all());
        $this->assertStringContainsString('Monthly contact lenses · batch L1', $groups['stock_expiring_soon']['items'][0]['title']);

        $this->assertSame(6, app(AttentionItems::class)->writeOff('optical_lot', $lot->id, 'Returned to supplier'));
        $this->assertSame(0, OpticalProductStock::where('optical_product_id', $lens->id)->value('quantity'));
        $this->assertSame(0, $lot->refresh()->quantity);
        $this->assertDatabaseHas('optical_product_stock_movements', ['optical_product_id' => $lens->id, 'quantity_change' => -6, 'reason' => 'Expired stock removed']);
        $this->assertArrayNotHasKey('stock_expiring_soon', app(AttentionItems::class)->groups('optical'));
    }

    private function stockGroups(): array
    {
        return array_filter(app(AttentionItems::class)->groups('clinic'), fn ($rule) => str_starts_with($rule, 'stock_'), ARRAY_FILTER_USE_KEY);
    }
}
