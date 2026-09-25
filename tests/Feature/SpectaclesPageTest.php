<?php

namespace Tests\Feature;

use App\Livewire\Secretary\SpectaclesComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SpectaclesPageTest extends TestCase
{
    use DatabaseTransactions;

    private User $secretary;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Secretary', 'guard_name' => 'web']);
        $this->secretary = User::factory()->create();
        $this->secretary->assignRole('Secretary');
        $this->actingAs($this->secretary);
    }

    public function test_secretary_can_render_spectacle_orders_page(): void
    {
        $this->get(route('secretary.spectacles'))
            ->assertOk()
            ->assertSee('Spectacle Orders')
            ->assertSee('Refractions Needing Order');
    }

    public function test_record_type_controls_switch_without_livewire_errors(): void
    {
        Livewire::test(SpectaclesComponent::class)
            ->assertSet('recordType', 'orders')
            ->call('setRecordType', 'refractions')
            ->assertSet('recordType', 'refractions')
            ->assertSet('statusFilter', '')
            ->call('setStatusFilter', 'In Lab')
            ->assertSet('recordType', 'orders')
            ->assertSet('statusFilter', 'In Lab')
            ->set('searchTerm', 'PX-TEST')
            ->assertSet('searchTerm', 'PX-TEST')
            ->assertStatus(200);
    }

    public function test_client_cannot_inline_edit_protected_order_fields(): void
    {
        Livewire::test(SpectaclesComponent::class)
            ->set('editField.status', 'Collected')
            ->call('saveEdit', 999999, 'status')
            ->assertDispatched('notify');
    }
}
