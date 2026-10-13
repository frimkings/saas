<?php

namespace Tests\Feature;

use App\Livewire\Optical\LensOptionsComponent;
use App\Livewire\Optical\OpticalCatalogueComponent;
use App\Livewire\Optical\OpticalLensGridComponent;
use App\Livewire\Optical\OpticalProductsComponent;
use App\Livewire\Optical\OpticalStockManagementComponent;
use App\Models\{Clinic, ClinicSubscription, OpticalLensOption, OpticalProduct, SubscriptionPlan, User};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A walk through the optical suite with stock in every category (a frame, an accessory, a
 * catalogue lens, single vision and bifocal stock lenses): every page opens, and the
 * screens that read a product's category or classification draw it.
 */
class OpticalSuiteSweepTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->manager = User::factory()->create();
        $clinic = Clinic::create(['name' => 'Sweep Optical', 'slug' => 'sweep-optical', 'status' => 'active']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($this->manager->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($this->manager->id, ['status' => 'active', 'is_default' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Optical', 'code' => 'optical-sweep', 'features' => ['optical', '*'], 'base_price' => 0, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth()]);
        app(TenantContext::class)->set($this->manager, $clinic, $branch, [$branch->id]);
        $role = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $this->manager->assignRole($role);
        \Illuminate\Support\Facades\DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $this->manager->id, 'role_id' => $role->id]);
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($this->manager, $branch);
        $this->actingAs($this->manager);
    }

    /** A frame, an accessory and a catalogue lens through the product form; stock lenses through receiving. */
    private function stock(): void
    {
        $form = Livewire::test(OpticalProductsComponent::class);
        $new = fn (string $name, string $sku, string $type) => $form->call('add')->set('name', $name)->set('sku', $sku)->set('type', $type)
            ->set('costPrice', '40')->set('sellingPrice', '100')->set('quantity', '2')->set('reorderLevel', '5');
        $new('Aviator', 'FR-1', 'frame')->set('newFrameBrand', 'Ray-Ban')->call('addFrameBrand')->set('frameModel', 'RB3025')->set('frameColour', 'Gold')->set('frameSize', '58-14-135')->call('save')->assertHasNoErrors();
        $new('Cloth', 'CL-1', 'accessory')->call('save')->assertHasNoErrors();
        $new('SV Photo AR', 'SV-PAR', 'lens')->set('lensDesign', 'Single Vision')->set('lensTreatment', 'Photo AR')->call('save')->assertHasNoErrors();

        $receive = fn (array $specs, int $quantity) => ['specs' => $specs + ['range' => 'CANADA', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65],
            'quantity' => $quantity, 'details' => ['unit_cost' => 10, 'unit_price' => 25, 'supplier' => 'Lab']];
        app(\App\Services\OpticalLensReceivingService::class)->receiveMany([
            $receive(['design' => 'Single Vision', 'sphere' => '-1.00', 'power' => '0.00'], 7),
            $receive(['design' => 'Single Vision', 'sphere' => '-1.25', 'power' => '-0.50'], 1),
            $receive(['design' => 'Bifocal', 'form' => 'Flat top', 'sphere' => '0.00', 'power' => '1.00', 'eye' => 'R'], 3),
            $receive(['design' => 'Bifocal', 'form' => 'Flat top', 'sphere' => '0.00', 'power' => '1.00', 'eye' => 'L'], 2),
        ]);
    }

    public function test_every_optical_page_opens_with_stock_in_every_category(): void
    {
        $this->stock();
        $this->assertSame(['accessory' => 1, 'frame' => 1, 'lens' => 5], OpticalProduct::query()->selectRaw('type, count(*) as n')->groupBy('type')->orderBy('type')->pluck('n', 'type')->all());

        $pages = ['optical.dashboard', 'optical.attention', 'optical.orders', 'optical.orders.create', 'optical.pos', 'optical.sales', 'optical.prescriptions',
            'optical.partners', 'optical.lab-workbench', 'optical.jobs', 'optical.collections', 'optical.catalogue', 'optical.lens-grid', 'optical.products',
            'optical.stock', 'optical.stock.receive-lenses', 'optical.purchasing', 'optical.stock-counts', 'optical.reports', 'optical.profit',
            'optical.expenses', 'optical.settings', 'optical.audit-trail', 'optical.login-history'];
        foreach ($pages as $route) {
            $response = $this->get(route($route));
            $this->assertSame(200, $response->status(), $route.' did not open: '.substr(strip_tags((string) $response->exception?->getMessage()), 0, 300));
        }

        // The menu has the grid, and the old Categories page is gone.
        $this->get(route('optical.catalogue'))->assertSee('View Grid')->assertDontSee('optical/categories', false);
        $this->get('/optical/categories')->assertNotFound();
        $this->get(route('optical.catalogue', ['activeTab' => 'lens-matrix', 'matrixDesign' => 'Bifocal']))->assertRedirect(route('optical.lens-grid', ['matrixDesign' => 'Bifocal']));
    }

    public function test_catalogue_tabs_products_and_pos_show_each_category(): void
    {
        $this->stock();
        $frame = OpticalProduct::where('sku', 'FR-1')->firstOrFail();

        $catalogue = Livewire::test(OpticalCatalogueComponent::class)->assertSee('Aviator')->assertDontSee('Cloth')->assertDontSee('Lens blank stock');
        $catalogue->set('viewProductId', $frame->id)->assertSee('Frames');
        $catalogue->call('setTab', 'lenses')->assertSee('SV Photo AR')->assertDontSee('Aviator');
        foreach (['lens-prices', 'options', 'services', 'frames'] as $tab) $catalogue->call('setTab', $tab)->assertHasNoErrors();
        $catalogue->call('setTab', 'lens-matrix')->assertStatus(422);

        Livewire::test(OpticalProductsComponent::class)
            ->assertSee('7 products found')->assertSee('Ray-Ban')->assertSee('RB3025')->assertSee('58-14-135')->assertSee('Photo AR')->assertSee('Flat top')
            ->set('categoryFilter', 'frame')->assertSee('Aviator')->assertDontSee('Cloth')->assertSee('1 product found')
            ->set('categoryFilter', 'lens')->assertSee('5 products found')
            ->set('categoryFilter', 'accessory')->assertSee('Cloth')->assertDontSee('Aviator');

        // Every kind opens in the form and saves again unchanged.
        $form = Livewire::test(OpticalProductsComponent::class);
        foreach (OpticalProduct::all() as $product) {
            $before = $product->only(['type', 'brand', 'details', 'lens_specs', 'name']);
            $form->call('edit', $product->id)->assertSet('type', $product->type)->call('save')->assertHasNoErrors();
            $this->assertEquals($before, $product->fresh()->only(['type', 'brand', 'details', 'lens_specs', 'name']), $product->sku.' changed on an untouched save.');
        }

        Livewire::test(\App\Livewire\Optical\OpticalPosComponent::class)->assertSee('Aviator')->assertSee('Cloth')->assertSee('Accessories');
        Livewire::test(\App\Livewire\Optical\OpticalOrderCreateComponent::class)
            ->call('selectFrameOpticalProduct', $frame->id)->assertSet('frame_price', $frame->selling_price)
            ->call('selectFrameOpticalProduct', OpticalProduct::where('sku', 'CL-1')->value('id'))->assertNotFound();
        $this->assertGreaterThanOrEqual(1, Livewire::test(\App\Livewire\Optical\OpticalDashboardComponent::class)->assertSee('Lenses')->viewData('lowStockCount'));
    }

    public function test_a_frame_saved_before_brands_were_a_list_and_a_renamed_brand(): void
    {
        // An old frame: a typed brand, no details. First use of the brand list starts with it.
        $old = OpticalProduct::create(['type' => 'frame', 'sku' => 'OLD-1', 'name' => 'Old Frame', 'brand' => 'Gucci', 'cost_price' => 10, 'selling_price' => 20, 'is_active' => true]);
        $form = Livewire::test(OpticalProductsComponent::class)->call('edit', $old->id)->assertSet('frameBrand', 'Gucci')->call('save')->assertHasNoErrors();
        $this->assertEquals(['brand' => 'Gucci'], $old->fresh()->details);

        // Renaming the brand in Lens options renames it on the frame; the code underneath stays.
        $option = OpticalLensOption::where('kind', 'frame_brand')->where('code', 'Gucci')->firstOrFail();
        Livewire::test(LensOptionsComponent::class)->call('edit', $option->id)->set('editingName', 'GUCCI Eyewear')->call('saveName')->assertHasNoErrors();
        $this->assertSame(['GUCCI Eyewear', 'Gucci'], [$old->fresh()->brand, $old->fresh()->details['brand']]);
        $form->call('edit', $old->id)->assertSee('GUCCI Eyewear')->call('save')->assertHasNoErrors();

        // A hidden brand stays on the frame that has it, but can't be picked for a new one.
        OpticalLensOption::create(['kind' => 'frame_brand', 'code' => 'Other', 'name' => 'Other', 'is_active' => true, 'sort_order' => 9]);
        \App\Support\Optical\LensOptions::forget();
        Livewire::test(LensOptionsComponent::class)->call('toggle', $option->id)->assertHasNoErrors();
        $form->call('edit', $old->id)->call('save')->assertHasNoErrors();
        $form->call('add')->set('name', 'New')->set('sku', 'NEW-1')->set('type', 'frame')->set('frameBrand', 'Gucci')->set('costPrice', '1')->set('sellingPrice', '2')
            ->call('save')->assertHasErrors(['frameBrand']);

        // Turning a frame into an accessory drops the frame details.
        $form->call('edit', $old->id)->set('type', 'accessory')->call('save')->assertHasNoErrors();
        $this->assertSame(['accessory', null], [$old->fresh()->type, $old->fresh()->details]);
    }

    public function test_the_grid_page_for_single_vision_bifocal_staff_and_no_stock(): void
    {
        // No stock yet: the page still opens and says so.
        Livewire::test(OpticalLensGridComponent::class)->assertSee('No lens stock has been received at this branch yet')->assertSee('Full screen');

        $this->stock();
        $grid = Livewire::test(OpticalLensGridComponent::class)->assertSee('Select powers')->assertSee('Reorder')->assertSeeHtml('data-grid-box');
        foreach ($grid->viewData('stockedRanges') as $range) {
            $grid->set('matrixRangeKey', $range['key'])->assertHasNoErrors()->assertSeeHtml('data-grid-box');
            foreach (['pairs', 'R', 'L'] as $view) $grid->set('matrixView', $view)->assertHasNoErrors();
        }
        $this->assertCount(2, $grid->viewData('stockedRanges'));

        // Opened from a link (or reloaded), the index in the address arrives as a number: the range must still be found.
        Livewire::withQueryParams(['matrixDesign' => 'Single Vision', 'matrixRange' => 'CANADA', 'matrixIndex' => '1.56', 'matrixCoating' => 'AR', 'matrixDiameter' => 65])
            ->test(OpticalLensGridComponent::class)->assertSet('matrixIndex', '1.56')->assertViewHas('matrixTotal', 8)->assertDontSee('No stock in this range');
        $grid->set('matrixStockedOnly', false)->set('matrixFullPowers', true)->assertHasNoErrors()
            ->call('editReorderLevels')->assertSet('showReorderSettings', true)->call('saveReorderLevels')->assertHasNoErrors();

        // Someone who isn't a manager sees the stock without the ordering and receiving controls.
        $staff = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Optician', 'guard_name' => 'web']);
        $staff->assignRole($role);
        $context = app(TenantContext::class);
        $context->clinic()?->users()->attach($staff->id, ['status' => 'active', 'is_default' => true]);
        $this->actingAs($staff);
        Livewire::test(OpticalLensGridComponent::class)->assertSeeHtml('data-grid-box')->assertDontSee('Done selecting')->assertDontSee('Receive ▾')->assertDontSee('Reorder ▾');
    }

    public function test_a_single_vision_sheet_in_pairs_with_halves_is_received_as_odd_lenses(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lens-xlsx-');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Count" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="2"><c r="A2" t="inlineStr"><is><t>SPH</t></is></c><c r="B2"><v>0</v></c><c r="C2"><v>-0.25</v></c></row><row r="3"><c r="A3"><v>-0.25</v></c><c r="B3"><v>3.5</v></c><c r="C3"><v>0.5</v></c></row></sheetData></worksheet>');
        $zip->close();
        try {
            $form = Livewire::test(OpticalStockManagementComponent::class)->call('openReceipt')
                ->set('stockType', 'lens')->set('entryMode', 'bulk')->set('newManufacturer', 'Factory')->call('addManufacturer')
                ->set('excelFile', UploadedFile::fake()->createWithContent('count.xlsx', file_get_contents($path)))
                ->assertSet('excelUnit', 'pairs')->call('previewExcel')->assertHasNoErrors()
                ->assertSet('excelPreview.total', 8)->assertSet('excelPreview.sourceTotal', 4)->assertSee('2 powers have a half pair')->assertDontSee('choose which eye');
            // The same sheet read as pieces refuses the halves.
            $form->set('excelUnit', 'pieces')->call('previewExcel')->assertHasErrors('excelFile');
            $form->set('excelUnit', 'pairs')->call('previewExcel')->assertHasNoErrors()->call('applyExcel')->assertHasNoErrors()
                ->assertSet('bulkQuantities.59.0', 7)->assertSet('bulkQuantities.59.1', 1)
                ->set('unitCost', '10')->set('unitPrice', '30')->set('supplier', 'Factory')->call('save')->assertHasNoErrors();
            $import = \App\Models\OpticalLensImport::sole();
            $this->assertEquals([4.0, 8], [(float) $import->source_quantity, $import->pieces]);
            $form->call('viewImport', $import->id)->assertSee('Worksheet: 4 pairs');
        } finally { if (file_exists($path)) unlink($path); }
        $this->assertEquals(8, \App\Models\OpticalProductStock::sum('quantity'));

        // The grid shows 3 pairs + 1 single lens, and the single lens alone.
        Livewire::test(OpticalLensGridComponent::class)->assertViewHas('matrixTotal', 8);
    }

    public function test_csv_round_trip_keeps_every_category_and_classification(): void
    {
        $this->stock();
        $service = app(\App\Services\OpticalProductCsvService::class);
        $stream = fopen('php://temp', 'w+');
        $service->writeCsv($stream);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $before = OpticalProduct::orderBy('sku')->get()->map->only(['sku', 'type', 'brand', 'details', 'lens_specs'])->all();

        Livewire::test(OpticalProductsComponent::class)->call('openImport')
            ->set('importFile', UploadedFile::fake()->createWithContent('products.csv', $csv))->call('importCsv')->assertHasNoErrors();
        $this->assertEquals($before, OpticalProduct::orderBy('sku')->get()->map->only(['sku', 'type', 'brand', 'details', 'lens_specs'])->all());
        $this->assertSame(7, OpticalProduct::count());

        // Plural and capitalised categories are understood; an unknown one is refused.
        $row = fn (string $sku, string $category) => "sku,name,category,brand,cost_price,selling_price,quantity,reorder_level,status,specifications\n{$sku},Case,{$category},,5,10,1,1,active,\n";
        $import = fn (string $text) => Livewire::test(OpticalProductsComponent::class)->call('openImport')->set('importFile', UploadedFile::fake()->createWithContent('p.csv', $text))->call('importCsv');
        $import($row('CASE-1', 'Accessories'))->assertHasNoErrors();
        $import($row('FRM-9', 'FRAMES'))->assertHasNoErrors();
        $import($row('SUN-1', 'Sunglasses'))->assertHasErrors(['importFile']);
        $this->assertSame(['accessory', 'frame'], [OpticalProduct::where('sku', 'CASE-1')->value('type'), OpticalProduct::where('sku', 'FRM-9')->value('type')]);
    }

    public function test_stock_count_splits_frames_lenses_and_everything_else(): void
    {
        $this->stock();
        $counts = app(\App\Services\OpticalStockCountService::class);
        $skus = fn ($count) => OpticalProduct::whereIn('id', $count->lines()->pluck('optical_product_id'))->orderBy('sku')->pluck('sku')->all();
        $this->assertSame(['FR-1'], $skus($counts->start('frames')));
        // A catalogue lens (no powers) is counted with the accessories, as before.
        $this->assertSame(['CL-1', 'SV-PAR'], $skus($counts->start('other')));
        // Lens ranges are counted one at a time; a bifocal range is one count per eye.
        $this->assertCount(3, $counts->lensRanges());
        Livewire::test(\App\Livewire\Optical\OpticalStockCountsComponent::class)->assertHasNoErrors();
    }
}
