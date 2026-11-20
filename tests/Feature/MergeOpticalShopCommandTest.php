<?php

namespace Tests\Feature;

use App\Models\{Branch, Clinic, LensOrder, OpticalPartnerClinic, OpticalService, Sales, User};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** 20 Nov 2026 (ICARE OPTICALS into ICARE VISION): an optical shop's account folded into the clinic at the same place. */
class MergeOpticalShopCommandTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $name): array
    {
        $clinic = Clinic::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'status' => 'active']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $plan = \App\Models\SubscriptionPlan::create(['name' => $name.' plan', 'code' => \Illuminate\Support\Str::slug($name).'-plan', 'features' => ['clinical', 'optical'],
            'base_price' => 0, 'billing_interval' => 'monthly']);
        \App\Models\ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'feature_snapshot' => ['clinical', 'optical'], 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth()]);

        return [$clinic, $branch];
    }

    private function member(Clinic $clinic, Branch $branch, string $role): User
    {
        $user = User::factory()->create();
        $clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => $role]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => Role::findOrCreate($role, 'web')->id, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    private function as(User $user, Clinic $clinic, Branch $branch): void
    {
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);
        $this->actingAs($user);
    }

    public function test_the_shop_moves_into_the_clinic_and_nothing_is_lost(): void
    {
        config(['tenancy.enabled' => true]);
        [$vision, $visionBranch] = $this->account('Vision Clinic');
        [$shop, $shopBranch] = $this->account('Vision Opticals');
        $owner = $this->member($vision, $visionBranch, 'Super Admin');
        $optician = $this->member($shop, $shopBranch, 'Optician');

        // The clinic has the default service list (never used) and a lens type list its doctors use.
        $this->as($owner, $vision, $visionBranch);
        $visionDefault = OpticalService::create(['code' => 'lens_transfer', 'name' => 'Lens transfer', 'price' => 0, 'is_active' => false]);
        DB::table('lens_options')->insert(['clinic_id' => $vision->id, 'family' => 'Single Vision', 'display_name' => 'SV Clear', 'created_at' => now(), 'updated_at' => now()]);

        // The shop: its priced services, a partner, an order and its sale, its lens types.
        $this->as($optician, $shop, $shopBranch);
        $shopService = OpticalService::create(['code' => 'lens_transfer', 'name' => 'Lens transfer', 'price' => 50, 'is_active' => true]);
        $partner = OpticalPartnerClinic::create(['name' => 'Finney Hospital', 'is_active' => true, 'billing_terms' => 'on_account']);
        $order = LensOrder::create(['user_id' => $optician->id, 'order_id' => 'OPT-SHOP001', 'status' => 'Pending', 'order_source' => 'partner', 'partner_clinic_id' => $partner->id,
            'frame_model_number' => 'Frame', 'frame_price' => 100, 'lens_price' => 20, 'pickUpDate' => now()->addWeek()->toDateString()]);
        $sale = Sales::create(['user_id' => $optician->id, 'transaction_id' => 'TXN-SHOP-1', 'business_line' => 'optical', 'total_amount' => 120, 'amount_paid' => 0, 'payment_status' => 'unpaid']);
        DB::table('lens_options')->insert([
            ['clinic_id' => $shop->id, 'family' => 'Single Vision', 'display_name' => 'SV Clear', 'created_at' => now(), 'updated_at' => now()],
            ['clinic_id' => $shop->id, 'family' => 'Single Vision', 'display_name' => 'SV Photo', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $shopSettings = DB::table('settings')->where('clinic_id', $shop->id)->count();

        // Dry run: reported, nothing changed.
        $this->artisan('clinics:merge-optical-shop', ['shop' => $shop->id, 'clinic' => $vision->id])->expectsOutputToContain('DRY RUN')->assertSuccessful();
        $this->assertSame($shop->id, (int) DB::table('lens_orders')->where('id', $order->id)->value('clinic_id'));

        // Merge.
        $this->artisan('clinics:merge-optical-shop', ['shop' => $shop->id, 'clinic' => $vision->id, '--commit' => true])->assertSuccessful();
        $moved = fn (string $table, int $id) => DB::table($table)->where('id', $id)->first(['clinic_id', ...(\Illuminate\Support\Facades\Schema::hasColumn($table, 'branch_id') ? ['branch_id'] : [])]);
        $this->assertEquals((object) ['clinic_id' => $vision->id, 'branch_id' => $visionBranch->id], $moved('lens_orders', $order->id));
        $this->assertEquals((object) ['clinic_id' => $vision->id, 'branch_id' => $visionBranch->id], $moved('sales', $sale->id));
        $this->assertSame($vision->id, (int) $moved('optical_partner_clinics', $partner->id)->clinic_id);

        // The shop's priced service now serves the clinic; the clinic's unused default went to the retired account, code intact.
        $this->assertSame($vision->id, (int) DB::table('optical_services')->where('id', $shopService->id)->value('clinic_id'));
        $this->assertEquals(['clinic_id' => $shop->id, 'code' => 'lens_transfer'], (array) DB::table('optical_services')->where('id', $visionDefault->id)->first(['clinic_id', 'code']));
        // Lens types: the clinic keeps its own; the shop's new one joins; its duplicate stays behind.
        $this->assertSame(['SV Clear', 'SV Photo'], DB::table('lens_options')->where('clinic_id', $vision->id)->orderBy('display_name')->pluck('display_name')->all());
        $this->assertSame(1, DB::table('lens_options')->where('clinic_id', $shop->id)->count());

        // The optician joins the clinic, with her role at its main branch; account history stays with the shop.
        $this->assertTrue(DB::table('clinic_user')->where('clinic_id', $vision->id)->where('user_id', $optician->id)->exists());
        $this->assertTrue(DB::table('branch_user_role')->where('branch_id', $visionBranch->id)->where('user_id', $optician->id)->where('role_id', Role::findByName('Optician')->id)->exists());
        $this->assertSame($shopSettings, DB::table('settings')->where('clinic_id', $shop->id)->count());

        // Run again: refused, as there is nothing left to move.
        $this->artisan('clinics:merge-optical-shop', ['shop' => $shop->id, 'clinic' => $vision->id, '--commit' => true])->expectsOutputToContain('already merged')->assertFailed();
    }

    public function test_it_stops_on_a_table_it_does_not_know_with_nothing_changed(): void
    {
        config(['tenancy.enabled' => true]);
        [$vision, $visionBranch] = $this->account('Clinic Two');
        [$shop, $shopBranch] = $this->account('Shop Two');
        $optician = $this->member($shop, $shopBranch, 'Optician');
        $this->as($optician, $shop, $shopBranch);
        $order = LensOrder::create(['user_id' => $optician->id, 'order_id' => 'OPT-SHOP002', 'status' => 'Pending', 'order_source' => 'walk_in', 'customer_name' => 'Walk In',
            'frame_model_number' => 'Frame', 'frame_price' => 100, 'lens_price' => 20, 'pickUpDate' => now()->addWeek()->toDateString()]);
        // An expense: a table the merge does not know (yet).
        DB::table('expense_categories')->insert(['clinic_id' => $shop->id, 'name' => 'Shop rent', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('clinics:merge-optical-shop', ['shop' => $shop->id, 'clinic' => $vision->id, '--commit' => true])
            ->expectsOutputToContain('expense_categories')->assertFailed();
        $this->assertSame($shop->id, (int) DB::table('lens_orders')->where('id', $order->id)->value('clinic_id'));
    }
}
