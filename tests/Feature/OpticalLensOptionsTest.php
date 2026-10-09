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
        $page = Livewire::test(LensOptionsComponent::class)->assertSee('Clear AR')->assertSee('Gold Tint')->assertSee('Lens designs');
        $this->assertSame(['AR', 'Photo AR', 'Photo Gray', 'Photochromic', 'Blue AR', 'BlueCut', 'Transitions', 'HC', 'Gold Tint'],
            OpticalLensOption::where('kind', 'treatment')->orderBy('sort_order')->pluck('code')->all());

        // Add, then use it when receiving.
        $page->set('newTreatment', 'Blue cut Photo AR')->call('addTreatment')->assertHasNoErrors();
        $page->set('newTreatment', 'blue CUT photo ar')->call('addTreatment')->assertHasErrors(['newTreatment']);
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

    public function test_a_range_typed_in_other_capitals_is_the_same_stock_and_new_ranges_are_flagged(): void
    {
        $this->receive('AR');
        $form = Livewire::test(OpticalLensReceivingComponent::class);

        // Capitals and spaces don't make a new range: the existing spelling is used.
        $form->set('lensRange', '  canada ')->assertSet('lensRange', 'CANADA')->assertDontSee('New range:');

        // A near miss is flagged with the likely range, which one tap puts back.
        $form->set('lensRange', 'CANDA')->assertSee('New range:')->assertSee('will be kept as separate stock')->assertSee('Did you mean')
            ->call('useRange', 'CANADA')->assertSet('lensRange', 'CANADA')->assertDontSee('New range:');

        // A genuinely new manufacturer is flagged, with nothing to suggest.
        $form->set('lensRange', 'Essilor')->assertSee('New range:')->assertDontSee('Did you mean');
        $form->call('useRange', 'Not a range')->assertStatus(422);
    }

    public function test_only_managers_change_lens_options(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff);
        Livewire::test(LensOptionsComponent::class)->set('newTreatment', 'Mirror')->call('addTreatment')->assertForbidden();
    }
}
