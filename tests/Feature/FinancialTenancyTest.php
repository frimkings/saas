<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Sales;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Livewire\Admin\ExpensesComponent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

class FinancialTenancyTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private const BRANCH_TABLES = [
        'sales', 'sale_items', 'payment_transactions', 'sale_adjustments',
        'refund_logs', 'discount_approval_requests', 'clearance_revoke_logs',
        'expenses', 'income_statement_entries', 'income_statement_period_locks',
        'insurance_claims', 'quotations', 'quotation_items', 'carts', 'orders',
    ];

    public function test_financial_tables_are_tenant_ready_and_backfilled(): void
    {
        foreach (self::BRANCH_TABLES as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'clinic_id'));
            $this->assertTrue(Schema::hasColumn($table, 'branch_id'));
            $this->assertSame(0, DB::table($table)->whereNull('clinic_id')->count());
            $this->assertSame(0, DB::table($table)->whereNull('branch_id')->count());
        }

        foreach (['expense_categories', 'income_statement_templates'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'clinic_id'));
            $this->assertSame(0, DB::table($table)->whereNull('clinic_id')->count());
        }
    }

    public function test_sales_references_can_repeat_across_clinics_and_are_isolated(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        [$clinicA, $branchA] = $this->membership($user, 'Clinic A', 'clinic-a');
        [$clinicB, $branchB] = $this->membership($user, 'Clinic B', 'clinic-b');
        $context = app(TenantContext::class);

        $context->set($user, $clinicA, $branchA, [$branchA->id]);
        $saleA = Sales::create($this->saleData($user, 'TX-SHARED'));

        $context->set($user, $clinicB, $branchB, [$branchB->id]);
        $saleB = Sales::create($this->saleData($user, 'TX-SHARED'));

        $this->assertSame([$saleB->id], Sales::query()->pluck('id')->all());
        $context->set($user, $clinicA, $branchA, [$branchA->id]);
        $this->assertSame([$saleA->id], Sales::query()->pluck('id')->all());
    }

    public function test_expense_cannot_use_another_clinics_category(): void
    {
        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);
        [$clinicA, $branchA] = $this->membership($user, 'Expense A', 'expense-a');
        [$clinicB, $branchB] = $this->membership($user, 'Expense B', 'expense-b');
        $context = app(TenantContext::class);
        $context->set($user, $clinicB, $branchB, [$branchB->id]);
        $foreignCategory = ExpenseCategory::create(['name' => 'Private B', 'section' => ExpenseCategory::OPERATING]);
        $context->set($user, $clinicA, $branchA, [$branchA->id]);
        $this->actingAs($user);

        Livewire::test(ExpensesComponent::class)
            ->set('state.expense_category_id', $foreignCategory->id)
            ->set('state.expense_date', now()->toDateString())
            ->set('state.description', 'Forged category expense')
            ->set('state.amount', '50.00')
            ->call('save')
            ->assertHasErrors(['state.expense_category_id']);

        $this->assertDatabaseMissing('expenses', ['description' => 'Forged category expense']);
    }

    private function saleData(User $user, string $transaction): array
    {
        return [
            'user_id' => $user->id,
            'transaction_id' => $transaction,
            'total_amount' => 100,
            'amount_paid' => 100,
            'payment_status' => 'paid',
            'profit' => 20,
        ];
    }

    private function membership(User $user, string $name, string $slug): array
    {
        $clinic = $this->activeClinic(['name' => $name, 'slug' => $slug]);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $clinic->users()->attach($user, ['status' => 'active']);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        return [$clinic, $branch];
    }
}
