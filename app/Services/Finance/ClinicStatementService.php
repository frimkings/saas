<?php

namespace App\Services\Finance;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\IncomeStatementEntry;
use App\Models\SaleItem;
use App\Support\BusinessLine;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The clinic income statement for a period: revenue and cost of sales from clinic sales
 * (grouped by product category); expenses from the Expense Tracker (grouped by category,
 * in the section the category reports under) plus manual statement entries; tax from entries.
 */
class ClinicStatementService
{
    /** Clinic sale lines in the period, joined to their product and category. */
    public function salesLines(CarbonInterface $from, CarbonInterface $to)
    {
        $query = SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('products', function ($join) {
                $join->on('sale_items.product_id', '=', 'products.id')
                    ->on('sale_items.clinic_id', '=', 'products.clinic_id');
            })
            ->leftJoin('categories', function ($join) {
                $join->on('products.category_id', '=', 'categories.id')
                    ->on('products.clinic_id', '=', 'categories.clinic_id');
            })
            ->where('sales.business_line', BusinessLine::CLINIC)
            ->where('sales.is_refunded', false)
            ->whereBetween('sales.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        $context = app(TenantContext::class);
        if ($context->clinicId() !== null) $query->where('sales.clinic_id', $context->clinicId());
        if ($context->branchId() !== null) $query->where('sales.branch_id', $context->branchId());

        return $query;
    }

    /** @return Collection<int, object{name: string, amount: string}> */
    public function revenueLines(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->salesLines($from, $to)
            ->selectRaw("COALESCE(categories.name, 'Uncategorized') as name")
            ->selectRaw('SUM(sale_items.subtotal) as amount')
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, object{name: string, amount: string}> */
    public function costLines(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->salesLines($from, $to)
            ->selectRaw("COALESCE(categories.name, 'Uncategorized') as name")
            ->selectRaw('SUM(sale_items.dispensed_quantity * COALESCE(sale_items.unit_cost, products.cost_price, 0)) as amount')
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('name')
            ->get();
    }

    public function entries(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return IncomeStatementEntry::businessLine(BusinessLine::CLINIC)
            ->where('is_active', true)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('entry_date')
            ->orderBy('name')
            ->get();
    }

    /**
     * Clinic expenses recorded in the Expense Tracker, per category.
     *
     * @return Collection<int, array{name: string, amount: float, section: string}>
     */
    public function trackedExpenses(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Expense::businessLine(BusinessLine::CLINIC)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('expense_category_id, SUM(amount) as total')
            ->groupBy('expense_category_id')
            ->with('category')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->category?->name ?? 'Uncategorised',
                'amount' => (float) $row->total,
                'section' => $row->category?->section ?? ExpenseCategory::OPERATING,
            ])
            ->sortBy('name')
            ->values();
    }

    /** Statement totals, with the lines they are built from. */
    public function statement(CarbonInterface $from, CarbonInterface $to): array
    {
        $revenueLines = $this->revenueLines($from, $to);
        $costLines = $this->costLines($from, $to);
        $entries = $this->entries($from, $to);
        $operatingLines = $entries->where('section', IncomeStatementEntry::OPERATING_EXPENSE);
        $nonOperatingLines = $entries->where('section', IncomeStatementEntry::NON_OPERATING_EXPENSE);
        $taxEntry = $entries->where('section', IncomeStatementEntry::TAX)->sortByDesc('entry_date')->first();
        $tracked = $this->trackedExpenses($from, $to);
        $trackedNonOperating = $tracked->where('section', ExpenseCategory::NON_OPERATING)->values();
        $trackedOperating = $tracked->where('section', '!=', ExpenseCategory::NON_OPERATING)->values();

        $revenue = (float) $revenueLines->sum('amount');
        $costOfSales = (float) $costLines->sum('amount');
        $grossProfit = $revenue - $costOfSales;
        $operatingExpenses = (float) $operatingLines->sum('amount') + (float) $trackedOperating->sum('amount');
        $operatingProfit = $grossProfit - $operatingExpenses;
        $nonOperatingExpenses = (float) $nonOperatingLines->sum('amount') + (float) $trackedNonOperating->sum('amount');
        $profitForPeriod = $operatingProfit - $nonOperatingExpenses;
        $taxRate = $taxEntry ? (float) $taxEntry->percentage : 0;
        $taxAmount = $profitForPeriod > 0 ? $profitForPeriod * ($taxRate / 100) : 0;

        return [
            'revenue' => $revenue,
            'cost_of_sales' => $costOfSales,
            'gross_profit' => $grossProfit,
            'operating_expenses' => $operatingExpenses,
            'operating_profit' => $operatingProfit,
            'non_operating_expenses' => $nonOperatingExpenses,
            'profit_for_period' => $profitForPeriod,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'net_profit' => $profitForPeriod - $taxAmount,
            'revenue_lines' => $revenueLines,
            'cost_lines' => $costLines,
            'operating_lines' => $operatingLines,
            'non_operating_lines' => $nonOperatingLines,
            'tracked_operating_lines' => $trackedOperating,
            'tracked_non_operating_lines' => $trackedNonOperating,
            'tax_entry' => $taxEntry,
        ];
    }
}
