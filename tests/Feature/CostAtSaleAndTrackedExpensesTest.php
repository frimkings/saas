<?php

namespace Tests\Feature;

use App\Livewire\Admin\ExpensesComponent;
use App\Livewire\Admin\IncomeStatementComponent;
use App\Models\Category;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\IncomeStatementEntry;
use App\Models\OpticalCategory;
use App\Models\OpticalProduct;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Models\User;
use App\Services\Finance\ClinicStatementService;
use App\Services\Finance\PeriodLockService;
use App\Services\OpticalProfitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CostAtSaleAndTrackedExpensesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $this->actingAs($this->owner);
    }

    private function sale(string $line, string $reference, float $total): Sales
    {
        return Sales::create(['user_id' => $this->owner->id, 'business_line' => $line, 'transaction_id' => $reference,
            'total_amount' => $total, 'amount_paid' => $total, 'payment_status' => 'paid']);
    }

    public function test_cost_of_sales_uses_what_items_cost_when_sold(): void
    {
        $category = Category::create(['user_id' => $this->owner->id, 'name' => 'Drops', 'type' => 'drug']);
        $drops = Product::create(['user_id' => $this->owner->id, 'category_id' => $category->id, 'name' => 'Eye drops', 'quantity' => 10, 'cost_price' => 10, 'selling_price' => 25]);
        $item = SaleItem::create(['sale_id' => $this->sale('clinic', 'CLINIC-COST-1', 50)->id, 'product_id' => $drops->id,
            'prescribed_quantity' => 2, 'dispensed_quantity' => 2, 'selling_price' => 25, 'subtotal' => 50]);
        $this->assertEquals(10, $item->fresh()->unit_cost, 'Cost is kept when the item is sold.');

        $opticalCategory = OpticalCategory::create(['code' => 'FRAMES', 'name' => 'Frames', 'is_active' => true]);
        $frame = OpticalProduct::create(['optical_category_id' => $opticalCategory->id, 'name' => 'Cost Frame', 'sku' => 'CF-1', 'selling_price' => 200, 'cost_price' => 80]);
        SaleItem::create(['sale_id' => $this->sale('optical', 'OPOS-COST-1', 200)->id, 'optical_product_id' => $frame->id,
            'prescribed_quantity' => 0, 'dispensed_quantity' => 1, 'selling_price' => 200, 'subtotal' => 200]);

        // Prices change afterwards: past profit must not move.
        $drops->update(['cost_price' => 18]);
        $frame->update(['cost_price' => 130]);
        $item->update(['notes' => 'edited later']);
        $this->assertEquals(10, $item->fresh()->unit_cost, 'Editing an item keeps its original cost.');

        $clinic = app(ClinicStatementService::class)->statement(now()->startOfMonth(), now());
        $this->assertSame(20.0, $clinic['cost_of_sales']);
        $optical = app(OpticalProfitService::class)->statement(now()->startOfMonth(), now());
        $this->assertSame(80.0, $optical['costs']['Retail items']);

        // An explicitly recorded cost (e.g. from a stock lot) is kept as given.
        $explicit = SaleItem::create(['sale_id' => $this->sale('clinic', 'CLINIC-COST-2', 25)->id, 'product_id' => $drops->id,
            'prescribed_quantity' => 1, 'dispensed_quantity' => 1, 'selling_price' => 25, 'subtotal' => 25, 'unit_cost' => 12]);
        $this->assertEquals(12, $explicit->fresh()->unit_cost);
    }

    public function test_clinic_statement_includes_tracked_expenses_and_locks_them(): void
    {
        $from = now()->subMonthNoOverflow()->startOfMonth();
        $to = now()->subMonthNoOverflow()->endOfMonth();
        $rent = ExpenseCategory::create(['name' => 'Rent', 'section' => ExpenseCategory::OPERATING, 'is_active' => true]);
        $loan = ExpenseCategory::create(['name' => 'Loan repayments', 'section' => ExpenseCategory::NON_OPERATING, 'is_active' => true]);
        $clinicRent = Expense::create(['expense_category_id' => $rent->id, 'expense_date' => $from->toDateString(), 'description' => 'Clinic rent', 'amount' => 900, 'recorded_by' => $this->owner->id]);
        Expense::create(['expense_category_id' => $loan->id, 'expense_date' => $from->toDateString(), 'description' => 'Loan', 'amount' => 150, 'recorded_by' => $this->owner->id]);
        Expense::create(['expense_category_id' => $rent->id, 'expense_date' => $from->toDateString(), 'description' => 'Shop rent', 'amount' => 400, 'recorded_by' => $this->owner->id, 'business_line' => 'optical']);
        IncomeStatementEntry::create(['section' => IncomeStatementEntry::OPERATING_EXPENSE, 'name' => 'Salaries', 'amount' => 2000, 'entry_date' => $from->toDateString(), 'is_active' => true]);

        $page = Livewire::test(IncomeStatementComponent::class)->set('fromDate', $from->toDateString())->set('toDate', $to->toDateString())
            ->assertSee('Salaries')->assertSee('Loan repayments')->assertDontSee('Preview &amp; Import', false);
        $statement = $page->instance()->statement;
        $this->assertSame(2900.0, $statement['operating_expenses'], 'Manual salaries plus tracked clinic rent; optical rent excluded.');
        $this->assertSame(150.0, $statement['non_operating_expenses'], 'Tracked loan goes where its category reports.');

        $csv = $this->get(route('admin.income-statement.export.csv', ['from' => $from->toDateString(), 'to' => $to->toDateString()]))->streamedContent();
        $this->assertStringContainsString('Rent,900.00,"Expense Tracker"', $csv);
        $this->assertStringContainsString('"Loan repayments",150.00,"Expense Tracker"', $csv);

        // Closing the clinic period freezes clinic expenses in it; optical ones stay open.
        $page->call('lockPeriod');
        Livewire::test(ExpensesComponent::class)->call('openEdit', $clinicRent->id)->set('state.amount', '1')->call('save')
            ->assertHasErrors('state.expense_date');
        $this->assertEquals(900, $clinicRent->fresh()->amount);
        $this->assertNull(app(PeriodLockService::class)->covering('optical', $from->toDateString()));
    }
}
