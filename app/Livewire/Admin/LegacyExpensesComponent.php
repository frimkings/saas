<?php

namespace App\Livewire\Admin;

use App\Models\LegacyExpense;
use App\Support\Feature;
use App\Services\LicenseService;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only list of expenses brought over from the clinic's previous software (App\Models\LegacyExpense).
 * History for owners: these are not in the Expense Tracker, the income statement or the dashboard.
 */
class LegacyExpensesComponent extends Component
{
    use WithPagination;

    public string $fromDate = '';
    public string $toDate = '';
    public string $category = '';
    public string $search = '';

    public function mount(): void
    {
        abort_if(! LicenseService::has(Feature::EXPENSE_TRACKING), 403);
        abort_if(! auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['fromDate', 'toDate', 'category', 'search'], true)) $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('fromDate', 'toDate', 'category', 'search');
        $this->resetPage();
    }

    private function query()
    {
        $day = fn ($value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;

        return LegacyExpense::query()
            ->when($day($this->fromDate), fn ($q, $from) => $q->where('expense_date', '>=', $from))
            ->when($day($this->toDate), fn ($q, $to) => $q->where('expense_date', '<=', $to))
            ->when($this->category !== '', fn ($q) => $q->where('category', $this->category))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('description', 'like', $term)->orWhere('reference', 'like', $term)->orWhere('notes', 'like', $term));
            });
    }

    public function render()
    {
        $expenses = $this->query()->orderByDesc('expense_date')->orderByDesc('id')->paginate(25);
        $total = (float) $this->query()->sum('amount');
        $span = LegacyExpense::query()->selectRaw('MIN(expense_date) as first_day, MAX(expense_date) as last_day, COUNT(*) as n')->first();
        $categories = LegacyExpense::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category');

        return view('livewire.admin.legacy-expenses-component', [
            'expenses' => $expenses, 'total' => $total, 'categories' => $categories,
            'firstDay' => $span?->first_day ? Carbon::parse($span->first_day) : null,
            'lastDay' => $span?->last_day ? Carbon::parse($span->last_day) : null,
            'allCount' => (int) ($span?->n ?? 0),
            'hasLegacyPayments' => \App\Models\PatientLegacyPayment::query()->exists(),
        ])->layout('layouts.admin.admin-layout');
    }
}
