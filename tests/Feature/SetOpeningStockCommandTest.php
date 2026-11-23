<?php

namespace Tests\Feature;

use App\Models\{AuditTrail, Category, Clinic, Product, Stock, User};
use App\Services\Inventory\BranchInventoryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** stock:opening books counted stock into products a migration brought across with zero stock. */
class SetOpeningStockCommandTest extends TestCase
{
    use RefreshDatabase, GivesClinicsAccess;

    private Clinic $clinic;
    private string $file;

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

        $drugs = Category::create(['user_id' => $user->id, 'name' => 'Drugs']);
        foreach ([['Blucipro', 'ITE-D3', 0], ['Dexatrol Ointment', 'ITE-D27', 0], ['Lens Cleaner', 'ITE-A22', 4], ['BluTears', 'ITE-D7', 0]] as [$name, $batch, $qty]) {
            Product::create(['user_id' => $user->id, 'name' => $name, 'category_id' => $drugs->id, 'batch_number' => $batch,
                'manufacture_date' => '2026-11-21', 'expiry_date' => '2028-11-21', 'quantity' => $qty, 'cost_price' => 6, 'selling_price' => 40]);
        }
        app(TenantContext::class)->clear();

        $this->file = tempnam(sys_get_temp_dir(), 'open');
        file_put_contents($this->file, "Item Name,Quantity Bought,Quantity Sold,Quantity Left\nBlucipro,40,17,23\nDexatrol  Ointment ,66,51,15\nLens Cleaner,117,29,88\nBluTears,192,192,\nNo Such Drug,5,0,5\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function qty(string $name): int
    {
        $product = Product::withoutGlobalScopes()->where('clinic_id', $this->clinic->id)->where('name', $name)->first();
        $branch = $this->clinic->branches()->first();
        app(TenantContext::class)->set($this->clinic->users()->first(), $this->clinic, $branch, [$branch->id]);
        try {
            return app(BranchInventoryService::class)->quantity($product);
        } finally {
            app(TenantContext::class)->clear();
        }
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->artisan('stock:opening', ['clinic' => $this->clinic->id, 'file' => $this->file])
            ->expectsOutputToContain('Would book 38 units across 2 products')->assertSuccessful();

        $this->assertSame(0, $this->qty('Blucipro'));
        $this->assertSame(0, Stock::withoutGlobalScopes()->count());
    }

    public function test_commit_books_each_count_as_an_opening_delivery_and_leaves_stocked_products_alone(): void
    {
        $this->artisan('stock:opening', ['clinic' => $this->clinic->id, 'file' => $this->file, '--commit' => true])
            ->expectsOutputToContain('Booked 38 units across 2 products')->assertSuccessful();

        $this->assertSame(23, $this->qty('Blucipro'));
        $this->assertSame(15, $this->qty('Dexatrol Ointment'));   // extra spaces in the file still match
        $this->assertSame(4, $this->qty('Lens Cleaner'));         // already had stock: untouched
        $this->assertSame(0, $this->qty('BluTears'));             // blank count: nothing added

        $movement = Stock::withoutGlobalScopes()->where('supplier', 'Opening stock')->where('quantity', 23)->firstOrFail();
        $this->assertSame('received', $movement->movement_type);
        $this->assertStringStartsWith('OPEN-', $movement->reference_no);
        $this->assertSame(0, (int) $movement->quantity_before);
        $this->assertSame(23, (int) $movement->quantity_after);
        $this->assertTrue(AuditTrail::withoutGlobalScopes()->where('event', 'stock.received')->where('description', 'like', 'Opening stock: 23 unit(s) of Blucipro%')->exists());

        // Running the same file again adds nothing.
        $this->artisan('stock:opening', ['clinic' => $this->clinic->id, 'file' => $this->file, '--commit' => true])
            ->expectsOutputToContain('Booked 0 units across 0 products')->assertSuccessful();
        $this->assertSame(23, $this->qty('Blucipro'));
    }

    public function test_the_eyes_of_hope_file_reads_cleanly(): void
    {
        $this->artisan('stock:opening', ['clinic' => $this->clinic->id, 'file' => base_path('database/opening-stock/eyes-of-hope-2026-11-23.csv')])
            ->expectsOutputToContain('Would book 38 units across 2 products')->assertSuccessful();
    }
}
