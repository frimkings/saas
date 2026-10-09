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

    public function test_categories_have_a_type_and_stock_lenses_file_into_the_clinics_lens_category(): void
    {
        // A lens category for single vision, with a 40% markup.
        Livewire::test(\App\Livewire\Optical\OpticalCategoriesComponent::class)->call('add')
            ->set('name', 'SV LENSES')->set('code', 'SV')->set('type', 'lens')->set('lensType', 'Single Vision')->set('markup', '40')
            ->call('save')->assertHasNoErrors();
        $sv = \App\Models\OpticalCategory::where('code', 'SV')->firstOrFail();
        $this->assertSame(['lens', 'Single Vision'], [$sv->type, $sv->lens_type]);

        // Received single vision stock goes into it, not an automatic "Stock Lenses" category.
        $this->receive('AR');
        $this->assertSame($sv->id, OpticalProduct::whereNotNull('lens_specs')->firstOrFail()->optical_category_id);
        $this->assertSame(0, \App\Models\OpticalCategory::where('code', 'like', 'stock-%')->count());

        // A category holding stock lenses can't stop being a lens category.
        Livewire::test(\App\Livewire\Optical\OpticalCategoriesComponent::class)->call('edit', $sv->id)->set('type', 'accessory')->call('save')
            ->assertHasErrors(['type']);

        // Progressive stock with no progressive category gets one made for it.
        app(\App\Services\OpticalLensReceivingService::class)->receiveMany([[
            'specs' => ['range' => 'CANADA', 'design' => 'Progressive', 'index' => '1.56', 'coating' => 'AR', 'diameter' => 65, 'sphere' => '-1.00', 'power' => '1.50', 'eye' => 'R'],
            'quantity' => 1, 'details' => ['unit_cost' => 10, 'unit_price' => 25, 'supplier' => 'Lab'],
        ]]);
        $made = \App\Models\OpticalCategory::where('lens_type', 'Progressive')->firstOrFail();
        $this->assertSame(['lens', 'Progressive Lenses'], [$made->type, $made->name]);

        // Frames and accessories: a name no longer decides; old-style names still get a sensible type.
        $this->assertSame('frame', \App\Models\OpticalCategory::create(['code' => 'FR', 'name' => 'Designer Frames', 'is_active' => true])->type);
        $this->assertSame('accessory', \App\Models\OpticalCategory::create(['code' => 'CS', 'name' => 'Cases', 'is_active' => true])->type);
    }

    public function test_markup_suggests_a_selling_price_from_cost_and_a_typed_price_is_kept(): void
    {
        \App\Models\OpticalCategory::create(['code' => 'SV', 'name' => 'SV LENSES', 'type' => 'lens', 'lens_type' => 'Single Vision', 'default_markup' => 40, 'is_active' => true]);
        $receive = Livewire::test(OpticalLensReceivingComponent::class)->set('lensDesign', 'Single Vision')
            ->set('unitCost', '25')->assertSet('unitPrice', '35.00')->assertSee('Suggested from cost +40% (SV LENSES)')
            ->set('unitCost', '50')->assertSet('unitPrice', '70.00');
        $receive->set('unitPrice', '80')->set('unitCost', '60')->assertSet('unitPrice', '80')->assertDontSee('Suggested from cost');

        $frames = \App\Models\OpticalCategory::create(['code' => 'FR', 'name' => 'Frames', 'type' => 'frame', 'default_markup' => 100, 'is_active' => true]);
        Livewire::test(\App\Livewire\Optical\OpticalProductsComponent::class)->call('add')
            ->set('categoryId', (string) $frames->id)->set('costPrice', '120')->assertSet('sellingPrice', '240.00')
            ->assertSee('Suggested from cost +100% (Frames)');
    }

    public function test_only_managers_change_lens_options(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff);
        Livewire::test(LensOptionsComponent::class)->set('newName.treatment', 'Mirror')->call('add', 'treatment')->assertForbidden();
    }
}
