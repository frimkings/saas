<?php

namespace Tests\Feature;

use App\Models\{AuditTrail, Category, Clinic, Product, User};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** products:services-made-to-order lets the POS sell imported services that came across as stocked items at zero. */
class MarkServicesMadeToOrderCommandTest extends TestCase
{
    use RefreshDatabase, GivesClinicsAccess;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->clinic = $this->activeClinic(['name' => 'Eyes Test', 'slug' => 'eyes-test', 'status' => 'active']);
        $branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $this->clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        app(TenantContext::class)->set($user, $this->clinic, $branch, [$branch->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        $services = Category::create(['user_id' => $user->id, 'name' => 'Services', 'type' => 'service']);
        $drugs = Category::create(['user_id' => $user->id, 'name' => 'Drugs', 'type' => 'drug']);
        foreach ([['Eye Pressure', 'ITE-S6', $services, false], ['Fluorescein/Dilation', 'ITE-S8', $services, true], ['Medical Report', 'ITE-S12', $services, false], ['Blucipro', 'ITE-D3', $drugs, false]] as [$name, $batch, $cat, $mto]) {
            Product::create(['user_id' => $user->id, 'name' => $name, 'category_id' => $cat->id, 'batch_number' => $batch, 'made_to_order' => $mto,
                'manufacture_date' => '2026-11-21', 'expiry_date' => '2028-11-21', 'quantity' => 0, 'cost_price' => 0, 'selling_price' => 30]);
        }
        app(TenantContext::class)->clear();
    }

    private function madeToOrder(string $name): bool
    {
        return (bool) Product::withoutGlobalScopes()->where('clinic_id', $this->clinic->id)->where('name', $name)->value('made_to_order');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->artisan('products:services-made-to-order', ['clinic' => $this->clinic->id])
            ->expectsOutputToContain('Would change 2 of 3 products')->assertSuccessful();
        $this->assertFalse($this->madeToOrder('Eye Pressure'));
    }

    public function test_commit_marks_services_only_and_a_rerun_changes_nothing(): void
    {
        $this->artisan('products:services-made-to-order', ['clinic' => $this->clinic->id, '--commit' => true])
            ->expectsOutputToContain('Changed 2 of 3 products')->assertSuccessful();

        $this->assertTrue($this->madeToOrder('Eye Pressure'));
        $this->assertTrue($this->madeToOrder('Medical Report'));
        $this->assertFalse($this->madeToOrder('Blucipro'));   // a drug keeps its stock count
        $this->assertTrue(AuditTrail::withoutGlobalScopes()->where('event', 'product.made_to_order')->where('description', 'like', 'Eye Pressure set to made to order%')->exists());

        $this->artisan('products:services-made-to-order', ['clinic' => $this->clinic->id, '--commit' => true])
            ->expectsOutputToContain('Changed 0 of 3 products')->assertSuccessful();
    }

    public function test_a_category_can_be_named(): void
    {
        $this->artisan('products:services-made-to-order', ['clinic' => $this->clinic->id, '--category' => 'Drugs'])
            ->expectsOutputToContain('Would change 1 of 1 products')->assertSuccessful();
    }
}
