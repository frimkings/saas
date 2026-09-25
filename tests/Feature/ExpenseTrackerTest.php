<?php

namespace Tests\Feature;

use App\Livewire\Admin\ExpensesComponent;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExpenseTrackerTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private ExpenseCategory $cat;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->actingAs($this->admin);

        $this->cat = ExpenseCategory::create([
            'name'    => 'Test Rent',
            'section' => ExpenseCategory::OPERATING,
            'color'   => '#ff0000',
        ]);
    }

    // ── Model constants ──────────────────────────────────────────────────

    public function test_expense_category_has_correct_section_constants(): void
    {
        $this->assertSame('operating_expense',     ExpenseCategory::OPERATING);
        $this->assertSame('non_operating_expense', ExpenseCategory::NON_OPERATING);
    }

    public function test_expense_category_section_labels_returns_both_sections(): void
    {
        $labels = ExpenseCategory::sectionLabels();

        $this->assertArrayHasKey('operating_expense',     $labels);
        $this->assertArrayHasKey('non_operating_expense', $labels);
    }

    // ── Expense model ────────────────────────────────────────────────────

    public function test_expense_belongs_to_category(): void
    {
        $expense = Expense::create([
            'expense_category_id' => $this->cat->id,
            'expense_date'        => today()->toDateString(),
            'description'         => 'Monthly rent',
            'amount'              => 1200.00,
            'recorded_by'         => $this->admin->id,
        ]);

        $this->assertInstanceOf(ExpenseCategory::class, $expense->category);
        $this->assertSame($this->cat->id, $expense->category->id);
    }

    public function test_expense_belongs_to_recorder(): void
    {
        $expense = Expense::create([
            'expense_date' => today()->toDateString(),
            'description'  => 'Test',
            'amount'       => 50.00,
            'recorded_by'  => $this->admin->id,
        ]);

        $this->assertInstanceOf(User::class, $expense->recorder);
        $this->assertSame($this->admin->id, $expense->recorder->id);
    }

    // ── Livewire component — expense CRUD ────────────────────────────────

    public function test_admin_can_record_expense(): void
    {
        Livewire::test(ExpensesComponent::class)
            ->call('openCreate')
            ->assertSet('showModal', true)
            ->set('state.expense_category_id', $this->cat->id)
            ->set('state.expense_date', today()->toDateString())
            ->set('state.description', 'Monthly electricity bill')
            ->set('state.amount', '340.00')
            ->set('state.reference', 'INV-001')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $this->assertDatabaseHas('expenses', [
            'description' => 'Monthly electricity bill',
            'amount'      => '340.00',
            'reference'   => 'INV-001',
            'recorded_by' => $this->admin->id,
        ]);
    }

    public function test_recording_expense_requires_description_and_amount(): void
    {
        Livewire::test(ExpensesComponent::class)
            ->call('openCreate')
            ->set('state.expense_date', today()->toDateString())
            ->set('state.description', '')
            ->set('state.amount', '')
            ->call('save')
            ->assertHasErrors(['state.description', 'state.amount']);
    }

    public function test_amount_must_be_positive(): void
    {
        Livewire::test(ExpensesComponent::class)
            ->call('openCreate')
            ->set('state.expense_date', today()->toDateString())
            ->set('state.description', 'Test')
            ->set('state.amount', '-50')
            ->call('save')
            ->assertHasErrors(['state.amount']);
    }

    public function test_admin_can_edit_expense(): void
    {
        $expense = Expense::create([
            'expense_date' => today()->toDateString(),
            'description'  => 'Original',
            'amount'       => 100.00,
            'recorded_by'  => $this->admin->id,
        ]);

        Livewire::test(ExpensesComponent::class)
            ->call('openEdit', $expense->id)
            ->assertSet('isEditing', true)
            ->assertSet('state.description', 'Original')
            ->set('state.description', 'Updated')
            ->set('state.amount', '150.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('expenses', [
            'id'          => $expense->id,
            'description' => 'Updated',
            'amount'      => '150.00',
        ]);
    }

    public function test_admin_can_delete_expense(): void
    {
        $expense = Expense::create([
            'expense_date' => today()->toDateString(),
            'description'  => 'To delete',
            'amount'       => 50.00,
            'recorded_by'  => $this->admin->id,
        ]);

        Livewire::test(ExpensesComponent::class)
            ->call('deleteExpense', $expense->id);

        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
    }

    // ── Category management ──────────────────────────────────────────────

    public function test_admin_can_create_category(): void
    {
        Livewire::test(ExpensesComponent::class)
            ->call('openCreateCategory')
            ->assertSet('showCategoryModal', true)
            ->set('categoryState.name', 'Equipment Repairs')
            ->set('categoryState.section', ExpenseCategory::NON_OPERATING)
            ->set('categoryState.color', '#abc123')
            ->call('saveCategory')
            ->assertHasNoErrors()
            ->assertSet('showCategoryModal', false);

        $this->assertDatabaseHas('expense_categories', [
            'name'    => 'Equipment Repairs',
            'section' => 'non_operating_expense',
            'color'   => '#abc123',
        ]);
    }

    public function test_category_name_must_be_unique(): void
    {
        Livewire::test(ExpensesComponent::class)
            ->call('openCreateCategory')
            ->set('categoryState.name', $this->cat->name)
            ->set('categoryState.section', ExpenseCategory::OPERATING)
            ->set('categoryState.color', '#000000')
            ->call('saveCategory')
            ->assertHasErrors(['categoryState.name']);
    }

    public function test_category_section_must_be_valid(): void
    {
        Livewire::test(ExpensesComponent::class)
            ->call('openCreateCategory')
            ->set('categoryState.name', 'New Cat')
            ->set('categoryState.section', 'invalid_section')
            ->set('categoryState.color', '#000000')
            ->call('saveCategory')
            ->assertHasErrors(['categoryState.section']);
    }

    public function test_admin_can_edit_category_section(): void
    {
        Livewire::test(ExpensesComponent::class)
            ->call('openEditCategory', $this->cat->id)
            ->assertSet('isEditingCategory', true)
            ->set('categoryState.section', ExpenseCategory::NON_OPERATING)
            ->call('saveCategory')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('expense_categories', [
            'id'      => $this->cat->id,
            'section' => 'non_operating_expense',
        ]);
    }

    public function test_toggle_category_active(): void
    {
        $this->cat->refresh();
        $this->assertTrue($this->cat->is_active);

        Livewire::test(ExpensesComponent::class)
            ->call('toggleCategoryActive', $this->cat->id);

        $this->assertDatabaseHas('expense_categories', [
            'id'        => $this->cat->id,
            'is_active' => false,
        ]);
    }

    // ── CSV export ───────────────────────────────────────────────────────

    public function test_csv_export_returns_streamed_response(): void
    {
        Expense::create([
            'expense_category_id' => $this->cat->id,
            'expense_date'        => today()->toDateString(),
            'description'         => 'Export test',
            'amount'              => 99.99,
            'recorded_by'         => $this->admin->id,
        ]);

        $component = Livewire::test(ExpensesComponent::class);

        $response = $component->instance()->exportCsv();

        $this->assertNotNull($response);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }
}
