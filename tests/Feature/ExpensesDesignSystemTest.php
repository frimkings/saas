<?php

namespace Tests\Feature;

use App\Livewire\Admin\ExpensesComponent;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExpensesDesignSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_and_category_forms_render_validate_and_save(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $this->actingAs($user);

        $page = Livewire::test(ExpensesComponent::class)
            ->assertSee('Expenses')
            ->call('openCreate')
            ->assertSee('Description (required)')
            ->call('save')
            ->assertHasErrors(['state.description', 'state.amount'])
            ->set('state.description', 'Design system workflow test')
            ->set('state.amount', '123.45')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false)
            ->assertSee('Design system workflow test');

        $expense = Expense::where('description', 'Design system workflow test')->firstOrFail();
        $page->call('openEdit', $expense->id)
            ->assertSet('state.description', 'Design system workflow test')
            ->set('state.amount', '130.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('130.00')
            ->call('openCreateCategory')
            ->assertSee('Category name (required)')
            ->call('saveCategory')
            ->assertHasErrors(['categoryState.name'])
            ->set('categoryState.name', 'Design system category')
            ->call('saveCategory')
            ->assertHasNoErrors()
            ->assertSet('showCategoryModal', false)
            ->set('showCategoryPanel', true)
            ->assertSee('Design system category');

        $page->call('setPage', 2)->set('perPage', 30)->assertSet('paginators.page', 1);
    }
}
