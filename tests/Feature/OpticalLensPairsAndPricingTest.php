<?php

namespace Tests\Feature;

use App\Livewire\Optical\LensPriceListComponent;
use App\Livewire\Optical\OpticalStockManagementComponent;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\LensOrder;
use App\Models\OpticalCategory;
use App\Models\OpticalOrderLensLine;
use App\Models\OpticalProduct;
use App\Models\OpticalProductStock;
use App\Models\Patient;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\OpticalLensAvailabilityService;
use App\Services\OpticalLensPriceList;
use App\Services\OpticalLensReceivingService;
use App\Services\OpticalOrderService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OpticalLensPairsAndPricingTest extends TestCase
{
    use RefreshDatabase;

    private const SV = ['range' => 'Photo Range', 'design' => 'Single Vision', 'index' => '1.56', 'coating' => 'Photo AR', 'diameter' => 65];

    private function manager(string $slug, string $role = 'Manager'): User
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        $clinic = Clinic::create(['name' => $slug, 'slug' => $slug, 'status' => 'active']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Optical', 'code' => 'optical-'.$slug, 'features' => ['optical'], 'base_price' => 0, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth()]);
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);
        DB::table('branch_user_role')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'role_id' => $role->id]);
        $this->actingAs($user);
        return $user;
    }

    private function stockOf(array $specs): int
    {
        ksort($specs);
        $product = OpticalProduct::where('lens_key', hash('sha256', json_encode($specs)))->first();
        return $product ? (int) OpticalProductStock::where('optical_product_id', $product->id)->value('quantity') : 0;
    }

    private function workbook(string $sheetName, string $rowsXml): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lens-xlsx-');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$sheetName.'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rowsXml.'</sheetData></worksheet>');
        $zip->close();
        $upload = UploadedFile::fake()->createWithContent('order.xlsx', file_get_contents($path));
        unlink($path);
        return $upload;
    }

    public function test_progressive_pairs_workbook_adds_one_right_and_one_left_lens_per_pair(): void
    {
        $this->manager('progressive-pairs');
        $upload = $this->workbook('PROGRESSIVE',
            '<row r="4"><c r="A4" t="inlineStr"><is><t>(+)</t></is></c><c r="B4"><v>1.00</v></c><c r="C4"><v>2.00</v></c></row>'
            .'<row r="5"><c r="A5" t="inlineStr"><is><t>+1.50</t></is></c><c r="B5"><v>3</v></c><c r="C5"><v>2</v></c></row>');

        $form = Livewire::test(OpticalStockManagementComponent::class)->call('openReceipt')
            ->set('stockType', 'lens')->set('entryMode', 'bulk')->set('lensRange', 'Vario')->set('lensDesign', 'Progressive')
            ->set('excelFile', $upload)
            ->assertSet('lensDesign', 'Progressive')->assertSet('excelUnit', 'pairs')->assertSet('lensEye', 'B')
            ->call('previewExcel')->assertHasNoErrors()
            ->assertSet('excelPreview.total', 10)->assertSet('excelPreview.quantities.66.3', 3)
            ->call('applyExcel')->assertHasNoErrors()
            ->set('unitCost', '100')->set('unitPrice', '250')->set('supplier', 'Lens Lab')->call('save')->assertHasNoErrors();

        $specs = ['range' => 'Vario', 'design' => 'Progressive', 'index' => '1.50', 'coating' => 'AR', 'diameter' => 65, 'sphere' => '1.50'];
        $this->assertSame(3, $this->stockOf($specs + ['power' => '1.00', 'eye' => 'R']));
        $this->assertSame(3, $this->stockOf($specs + ['power' => '1.00', 'eye' => 'L']));
        $this->assertSame(2, $this->stockOf($specs + ['power' => '2.00', 'eye' => 'R']));
        $this->assertSame(2, $this->stockOf($specs + ['power' => '2.00', 'eye' => 'L']));
        $this->assertEquals(10, OpticalProductStock::sum('quantity'));
        $this->assertSame(10, \App\Models\OpticalLensImport::firstOrFail()->pieces);
        $form->assertSet('showForm', false);
    }

    public function test_eye_choice_follows_the_quantity_unit_for_eye_specific_lenses(): void
    {
        $this->manager('eye-unit-sync');
        $upload = $this->workbook('BIFOCAL RIGHT',
            '<row r="4"><c r="A4" t="inlineStr"><is><t>(+)</t></is></c><c r="B4"><v>1.00</v></c><c r="C4"><v>1.25</v></c></row>'
            .'<row r="5"><c r="A5" t="inlineStr"><is><t>+0.50</t></is></c><c r="B5"><v>4</v></c></row>');

        Livewire::test(OpticalStockManagementComponent::class)->call('openReceipt')
            ->set('stockType', 'lens')->set('entryMode', 'bulk')->set('lensRange', 'Flat Top')->set('lensDesign', 'Bifocal')
            ->set('excelFile', $upload)
            // A sheet titled for one eye holds that eye's individual lenses.
            ->assertSet('lensEye', 'R')->assertSet('excelUnit', 'pieces')
            ->call('previewExcel')->assertHasNoErrors()->assertSet('excelPreview.total', 4)
            ->set('excelUnit', 'pairs')->assertSet('lensEye', 'B')
            ->set('lensEye', 'L')->assertSet('excelUnit', 'pieces');
    }

    public function test_single_power_both_eye_receipt_records_pairs_per_eye(): void
    {
        $this->manager('bifocal-single-pairs');
        Livewire::test(OpticalStockManagementComponent::class)->call('openReceipt')
            ->set('stockType', 'lens')->set('lensRange', 'Flat Top')->set('lensDesign', 'Bifocal')->set('lensEye', 'B')
            ->set('lensPower', '1.50')->set('quantity', '4')->set('unitCost', '40')->set('unitPrice', '90')
            ->set('supplier', 'Lens Lab')->call('save')->assertHasNoErrors();

        $specs = ['range' => 'Flat Top', 'design' => 'Bifocal', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65, 'sphere' => '0.00', 'power' => '1.50'];
        $this->assertSame(4, $this->stockOf($specs + ['eye' => 'R']));
        $this->assertSame(4, $this->stockOf($specs + ['eye' => 'L']));
    }

    public function test_receipts_set_the_last_purchase_cost_and_orders_keep_the_cost_they_recorded(): void
    {
        $user = $this->manager('last-cost');
        $receiving = app(OpticalLensReceivingService::class);
        $lens = $receiving->receive(self::SV + ['sphere' => '0.50', 'power' => '0.00'], 4, ['unit_cost' => 12.50, 'unit_price' => 25]);
        $this->assertEquals(12.50, $lens->fresh()->cost_price);

        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Cost Customer', 'contact' => '0240001212', 'gender' => 'Other']);
        $orderFor = function () use ($patient) {
            $measurements = ['od' => ['sph' => '+0.50'], 'os' => ['sph' => '+0.50']];
            $option = app(OpticalLensAvailabilityService::class)->stockOptions($measurements)[0];
            return app(OpticalOrderService::class)->create([
                'patient_id' => $patient->id, 'measurements' => $measurements, 'lens_fulfilment_source' => 'stock',
                'docket' => ['lens_details' => ['stock_key' => $option['key'], 'stock_split' => $option['split']]],
                'frame_model_number' => 'Own frame', 'frame_price' => 0, 'lens_price' => $option['price'], 'glazing_fee' => 0,
                'discount_amount' => 0, 'paid_amount' => 0, 'payment_method' => 'cash', 'pickup_date' => now()->addWeek()->toDateString(),
            ]);
        };
        $first = $orderFor();

        $receiving->receive(self::SV + ['sphere' => '0.50', 'power' => '0.00'], 4, ['unit_cost' => 15, 'unit_price' => 25]);
        $this->assertEquals(15, $lens->fresh()->cost_price, 'A new receipt sets the last purchase cost.');
        $second = $orderFor();

        $costs = fn (LensOrder $order) => OpticalOrderLensLine::where('lens_order_id', $order->id)->pluck('unit_cost')->map(fn ($c) => (float) $c)->all();
        $this->assertSame([12.5, 12.5], $costs($first), 'The earlier order keeps the cost it recorded.');
        $this->assertSame([15.0, 15.0], $costs($second));
    }

    public function test_migration_brings_lens_costs_up_to_the_latest_unreversed_receipt(): void
    {
        $this->manager('cost-backfill');
        $ledger = app(\App\Services\OpticalStockLedgerService::class);
        $lens = app(OpticalLensReceivingService::class)->receive(self::SV + ['sphere' => '1.00', 'power' => '0.00'], 2, ['unit_cost' => 10, 'unit_price' => 30]);
        $ledger->receive($lens, 2, ['unit_cost' => 14]);
        $reversed = $ledger->receive($lens, 1, ['unit_cost' => 99]);
        $ledger->reverse($reversed->id);
        $lens->update(['cost_price' => 10]); // as left by first-receipt-only costing

        (require database_path('migrations/2026_09_26_000001_set_lens_cost_to_last_purchase_cost.php'))->up();

        $this->assertEquals(14, $lens->fresh()->cost_price);
    }

    public function test_price_list_prices_every_power_per_pair_with_power_exceptions(): void
    {
        $this->manager('lens-price-list');
        $receiving = app(OpticalLensReceivingService::class);
        $plano = $receiving->receive(self::SV + ['sphere' => '0.00', 'power' => '0.00'], 2, ['unit_cost' => 12.50, 'unit_price' => 20]);
        $highSph = $receiving->receive(self::SV + ['sphere' => '-4.50', 'power' => '0.00'], 2, ['unit_cost' => 12.50, 'unit_price' => 20]);
        $highCyl = $receiving->receive(self::SV + ['sphere' => '-1.00', 'power' => '-2.50'], 2, ['unit_cost' => 12.50, 'unit_price' => 20]);
        $both = $receiving->receive(self::SV + ['sphere' => '5.00', 'power' => '-3.00'], 2, ['unit_cost' => 12.50, 'unit_price' => 20]);
        $key = app(OpticalLensPriceList::class)->rangeKey(self::SV);

        $list = Livewire::test(LensPriceListComponent::class)->assertSee('Photo Range')->assertSee('Not set')
            ->call('edit', $key)->set('pairPrice', '50')
            ->call('addRule')->set('rules.0.pair_price', '70')->call('save')->assertHasErrors('rules.0.min_sphere')
            ->set('rules.0.min_sphere', '4.00')
            ->call('addRule')->set('rules.1.min_power', '2.00')->set('rules.1.pair_price', '60')
            ->call('save')->assertHasNoErrors()->assertSee('4 lens powers repriced');

        $this->assertEquals(25, $plano->fresh()->selling_price, 'One lens is half the pair price.');
        $this->assertEquals(35, $highSph->fresh()->selling_price);
        $this->assertEquals(30, $highCyl->fresh()->selling_price);
        $this->assertEquals(35, $both->fresh()->selling_price, 'The highest matching exception wins.');

        // Receipts no longer overwrite the list price, and new powers take it.
        $receiving->receive(self::SV + ['sphere' => '0.00', 'power' => '0.00'], 1, ['unit_cost' => 13, 'unit_price' => 99], true);
        $this->assertEquals(25, $plano->fresh()->selling_price);
        $new = $receiving->receive(self::SV + ['sphere' => '-0.75', 'power' => '0.00'], 1, ['unit_cost' => 13, 'unit_price' => 99]);
        $this->assertEquals(25, $new->fresh()->selling_price);

        // A power the range does not stock is special-ordered at the list price.
        $option = app(OpticalLensAvailabilityService::class)->stockOptions(['od' => ['sph' => '0.00'], 'os' => ['sph' => '-6.00']])[0];
        $this->assertSame('special_order', $option['eyes']['os']['source']);
        $this->assertEquals(35, $option['eyes']['os']['unit_price']);
        $this->assertEquals(60, $option['price']);

        $list->call('edit', $key)->assertSet('pairPrice', '50.00')->assertCount('rules', 2);
    }

    public function test_only_managers_can_change_lens_prices(): void
    {
        $this->manager('lens-price-staff', 'Secretary');
        app(OpticalLensReceivingService::class)->receive(self::SV + ['sphere' => '0.00', 'power' => '0.00'], 1, ['unit_cost' => 10, 'unit_price' => 20]);
        Livewire::test(LensPriceListComponent::class)->assertSee('Photo Range')->assertDontSee('Set price')
            ->call('edit', app(OpticalLensPriceList::class)->rangeKey(self::SV))->assertForbidden();
    }

    public function test_single_vision_lenses_sell_singly_at_half_the_pair_price_but_progressive_only_in_pairs(): void
    {
        $this->manager('pair-only-sales');
        $receiving = app(OpticalLensReceivingService::class);
        $single = $receiving->receive(self::SV + ['sphere' => '0.50', 'power' => '0.00'], 2, ['unit_cost' => 12.50, 'unit_price' => 20]);
        $progressive = ['range' => 'Vario', 'design' => 'Progressive', 'index' => '1.50', 'coating' => 'AR', 'diameter' => 65, 'sphere' => '0.50', 'power' => '2.00'];
        $right = $receiving->receive($progressive + ['eye' => 'R'], 2, ['unit_cost' => 100, 'unit_price' => 200]);
        Livewire::test(LensPriceListComponent::class)->call('edit', app(OpticalLensPriceList::class)->rangeKey(self::SV))
            ->set('pairPrice', '50')->call('save')->assertHasNoErrors();

        $pos = Livewire::test(\App\Livewire\Optical\OpticalPosComponent::class)
            ->set('searchTerm', 'Vario')->assertDontSee($right->name)
            ->call('addToCart', 'o:'.$right->id)->assertHasErrors('cart')
            ->set('searchTerm', 'Photo Range')->assertSee($single->name)
            ->call('addToCart', 'o:'.$single->id);
        $this->assertEquals(25, $pos->get('cart')['o:'.$single->id]['price'], 'One single vision lens is half the pair price.');

        // Neither can be picked as a whole "catalogue lens" on an order: that path charges and deducts one item.
        $this->expectsValidationOn(fn () => app(OpticalOrderService::class)->create([
            'patient_id' => Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Pair Customer', 'contact' => '0240003434', 'gender' => 'Other'])->id,
            'measurements' => ['od' => ['sph' => '+0.50', 'add' => '2.00'], 'os' => ['sph' => '+0.50', 'add' => '2.00']],
            'lens_fulfilment_source' => 'catalogue', 'lens_optical_product_id' => $right->id,
            'frame_model_number' => 'Own frame', 'frame_price' => 0, 'lens_price' => 0, 'glazing_fee' => 0,
            'discount_amount' => 0, 'paid_amount' => 0, 'payment_method' => 'cash', 'pickup_date' => now()->addWeek()->toDateString(),
        ]), 'lens_optical_product_id');
    }

    public function test_add_prescription_without_multifocal_stock_explains_and_suggests_reading_lenses(): void
    {
        $this->manager('add-no-stock');
        app(OpticalLensReceivingService::class)->receive(self::SV + ['sphere' => '1.50', 'power' => '0.00'], 4, ['unit_cost' => 12.50, 'unit_price' => 25]);
        $check = fn () => Livewire::test(\App\Livewire\Optical\OpticalOrderCreateComponent::class)
            ->set('rx_od_sph', '0')->set('rx_od_add', '+1.50')->set('rx_os_sph', '0')->set('rx_os_add', '+1.50')
            ->call('checkLensAvailability')->get('lensAvailability')['message'] ?? '';

        $message = $check();
        $this->assertStringContainsString('ADD +1.50 needs a progressive or bifocal lens, and this branch has no progressive or bifocal lenses in stock', $message);
        $this->assertStringContainsString('enter SPH +1.50 with no ADD to use single vision lenses, which are in stock', $message);

        // Once multifocal stock exists, the message says these particular powers are missing.
        app(OpticalLensReceivingService::class)->receive(['range' => 'Vario', 'design' => 'Progressive', 'index' => '1.50', 'coating' => 'AR', 'diameter' => 65, 'sphere' => '2.00', 'power' => '2.00', 'eye' => 'R'], 1, ['unit_cost' => 100, 'unit_price' => 200]);
        $this->assertStringContainsString('none are in stock at this branch for these powers', $check());
    }

    public function test_lens_matrix_keeps_the_chosen_range_and_shows_pairs_with_spare_lenses(): void
    {
        $this->manager('matrix-pairs');
        $receiving = app(OpticalLensReceivingService::class);
        $receiving->receive(self::SV + ['sphere' => '0.50', 'power' => '0.00'], 7, ['unit_cost' => 12.50, 'unit_price' => 25]);
        $bifocal = ['range' => 'Flat Top', 'design' => 'Bifocal', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65, 'sphere' => '1.00', 'power' => '2.00'];
        $receiving->receive($bifocal + ['eye' => 'R'], 5, ['unit_cost' => 40, 'unit_price' => 90]);
        $receiving->receive($bifocal + ['eye' => 'L'], 3, ['unit_cost' => 40, 'unit_price' => 90]);
        $keys = app(OpticalLensPriceList::class);

        $matrix = Livewire::test(\App\Livewire\Optical\OpticalCatalogueComponent::class)->set('activeTab', 'lens-matrix')
            ->set('matrixRangeKey', $keys->rangeKey(self::SV))->assertSet('matrixDesign', 'Single Vision')
            ->assertViewHas('matrixCells', fn ($cells) => $cells['0.50|0.00'] === ['value' => 3, 'extra' => 1, 'extraEye' => null])
            ->assertSee('3 pairs + 1 single lens')
            ->set('matrixView', 'lenses')->assertViewHas('matrixCells', fn ($cells) => $cells['0.50|0.00']['value'] === 7);

        // Choosing the bifocal range keeps it: nothing snaps back to single vision.
        $matrix->set('matrixRangeKey', $keys->rangeKey($bifocal))->assertSet('matrixDesign', 'Bifocal')->assertSet('matrixView', 'pairs')
            ->assertViewHas('matrixCells', fn ($cells) => $cells['1.00|2.00'] === ['value' => 3, 'extra' => 2, 'extraEye' => 'R'])
            ->assertSee('3 pairs + 2 unpaired lenses')
            ->set('matrixView', 'L')->assertSet('matrixEye', 'L')->assertViewHas('matrixCells', fn ($cells) => $cells['1.00|2.00']['value'] === 3)
            ->call('$refresh')->assertSet('matrixDesign', 'Bifocal');

        // A link naming only the range and design fills in the rest of that range.
        Livewire::withQueryParams(['activeTab' => 'lens-matrix', 'matrixRange' => 'Flat Top', 'matrixDesign' => 'Bifocal', 'matrixCoating' => 'Photo AR', 'matrixDiameter' => 0])
            ->test(\App\Livewire\Optical\OpticalCatalogueComponent::class)
            ->assertSet('matrixDesign', 'Bifocal')->assertSet('matrixCoating', 'AR')->assertSet('matrixDiameter', 65)
            ->assertViewHas('matrixCellTotal', ['value' => 3, 'extra' => 2])
            ->assertDontSeeHtml('wire:poll');
    }

    public function test_powers_selected_on_the_grid_are_ordered_in_pairs(): void
    {
        $this->manager('grid-order');
        $receiving = app(OpticalLensReceivingService::class);
        $ledger = app(\App\Services\OpticalStockLedgerService::class);
        $out = $receiving->receive(self::SV + ['sphere' => '0.50', 'power' => '0.00'], 2, ['unit_cost' => 12.50, 'unit_price' => 25]);
        $ledger->adjust($out, -2, 'Sold out');
        $receiving->receive(self::SV + ['sphere' => '0.75', 'power' => '0.00'], 40, ['unit_cost' => 12.50, 'unit_price' => 25]);
        $low = $receiving->receive(self::SV + ['sphere' => '1.00', 'power' => '0.00'], 4, ['unit_cost' => 15, 'unit_price' => 25]);
        $keys = app(OpticalLensPriceList::class);
        $supplier = \App\Models\Supplier::create(['name' => 'Lens Lab', 'is_active' => true]);

        // The browser picks powers from this data: [pairs, spare, spare eye, reorder, on order, pair cost].
        $grid = Livewire::test(\App\Livewire\Optical\OpticalCatalogueComponent::class)->set('activeTab', 'lens-matrix')
            ->set('matrixRangeKey', $keys->rangeKey(self::SV))
            ;
        $data = $grid->viewData('selectionGrid');
        $this->assertEquals([
            '0.50|0.00' => [0, 0, null, 5, 0, 25.0], '0.75|0.00' => [20, 0, null, 5, 0, 25.0], '1.00|0.00' => [2, 0, null, 5, 0, 30.0],
        ], (array) $data['cells']);
        $this->assertEquals(25.0, $data['typical']);

        // The server checks the browser's picks again: a power that does not exist is dropped.
        $response = $grid->call('createOrderFromSelection', ['0.50|0.00', '1.00|0.00', '-3.10|0.00'], 10)->effects['redirect'];
        parse_str(parse_url($response, PHP_URL_QUERY), $query);
        $purchase = Livewire::withQueryParams($query)->test(\App\Livewire\Optical\OpticalPurchasingComponent::class)
            ->assertSet('showOrderForm', true)->assertCount('draftLines', 2)
            ->assertSet('draftLines.0.pairs', 10)->assertSet('draftLines.1.pair_cost', '30.00')
            ->set('supplierId', $supplier->id)->call('saveDraft', true)->assertHasNoErrors();

        $order = \App\Models\OpticalPurchaseOrder::with('lines.product')->firstOrFail();
        $this->assertSame([20, 20], $order->lines->pluck('quantity_ordered')->all(), 'Saved as lenses: 10 pairs = 20 lenses.');
        $this->assertEquals(15, $order->lines->firstWhere('optical_product_id', $low->id)->unit_cost, 'Cost per lens is half the pair cost.');
        $this->assertSame(['10 pairs', '10 pairs'], $order->supplierLines()->pluck('quantity')->all());

        // Once sent, the grid shows the pairs on order, and the browser data carries them so "All out" can skip them.
        $grid->call('$refresh')->assertViewHas('matrixOnOrder', ['0.50|0.00' => 10, '1.00|0.00' => 10])->assertSeeHtml('⏱10')
            ->assertViewHas('selectionGrid', fn ($data) => ((array) $data['cells'])['0.50|0.00'][4] === 10);
        $grid->call('downloadSelectionSheet', ['0.50|0.00'], 10)->assertFileDownloaded('photo-range-single-vision-photo-ar-order.xlsx');
        $grid->call('createOrderFromSelection', ['0.50|0.00'], 2.5)->assertHasErrors('orderPairsEach');
        $grid->call('createOrderFromSelection', ['-3.10|0.00'], 10)->assertHasErrors('selectedCells');
    }

    public function test_a_power_never_stocked_can_be_ordered_and_is_created_only_when_the_order_is_saved(): void
    {
        $this->manager('grid-new-power');
        $receiving = app(OpticalLensReceivingService::class);
        $receiving->receive(self::SV + ['sphere' => '0.75', 'power' => '0.00'], 10, ['unit_cost' => 12, 'unit_price' => 25]);
        $receiving->receive(self::SV + ['sphere' => '1.00', 'power' => '0.00'], 10, ['unit_cost' => 14, 'unit_price' => 25]);
        $supplier = \App\Models\Supplier::create(['name' => 'Lens Lab', 'is_active' => true]);
        $products = OpticalProduct::count();

        $grid = Livewire::test(\App\Livewire\Optical\OpticalCatalogueComponent::class)->set('activeTab', 'lens-matrix')
            ->set('matrixRangeKey', app(OpticalLensPriceList::class)->rangeKey(self::SV))
            ->assertViewHas('selectionGrid', fn ($data) => $data['typical'] == 26.0); // typical lens 13.00 → 26.00 a pair

        $response = $grid->call('createOrderFromSelection', ['-2.00|-0.50'], 3)->effects['redirect'];
        parse_str(parse_url($response, PHP_URL_QUERY), $query);
        $purchase = Livewire::withQueryParams($query)->test(\App\Livewire\Optical\OpticalPurchasingComponent::class)
            ->assertCount('draftLines', 1)->assertSee('SPH -2.00 CYL -0.50 · new power')->assertSet('draftLines.0.pair_cost', '26.00');
        $this->assertSame($products, OpticalProduct::count(), 'Nothing is created for a draft that is never saved.');

        $purchase->set('supplierId', $supplier->id)->call('saveDraft')->assertHasNoErrors();
        $this->assertSame($products + 1, OpticalProduct::count());
        $line = \App\Models\OpticalPurchaseOrder::with('lines.product')->firstOrFail()->lines->sole();
        $this->assertSame(6, (int) $line->quantity_ordered, '3 pairs = 6 lenses.');
        $this->assertEquals(13, $line->unit_cost);
        $this->assertSame(['-2.00', '-0.50'], [$line->product->lens_specs['sphere'], $line->product->lens_specs['power']]);
    }

    public function test_progressive_and_bifocal_orders_cover_both_eyes(): void
    {
        $this->manager('grid-order-bifocal');
        $receiving = app(OpticalLensReceivingService::class);
        $bifocal = ['range' => 'Flat Top', 'design' => 'Bifocal', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65];
        // Only the right eye was ever stocked at ADD 2.00: ordering pairs creates the left item too.
        $right = $receiving->receive($bifocal + ['sphere' => '1.00', 'power' => '2.00', 'eye' => 'R'], 1, ['unit_cost' => 40, 'unit_price' => 90]);
        $keys = app(OpticalLensPriceList::class);
        $supplier = \App\Models\Supplier::create(['name' => 'Bifocal Lab', 'is_active' => true]);

        $lines = app(\App\Services\OpticalLensOrderPlanner::class)->draftLines($bifocal, ['1.00|2.00' => 5]);
        $this->assertCount(1, $lines);
        $this->assertSame('80.00', $lines[0]['pair_cost']);
        $this->assertStringContainsString('R + L', $lines[0]['label']);

        // The reorder list button orders the same powers in pairs for both eyes.
        $redirect = Livewire::test(\App\Livewire\Optical\OpticalCatalogueComponent::class)->set('activeTab', 'lens-matrix')
            ->set('matrixRangeKey', $keys->rangeKey($bifocal))->call('orderFromReorderList')->effects['redirect'];
        parse_str(parse_url($redirect, PHP_URL_QUERY), $query);
        Livewire::withQueryParams($query)->test(\App\Livewire\Optical\OpticalPurchasingComponent::class)
            ->assertCount('draftLines', 1)->assertSet('draftLines.0.pairs', 10)
            ->set('supplierId', $supplier->id)->call('saveDraft')->assertHasNoErrors();

        $order = \App\Models\OpticalPurchaseOrder::with('lines.product')->firstOrFail();
        $this->assertSame(['L' => 10, 'R' => 10], $order->lines->mapWithKeys(fn ($l) => [$l->product->lens_specs['eye'] => $l->quantity_ordered])->sortKeys()->all());
        $rows = $order->supplierLines();
        $this->assertCount(1, $rows, 'Right and left print as one pair row.');
        $this->assertSame('10 pairs', $rows[0]['quantity']);
        $this->assertEquals(80, $rows[0]['unit_cost']);
    }

    private function expectsValidationOn(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail("Expected a validation error on $field.");
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_converting_a_valid_quote_keeps_its_prices_unless_repriced(): void
    {
        $user = $this->manager('quote-reprice');
        $category = OpticalCategory::create(['code' => 'FRM', 'name' => 'Frames', 'is_active' => true]);
        $frame = OpticalProduct::create(['optical_category_id' => $category->id, 'name' => 'Quote Frame', 'sku' => 'QF-2', 'selling_price' => 200, 'cost_price' => 80, 'is_active' => true]);
        app(\App\Services\OpticalProductInventoryService::class)->setBalance($frame, 3, 1, 'Opening');
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Quote Customer', 'contact' => '0240007790', 'gender' => 'Other']);
        $data = ['patient_id' => $patient->id, 'measurements' => ['od' => ['sph' => '+1.00'], 'os' => ['sph' => '+0.75']],
            'frame_model_number' => 'Quote Frame', 'frame_optical_product_id' => $frame->id, 'docket' => ['frame_source' => 'stock'], 'frame_price' => 200,
            'lens_price' => 0, 'glazing_fee' => 0, 'discount_amount' => 0, 'paid_amount' => 0, 'payment_method' => 'cash',
            'pickup_date' => now()->addWeek()->toDateString()];
        $keep = app(OpticalOrderService::class)->create($data, true);
        $reprice = app(OpticalOrderService::class)->create($data, true);
        $frame->update(['selling_price' => 260]);

        Livewire::test(\App\Livewire\Optical\OpticalOrdersComponent::class)
            ->call('convertQuotationToOrder', $keep->id)->assertSet('convertPricing', 'keep')->assertSee('Current prices for catalogue items')
            ->call('confirmConvertQuotation')->assertHasNoErrors()
            ->call('convertQuotationToOrder', $reprice->id)->set('convertPricing', 'reprice')->call('confirmConvertQuotation')->assertHasNoErrors();

        $this->assertEquals(200, $keep->fresh()->frame_price, 'Quoted price kept.');
        $this->assertEquals(260, $reprice->fresh()->frame_price, 'Repriced at the current price.');
    }

    public function test_bulk_lens_receiving_opens_its_own_page_and_returns_to_the_ledger(): void
    {
        $this->manager('receive-page');

        // Choosing the bulk grid in the modal moves to the full page with the range filled in.
        Livewire::test(OpticalStockManagementComponent::class)->call('openReceipt')
            ->set('stockType', 'lens')->set('lensRange', 'Photo Range')->set('lensCoating', 'Photo AR')->set('entryMode', 'bulk')
            ->assertRedirect(route('optical.stock.receive-lenses', ['lensRange' => 'Photo Range', 'lensDesign' => 'Single Vision', 'lensIndex' => '1.56', 'lensCoating' => 'Photo AR', 'lensDiameter' => '65']));

        $this->get(route('optical.stock.receive-lenses', ['lensRange' => 'Photo Range', 'lensCoating' => 'Photo AR']))
            ->assertOk()->assertSee('Receive lenses')->assertSee('Price overrides')->assertSee('Photo Range');

        $upload = $this->workbook('PHOTO AR',
            '<row r="4"><c r="A4" t="inlineStr"><is><t>(+)</t></is></c><c r="B4"><v>0</v></c><c r="C4"><v>-0.25</v></c></row>'
            .'<row r="5"><c r="A5" t="inlineStr"><is><t>+0.00</t></is></c><c r="B5"><v>3</v></c><c r="C5"><v>2</v></c></row>');
        Livewire::withQueryParams(['lensRange' => 'Photo Range', 'lensCoating' => 'Photo AR'])
            ->test(\App\Livewire\Optical\OpticalLensReceivingComponent::class)
            ->assertSet('entryMode', 'bulk')->assertSet('lensRange', 'Photo Range')
            ->set('excelFile', $upload)->set('excelLayout', 'template')->set('excelUnit', 'pairs')
            ->call('previewExcel')->call('applyExcel')->assertHasNoErrors()
            ->set('unitCost', '12.50')->set('unitPrice', '30')->set('supplier', 'Lens Lab')
            ->assertSee('10 lenses')->assertSee('(5 pairs)')->assertSee('125.00')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('optical.stock'));

        $this->assertSame(6, $this->stockOf(self::SV + ['sphere' => '0.00', 'power' => '0.00']));
        $this->assertSame(4, $this->stockOf(self::SV + ['sphere' => '0.00', 'power' => '-0.25']));
        $this->get(route('optical.stock'))->assertSee('View received lenses in power matrix');
    }

    public function test_lens_receiving_page_is_for_managers_only(): void
    {
        $this->manager('receive-page-staff', 'Receptionist');
        $this->get(route('optical.stock.receive-lenses'))->assertForbidden();
    }
}
