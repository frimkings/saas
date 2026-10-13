<?php

namespace Tests\Feature;

use App\Livewire\Optical\LensOptionsComponent;
use App\Livewire\Optical\OpticalLensReceivingComponent;
use App\Models\{Clinic, ClinicSubscription, OpticalLensOption, OpticalProduct, SubscriptionPlan, User};
use App\Support\Optical\LensOptions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Managers keep their own lens designs and treatments; stock keeps codes, so renaming never splits it. */
class OpticalLensOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $clinic = Clinic::create(['name' => 'Lens Options', 'slug' => 'lens-options', 'status' => 'active']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Optical', 'code' => 'optical-lens-options', 'features' => ['optical', '*'], 'base_price' => 0, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth()]);
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);
        $role = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $user->assignRole($role);
        \Illuminate\Support\Facades\DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $role->id]);
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($user, $branch);
        $this->actingAs($user);
    }

    private function receive(string $coating): void
    {
        app(\App\Services\OpticalLensReceivingService::class)->receiveMany([[
            'specs' => ['range' => 'CANADA', 'design' => 'Single Vision', 'index' => '1.56', 'coating' => $coating, 'diameter' => 65, 'sphere' => '-1.00', 'power' => '0.00'],
            'quantity' => 2, 'details' => ['unit_cost' => 10, 'unit_price' => 25, 'supplier' => 'Lab'],
        ]]);
    }

    public function test_treatments_can_be_added_renamed_hidden_reordered_and_deleted_when_unused(): void
    {
        $this->receive('Gold Tint'); // a treatment already on stock joins the defaults
        $page = Livewire::test(LensOptionsComponent::class)->assertSee('Clear AR')->assertSee('Gold Tint')->assertSee('Lens types')->assertSee('Forms')->assertSee('Manufacturers');
        $this->assertSame(['AR', 'Photo AR', 'Photo Gray', 'Photochromic', 'Blue AR', 'BlueCut', 'Transitions', 'HC', 'Gold Tint'],
            OpticalLensOption::where('kind', 'treatment')->orderBy('sort_order')->pluck('code')->all());

        // Add, then use it when receiving.
        $page->set('newName.treatment', 'Blue cut Photo AR')->call('add', 'treatment')->assertHasNoErrors();
        $page->set('newName.treatment', 'blue CUT photo ar')->call('add', 'treatment')->assertHasErrors(['newName.treatment']);
        LensOptions::forget();
        $this->assertArrayHasKey('Blue cut Photo AR', LensOptions::choices('treatment'));
        Livewire::test(OpticalLensReceivingComponent::class)->assertSee('Blue cut Photo AR');

        // Rename: the name changes, the code on stock stays, so the stock is not split.
        $gold = OpticalLensOption::where('code', 'Gold Tint')->firstOrFail();
        $page->call('edit', $gold->id)->set('editingName', 'Gold mirror')->call('saveName')->assertHasNoErrors();
        LensOptions::forget();
        $this->assertSame('Gold mirror', LensOptions::label('treatment', 'Gold Tint'));
        $this->assertSame('Gold Tint', OpticalProduct::whereNotNull('lens_specs')->firstOrFail()->lens_specs['coating']);

        // In use: can be hidden, not deleted. Hidden treatments can't be received.
        $page->call('delete', $gold->id)->assertHasErrors(['options']);
        $page->call('toggle', $gold->id);
        LensOptions::forget();
        $this->assertArrayNotHasKey('Gold Tint', LensOptions::choices('treatment'));
        $this->assertArrayHasKey('Gold Tint', LensOptions::choices('treatment', 'Gold Tint')); // still shown where already chosen

        // Unused: deleted. Reorder moves it.
        $new = OpticalLensOption::where('code', 'Blue cut Photo AR')->firstOrFail();
        $page->call('move', $new->id, -1);
        $this->assertSame('Blue cut Photo AR', OpticalLensOption::where('kind', 'treatment')->orderBy('sort_order')->get()->reverse()->values()[1]->code);
        $page->call('delete', $new->id)->assertHasNoErrors();
        $this->assertDatabaseMissing('optical_lens_options', ['code' => 'Blue cut Photo AR']);
    }

    public function test_designs_are_renamed_and_hidden_but_not_deleted_and_names_show_in_ordering(): void
    {
        $this->receive('BlueCut');
        $page = Livewire::test(LensOptionsComponent::class);
        $sv = OpticalLensOption::where('kind', 'design')->where('code', 'Single Vision')->firstOrFail();
        $page->call('edit', $sv->id)->set('editingName', 'SV')->call('saveName')->assertHasNoErrors()
            ->assertDontSee('wire:click="delete('.$sv->id.')"', false);
        LensOptions::forget();
        $this->assertSame('SV – Blue cut', (new \App\Livewire\Optical\OpticalOrderCreateComponent())->wantedLensChoices()['Single Vision|BlueCut']);

        // At least one design stays available.
        foreach (OpticalLensOption::where('kind', 'design')->where('code', '!=', 'Progressive')->get() as $design) $page->call('toggle', $design->id);
        $page->call('toggle', OpticalLensOption::where('code', 'Progressive')->value('id'))->assertHasErrors(['options']);
    }

    public function test_manufacturers_come_from_the_list_and_new_ones_are_added_once(): void
    {
        $this->receive('AR'); // CANADA is on stock, so it starts on the list
        $form = Livewire::test(OpticalLensReceivingComponent::class)->assertSee('CANADA');

        // Adding "canada" picks the existing CANADA instead of making a second one.
        $form->set('newManufacturer', '  canada ')->call('addManufacturer')->assertSet('lensRange', 'CANADA');
        $this->assertSame(1, OpticalLensOption::where('kind', 'manufacturer')->count());
        $form->set('newManufacturer', 'Essilor')->call('addManufacturer')->assertSet('lensRange', 'Essilor');
        $this->assertSame(['CANADA', 'Essilor'], OpticalLensOption::where('kind', 'manufacturer')->orderBy('sort_order')->pluck('code')->all());

        // A name from a workbook that isn't on the list is flagged and can't be received until chosen or added.
        $form->set('lensRange', 'CANDA')->assertSee('is not in your manufacturers')->assertSee('Did you mean')
            ->set('unitCost', '10')->set('unitPrice', '20')->set('supplier', 'Lab')->set('lensSphere', '-1.00')->set('quantity', '1')
            ->call('save')->assertHasErrors(['lensRange'])
            ->call('useRange', 'CANADA')->assertSet('lensRange', 'CANADA')->assertDontSee('is not in your manufacturers');
        $form->call('useRange', 'Not a range')->assertStatus(422);
    }

    public function test_forms_belong_to_a_lens_type_and_each_form_is_its_own_stock(): void
    {
        LensOptions::forget();
        $this->assertSame(['Standard', 'Flat top', 'Invisible'], array_keys(LensOptions::choices('form', null, 'Bifocal')));
        $this->assertSame(['Standard', 'Wider corridor'], array_keys(LensOptions::choices('form', null, 'Progressive')));
        $this->assertSame(['Standard'], array_keys(LensOptions::choices('form', null, 'Single Vision')));

        // Receive the same bifocal power as Standard and as Invisible: two stock items.
        $receive = fn (string $form) => Livewire::test(OpticalLensReceivingComponent::class)
            ->set('entryMode', 'single')->set('lensDesign', 'Bifocal')->set('lensForm', $form)->set('lensCoating', 'AR')->set('newManufacturer', 'CANADA')->call('addManufacturer')
            ->set('lensEye', 'R')->set('lensSphere', '-1.00')->set('lensPower', '2.00')->set('quantity', '2')
            ->set('unitCost', '20')->set('unitPrice', '60')->set('supplier', 'Lab')->call('save')->assertHasNoErrors();
        $receive('Standard');
        $receive('Invisible');
        $items = OpticalProduct::whereNotNull('lens_specs')->get();
        $this->assertCount(2, $items);
        $this->assertSame([null, 'Invisible'], $items->map(fn ($item) => $item->lens_specs['form'] ?? null)->sort()->values()->all());
        $this->assertStringContainsString('Invisible', $items->first(fn ($item) => isset($item->lens_specs['form']))->name);

        // A form that doesn't fit the lens type goes back to Standard.
        Livewire::test(OpticalLensReceivingComponent::class)->set('lensDesign', 'Bifocal')->set('lensForm', 'Invisible')
            ->set('lensDesign', 'Progressive')->assertSet('lensForm', 'Standard');

        // Forms are managed in Lens options: Standard can't be deleted; an unused form can.
        $page = Livewire::test(LensOptionsComponent::class)->set('newName.form', 'Executive')->set('newFormDesign', 'Bifocal')->call('add', 'form')->assertHasNoErrors();
        $this->assertSame('Bifocal', OpticalLensOption::where('code', 'Executive')->value('design'));
        $page->assertDontSee('wire:click="delete('.OpticalLensOption::where('code', 'Standard')->value('id').')"', false)
            ->call('delete', OpticalLensOption::where('code', 'Invisible')->value('id'))->assertHasErrors(['options'])
            ->call('delete', OpticalLensOption::where('code', 'Executive')->value('id'))->assertHasNoErrors();
    }

    public function test_every_product_is_a_frame_lens_or_accessory_with_its_own_details(): void
    {
        // Every received stock lens is in Lenses, whatever its lens type; no category is made for it.
        $this->receive('AR');
        app(\App\Services\OpticalLensReceivingService::class)->receiveMany([[
            'specs' => ['range' => 'CANADA', 'design' => 'Progressive', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65, 'sphere' => '-1.00', 'power' => '1.50', 'eye' => 'R'],
            'quantity' => 1, 'details' => ['unit_cost' => 10, 'unit_price' => 25, 'supplier' => 'Lab'],
        ]]);
        $first = OpticalProduct::where('lens_specs->design', 'Single Vision')->firstOrFail();
        $this->assertSame(['lens', 'Lenses'], [$first->type, $first->categoryLabel()]);
        $this->assertSame(2, OpticalProduct::whereNotNull('lens_specs')->where('type', 'lens')->count());
        $this->assertSame(0, \App\Models\OpticalCategory::count());

        // A stock lens stays in Lenses and keeps the classification it was received with.
        $page = Livewire::test(\App\Livewire\Optical\OpticalProductsComponent::class);
        $page->call('edit', $first->id)->assertSet('stockLens', true)->assertSet('lensDesign', 'Single Vision')
            ->set('type', 'accessory')->call('save')->assertHasErrors(['type']);

        // The category is required, and is one of the three.
        $new = fn (string $name, string $sku) => $page->call('add')->set('name', $name)->set('sku', $sku)->set('costPrice', '10')->set('sellingPrice', '20');
        $new('No category', 'NC-1')->call('save')->assertHasErrors(['type']);
        $new('Sunglasses', 'SG-1')->set('type', 'sunglasses')->call('save')->assertHasErrors(['type']);

        // An accessory is just the product: Accessories → Cloth.
        $new('Cloth', 'CL-1')->set('type', 'accessory')->assertDontSee('Frame details')->assertDontSee('Lens classification')->call('save')->assertHasNoErrors();
        $this->assertSame(['accessory', null], [OpticalProduct::where('sku', 'CL-1')->value('type'), OpticalProduct::where('sku', 'CL-1')->firstOrFail()->details]);

        // A frame has a brand from the list (added on the spot here), a model, a colour and a size.
        $new('Aviator', 'FR-1')->set('type', 'frame')->assertSee('Frame details')->call('save')->assertHasErrors(['frameBrand']);
        $new('Aviator', 'FR-1')->set('type', 'frame')->set('newFrameBrand', 'Ray-Ban')->call('addFrameBrand')->assertSet('frameBrand', 'Ray-Ban')
            ->set('frameModel', 'RB3025')->set('frameColour', 'Gold')->set('frameSize', '58-14-135')->call('save')->assertHasNoErrors();
        $frame = OpticalProduct::where('sku', 'FR-1')->firstOrFail();
        $this->assertEquals(['brand' => 'Ray-Ban', 'model' => 'RB3025', 'colour' => 'Gold', 'size' => '58-14-135'], $frame->details);
        $this->assertSame(['frame', 'Ray-Ban'], [$frame->type, $frame->brand]);
        $this->assertSame(1, OpticalLensOption::where('kind', 'frame_brand')->where('name', 'Ray-Ban')->count());
        $page->call('edit', $frame->id)->assertSet('frameBrand', 'Ray-Ban')->assertSet('frameSize', '58-14-135')
            ->set('newFrameBrand', 'ray-ban')->call('addFrameBrand')->assertSet('frameBrand', 'Ray-Ban');
        $this->assertSame(1, OpticalLensOption::where('kind', 'frame_brand')->count());
        Livewire::test(\App\Livewire\Optical\OpticalOrderCreateComponent::class)
            ->call('selectFrameOpticalProduct', $frame->id)->assertSet('frame_model_number', 'Aviator');
        // The brand list is kept with the lens options, and a brand on a frame can't be deleted.
        Livewire::test(LensOptionsComponent::class)->assertSee('Frame brands')->assertSee('Ray-Ban')
            ->call('delete', OpticalLensOption::where('kind', 'frame_brand')->value('id'))->assertHasErrors(['options']);

        // A lens added as a product is classified: lens type, form, treatment, manufacturer.
        OpticalLensOption::create(['kind' => 'manufacturer', 'code' => 'Canada Opticals', 'name' => 'Canada Opticals', 'is_active' => true, 'sort_order' => 99]);
        LensOptions::forget();
        $lens = fn () => $new('SV Photo AR', 'SV-PAR')->set('type', 'lens')->assertSee('Lens classification');
        $lens()->call('save')->assertHasErrors(['lensDesign', 'lensTreatment']);
        $lens()->set('lensDesign', 'Single Vision')->set('lensForm', 'Flat top')->set('lensTreatment', 'Photo AR')->call('save')->assertHasErrors(['lensForm']);
        $lens()->set('lensDesign', 'Single Vision')->set('lensTreatment', 'Photo AR')->set('lensManufacturer', 'Canada Opticals')->call('save')->assertHasNoErrors();
        $saved = OpticalProduct::where('sku', 'SV-PAR')->firstOrFail();
        $this->assertEquals(['design' => 'Single Vision', 'coating' => 'Photo AR', 'range' => 'Canada Opticals'], $saved->details);
        $this->assertSame(['lens', 'Canada Opticals', null], [$saved->type, $saved->brand, $saved->lens_specs]);
        $page->call('edit', $saved->id)->assertSet('lensTreatment', 'Photo AR')->assertSet('lensForm', 'Standard')->assertSet('stockLens', false);

        // The list filters by category.
        $page->set('categoryFilter', 'accessory')->assertSee('Cloth')->assertDontSee('Aviator')->assertDontSee('SV Photo AR');
    }

    public function test_an_invisible_bifocal_is_matched_and_shown_apart_from_a_flat_top(): void
    {
        $stock = fn (?string $form, int $quantity) => app(\App\Services\OpticalLensReceivingService::class)->receiveMany(array_map(fn ($eye) => [
            'specs' => array_filter(['range' => 'CANADA', 'design' => 'Bifocal', 'form' => $form, 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65,
                'sphere' => '-1.00', 'power' => '2.00', 'eye' => $eye], fn ($v) => $v !== null),
            'quantity' => $quantity, 'details' => ['unit_cost' => 20, 'unit_price' => 60, 'supplier' => 'Lab'],
        ], ['R', 'L']));
        $stock(null, 3);          // Standard (flat-top) bifocals
        $stock('Invisible', 2);

        $patient = \App\Models\Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Abena', 'contact' => '0240000111', 'gender' => 'Other']);
        $order = Livewire::test(\App\Livewire\Optical\OpticalOrderCreateComponent::class)->call('choosePatient', $patient->id)->set('currentStep', 2);
        $choices = $order->instance()->wantedLensChoices();
        $this->assertSame('Bifocal – Clear AR', $choices['Bifocal|AR']);
        $this->assertSame('Bifocal Invisible – Clear AR', $choices['Bifocal|AR|Invisible']);

        // Invisible wanted: only the invisible stock is chosen; the flat-top one is an alternative.
        $rx = ['od' => ['sph' => '-1.00', 'add' => '2.00'], 'os' => ['sph' => '-1.00', 'add' => '2.00']];
        $order->call('checkLensAvailabilityFromClient', $rx + ['wanted' => 'Bifocal|AR|Invisible'])
            ->assertSet('stock_form', 'Invisible')->assertSet('lensAvailability.status', 'available')
            ->assertSeeInOrder(['CANADA Bifocal Invisible', 'Alternatives in stock', 'CANADA Bifocal']);
        $this->assertSame([true, false], array_column($order->get('stockLensOptions'), 'matches'));
        // Flat-top wanted: the invisible stock is never picked for it.
        $order->call('checkLensAvailabilityFromClient', $rx + ['wanted' => 'Bifocal|AR'])->assertSet('stock_form', '');

        // The lens matrix keeps them apart, labelled by form.
        $matrix = Livewire::test(\App\Livewire\Optical\OpticalLensGridComponent::class)
            ->set('matrixRange', 'CANADA')->set('matrixDesign', 'Bifocal')->set('matrixForm', 'Invisible')->set('matrixDiameter', 65)
            ->assertSee('Invisible · Clear AR · CANADA');
        $matrix->assertViewHas('lensBlankStock', fn ($stock) => $stock['-1.00|2.00'] === 2);
        $matrix->set('matrixForm', '')->assertViewHas('lensBlankStock', fn ($stock) => $stock['-1.00|2.00'] === 3);
    }

    public function test_only_managers_change_lens_options(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff);
        Livewire::test(LensOptionsComponent::class)->set('newName.treatment', 'Mirror')->call('add', 'treatment')->assertForbidden();
    }

    public function test_the_odd_lens_of_a_bifocal_half_pair_is_received_for_the_chosen_eye(): void
    {
        // A sheet in pairs: 2.5 pairs at +0.00 / +1.00 and half a pair at +0.00 / +1.25.
        $path = tempnam(sys_get_temp_dir(), 'lens-xlsx-');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Count" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="2"><c r="A2" t="inlineStr"><is><t>SPH</t></is></c><c r="B2"><v>1</v></c><c r="C2"><v>1.25</v></c></row><row r="3"><c r="A3"><v>0</v></c><c r="B3"><v>2.5</v></c><c r="C3"><v>0.5</v></c></row></sheetData></worksheet>');
        $zip->close();
        try {
            $form = Livewire::test(\App\Livewire\Optical\OpticalStockManagementComponent::class)->call('openReceipt')
                ->set('stockType', 'lens')->set('entryMode', 'bulk')->set('newManufacturer', 'Factory')->call('addManufacturer')
                ->set('lensDesign', 'Bifocal')->set('lensForm', 'Flat top')
                ->set('excelFile', \Illuminate\Http\UploadedFile::fake()->createWithContent('count.xlsx', file_get_contents($path)))
                ->assertSet('excelUnit', 'pairs')->assertSet('lensEye', 'B')->call('previewExcel')->assertHasNoErrors()
                ->assertSet('excelPreview.total', 6)->assertSet('excelPreview.sourceTotal', 3)
                ->assertSee('2 powers have a half pair')->assertSee('+ 1 lens');
            // The grid keeps whole pairs; each loose lens needs an eye before the sheet is applied.
            $form->call('applyExcel')->assertHasErrors(['oddEyes']);
            $form->call('setOddEyes', 'R')->set('oddEyes.60:4', 'L')->call('applyExcel')->assertHasNoErrors()
                ->assertSet('bulkQuantities.60.3', 2)->assertSet('bulkQuantities.60.4', 0)
                ->assertSee('Plus 2 loose lenses from half pairs');
            $form->set('unitCost', '10')->set('unitPrice', '30')->set('supplier', 'Factory');
            $this->assertEquals(['pieces' => 6, 'cost' => 60], $form->instance()->receiptTotals());
            $form->call('save')->assertHasNoErrors();
        } finally { if (file_exists($path)) unlink($path); }

        $held = fn (string $power, string $eye) => (int) OpticalProduct::where('lens_specs->power', $power)->where('lens_specs->eye', $eye)->first()?->stocks()->sum('quantity');
        $this->assertSame([3, 2], [$held('1.00', 'R'), $held('1.00', 'L')]);
        $this->assertSame([0, 1], [$held('1.25', 'R'), $held('1.25', 'L')]);
        $import = \App\Models\OpticalLensImport::sole();
        $this->assertEquals([3.0, 6], [(float) $import->source_quantity, $import->pieces]);
    }
}
