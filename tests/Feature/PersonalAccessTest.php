<?php

namespace Tests\Feature;

use App\Livewire\Admin\PersonAccessComponent;
use App\Models\User;
use App\Services\OwnerAlerts;
use App\Support\Access\Access;
use App\Support\Access\PersonalAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Roles phase 4: single permissions switched on or off for one person, per clinic. */
class PersonalAccessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Super Admin', 'Manager', 'Secretary', 'Cashier', 'Doctor'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function person(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_a_switch_adds_or_removes_one_permission_for_one_person(): void
    {
        $cashier = $this->person('Cashier');
        $other = $this->person('Cashier');
        $this->assertFalse(Access::allows($cashier, 'expenses.manage'));
        $this->assertTrue(Access::allows($cashier, 'pos.sell'));

        PersonalAccess::set($cashier, 'expenses.manage', true, null);
        PersonalAccess::set($cashier, 'pos.sell', false, null);

        $this->assertTrue(Access::allows($cashier, 'expenses.manage'));
        $this->assertFalse(Access::allows($cashier, 'pos.sell'));
        $this->assertTrue(Access::allows($cashier, 'sales.view'), 'the rest still follows the role');
        $this->assertFalse(Access::allows($other, 'expenses.manage'), 'only that person');

        PersonalAccess::set($cashier, 'pos.sell', null, null);
        $this->assertTrue(Access::allows($cashier, 'pos.sell'), 'back to the role');
    }

    public function test_switches_open_the_screens_and_menu_too(): void
    {
        $cashier = $this->person('Cashier');
        $this->assertFalse(Access::allowsRoute($cashier, 'admin.expenses'));
        PersonalAccess::set($cashier, 'expenses.manage', true, null);
        $this->assertTrue(Access::allowsRoute($cashier, 'admin.expenses'));
    }

    public function test_switches_belong_to_one_clinic(): void
    {
        config(['tenancy.enabled' => true]); // hosted: each clinic keeps its own switches
        $cashier = $this->person('Cashier');
        DB::table('clinic_user_permissions')->insert(['clinic_id' => 999999, 'user_id' => $cashier->id, 'permission' => 'expenses.manage',
            'granted' => true, 'created_at' => now(), 'updated_at' => now()]);
        PersonalAccess::forget($cashier);

        $this->assertFalse(Access::allows($cashier, 'expenses.manage'));
    }

    public function test_the_owner_cannot_be_switched_off(): void
    {
        $owner = $this->person('Super Admin');
        PersonalAccess::set($owner, 'pos.sell', false, null);

        $this->assertTrue(Access::allows($owner, 'pos.sell'));
    }

    public function test_the_owner_switches_access_and_hears_when_it_goes_up(): void
    {
        $cashier = $this->person('Cashier');
        $this->actingAs($this->person('Super Admin'));
        $this->mock(OwnerAlerts::class)->shouldReceive('accessIncreased')->once()
            ->withArgs(fn ($staff, $added) => $staff->is($cashier) && $added === ['Expenses']);

        Livewire::test(PersonAccessComponent::class, ['user' => $cashier])
            ->assertSee('What they can see')
            ->set('choices.expenses__manage', 'on')->set('choices.pos__sell', 'off')
            ->call('save')->assertHasNoErrors();

        $this->assertEqualsCanonicalizing(['expenses.manage' => true, 'pos.sell' => false], PersonalAccess::overrides($cashier));
        $this->assertDatabaseHas('audit_trails', ['event' => 'staff.access_changed', 'auditable_id' => $cashier->id]);
    }

    public function test_a_manager_switches_only_what_they_hold_and_never_approvals(): void
    {
        $cashier = $this->person('Cashier');
        $this->actingAs($this->person('Manager'));
        $this->mock(OwnerAlerts::class)->shouldReceive('accessIncreased');

        Livewire::test(PersonAccessComponent::class, ['user' => $cashier])
            ->set('choices.expenses__manage', 'on')->call('save')->assertHasNoErrors();
        $this->assertTrue(PersonalAccess::override($cashier, 'expenses.manage'));

        Livewire::test(PersonAccessComponent::class, ['user' => $cashier])
            ->set('choices.approve__refunds', 'on')->call('save')->assertForbidden();
        Livewire::test(PersonAccessComponent::class, ['user' => $cashier])
            ->set('choices.settings__manage', 'on')->call('save')->assertForbidden();
        $this->assertNull(PersonalAccess::override($cashier, 'approve.refunds'));
        $this->assertNull(PersonalAccess::override($cashier, 'settings.manage'));
    }

    public function test_nobody_changes_their_own_access_or_the_owners(): void
    {
        $manager = $this->person('Manager');
        $this->actingAs($manager);
        Livewire::test(PersonAccessComponent::class, ['user' => $manager])
            ->set('choices.settings__manage', 'on')->call('save')->assertForbidden();
        Livewire::test(PersonAccessComponent::class, ['user' => $this->person('Super Admin')])
            ->set('choices.pos__sell', 'off')->call('save')->assertForbidden();
    }

    public function test_desk_staff_cannot_open_the_page(): void
    {
        $this->actingAs($this->person('Cashier'));
        Livewire::test(PersonAccessComponent::class, ['user' => $this->person('Doctor')])->assertForbidden();
    }
}
