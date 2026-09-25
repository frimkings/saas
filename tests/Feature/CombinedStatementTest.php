<?php

namespace Tests\Feature;

use App\Livewire\Admin\CombinedStatementComponent;
use App\Livewire\Admin\IncomeStatementComponent;
use App\Livewire\Optical\OpticalProfitComponent;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\IncomeStatementEntry;
use App\Models\User;
use App\Services\Finance\CombinedStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** An offline install with every feature runs both businesses, so it gets the combined view. */
class CombinedStatementTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $this->actingAs($user);
        return $user;
    }

    public function test_combined_statement_adds_each_business_at_the_totals(): void
    {
        $owner = $this->owner();
        $today = now()->toDateString();
        IncomeStatementEntry::create(['section' => IncomeStatementEntry::OPERATING_EXPENSE, 'name' => 'Clinic rent', 'amount' => 1000, 'entry_date' => $today, 'is_active' => true]);
        IncomeStatementEntry::create(['section' => IncomeStatementEntry::NON_OPERATING_EXPENSE, 'name' => 'Loan interest', 'amount' => 200, 'entry_date' => $today, 'is_active' => true]);
        IncomeStatementEntry::create(['section' => IncomeStatementEntry::TAX, 'name' => 'Tax', 'amount' => 0, 'percentage' => 25, 'entry_date' => $today, 'is_active' => true]);
        // Clinic revenue of 3,000 from a sale with no stock product (so no cost of sales).
        $sale = \App\Models\Sales::create(['user_id' => $owner->id, 'business_line' => 'clinic', 'transaction_id' => 'CLINIC-COMBINED-1',
            'total_amount' => 3000, 'amount_paid' => 3000, 'payment_status' => 'paid']);
        \App\Models\SaleItem::create(['sale_id' => $sale->id, 'prescribed_quantity' => 1, 'dispensed_quantity' => 1, 'selling_price' => 3000, 'subtotal' => 3000]);
        $rent = ExpenseCategory::create(['name' => 'Shop rent', 'section' => ExpenseCategory::OPERATING, 'is_active' => true]);
        $cleaning = ExpenseCategory::create(['name' => 'Cleaning', 'section' => ExpenseCategory::OPERATING, 'is_active' => true]);
        Expense::create(['expense_category_id' => $rent->id, 'expense_date' => $today, 'description' => 'Shop rent', 'amount' => 300, 'recorded_by' => $owner->id, 'business_line' => 'optical']);
        // Clinic expenses in the Expense Tracker count on the clinic statement without importing.
        Expense::create(['expense_category_id' => $cleaning->id, 'expense_date' => $today, 'description' => 'Clinic cleaning', 'amount' => 100, 'recorded_by' => $owner->id]);

        $statement = app(CombinedStatementService::class)->statement(now()->startOfMonth(), now());
        $this->assertSame(1100.0, $statement['lines']['clinic']['operating_expenses'], 'Manual rent plus tracked cleaning.');
        $this->assertSame(1700.0, $statement['lines']['clinic']['profit_before_tax']);
        $this->assertSame(425.0, $statement['lines']['clinic']['tax'], '25% of clinic profit, as on the clinic statement.');
        $this->assertSame(-300.0, $statement['lines']['optical']['net_profit']);
        $this->assertSame(0.0, $statement['lines']['optical']['tax'], 'No tax is invented for optical.');
        $this->assertSame(1400.0, $statement['total']['profit_before_tax']);
        $this->assertSame(975.0, $statement['total']['net_profit']);

        Livewire::test(CombinedStatementComponent::class)
            ->assertSee('Combined Statement')->assertSee('975.00')->assertSee('25.00% of clinic profit')
            ->assertSee(e(route('admin.income-statement', ['fromDate' => now()->startOfMonth()->toDateString(), 'toDate' => $today])), false);

        $csv = $this->get(route('admin.combined-statement.export', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Whole business"', $csv);
        $this->assertStringContainsString('"Profit before tax",1400.00', $csv);
        $this->assertStringContainsString('"Net profit",975.00', $csv);
        $this->get(route('admin.combined-statement.export', ['format' => 'pdf']))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_statement_pages_link_to_each_other_and_the_combined_view(): void
    {
        $this->owner();

        Livewire::test(IncomeStatementComponent::class)->assertSee(e(route('admin.combined-statement', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()])), false);
        Livewire::test(OpticalProfitComponent::class)->assertSee('Combined')->assertSee(e(route('optical.profit', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()])), false);
        $this->get(route('admin.combined-statement'))->assertOk();
    }

    public function test_managers_without_billing_access_cannot_see_the_combined_view(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $this->actingAs($manager);

        $this->get(route('admin.combined-statement'))->assertForbidden();
        $this->get(route('admin.combined-statement.export', ['format' => 'csv']))->assertForbidden();
        Livewire::test(OpticalProfitComponent::class)->assertDontSee('Combined');
    }
}
