{{-- Expenses from the clinic's previous software (App\Models\LegacyExpense). History only: not in the Expense Tracker or finance reports. --}}
@php
    $money = fn ($amount) => currency().' '.number_format((float) $amount, 2);
    $filtered = $fromDate !== '' || $toDate !== '' || $category !== '' || $search !== '';
@endphp
<div class="clinic-ui ui-page space-y-4">
    <style>
        .lx-filters{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;padding:12px 16px;border-bottom:1px solid var(--clinic-line)}
        .lx-filters .ui-field{margin:0}.lx-filters .lx-search{flex:1;min-width:200px}
        .lx-filters select.ui-input{min-width:180px}
    </style>

    <header class="ui-heading">
        <div>
            <p class="ui-muted">Clinic workspace / Finance / Expenses</p>
            <h1>Previous software expenses</h1>
            <p class="ui-muted">What the clinic spent before it moved here, brought over from its previous software. For reference only: these are not in the Expense Tracker, the income statement or the dashboard.</p>
        </div>
        <div class="ui-actions">
            @if($hasLegacyPayments)<x-ui.button :href="route('admin.sales-records.previous')">Previous software payments</x-ui.button>@endif
            <x-ui.button :href="route('admin.expenses')">Back to Expenses</x-ui.button>
        </div>
    </header>

    <section class="ui-tiles" aria-label="Summary">
        <x-ui.stat-tile :label="$filtered ? 'Total in these filters' : 'Total'" :value="$money($total)" :hint="number_format($expenses->total()).' '.Str::plural('entry', $expenses->total())" />
        <x-ui.stat-tile label="Covers" :value="$firstDay ? $firstDay->format('M Y').' – '.$lastDay->format('M Y') : '—'" :hint="number_format($allCount).' entries in all'" />
        <x-ui.stat-tile label="Categories" :value="number_format($categories->count())" hint="As named in the previous software" />
    </section>

    <x-ui.panel style="padding:0">
        <h2 class="sr-only">Previous software expense records</h2>
        <div class="lx-filters">
            <x-date-range from="fromDate" to="toDate" presets="finance" label="Dates" clearable />
            <x-ui.field label="Category" name="category" :options="['' => 'All categories'] + $categories->combine($categories)->all()" wire:model.live="category" />
            <div class="lx-search"><x-ui.field label="Search" name="search" wire:model.live.debounce.300ms="search" placeholder="Description, reference or note" /></div>
            @if($filtered)<x-ui.button wire:click="resetFilters">Reset filters</x-ui.button>@endif
            <span class="ui-muted text-xs" wire:loading role="status">Updating…</span>
        </div>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <caption class="sr-only">Expenses from the previous software matching the selected filters.</caption>
                <thead><tr><th scope="col">Date</th><th scope="col">Description</th><th scope="col">Category</th><th scope="col">Recorded by</th><th scope="col" class="ui-number">Amount</th></tr></thead>
                <tbody wire:loading.class="opacity-50">
                @forelse($expenses as $expense)
                    <tr wire:key="legacy-expense-{{ $expense->id }}">
                        <td class="whitespace-nowrap">{{ $expense->expense_date->format('d M Y') }}</td>
                        <td>
                            <span class="font-semibold">{{ $expense->description }}</span>
                            @if($expense->reference)<span class="block text-xs text-slate-500">Ref {{ $expense->reference }}</span>@endif
                            @if($expense->notes)<span class="block text-xs text-slate-600">{{ $expense->notes }}</span>@endif
                        </td>
                        <td class="whitespace-nowrap">{{ $expense->category ?? 'Uncategorised' }}</td>
                        <td class="whitespace-nowrap text-slate-600">{{ $expense->recorded_by ?? '—' }}</td>
                        <td class="ui-number whitespace-nowrap font-semibold">{{ $money($expense->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="ui-empty">
                        @if($filtered)
                            <h2>No expenses match these filters</h2><p class="ui-muted">Try other dates or clear the filters.</p>
                            <x-ui.button wire:click="resetFilters">Reset filters</x-ui.button>
                        @else
                            <h2>No expenses from previous software</h2><p class="ui-muted">Expenses appear here when the clinic's records are brought over from software it used before.</p>
                        @endif
                    </div></td></tr>
                @endforelse
                </tbody>
                @if($expenses->isNotEmpty())<tfoot><tr><th colspan="4" scope="row" class="ui-number">{{ $expenses->hasPages() ? 'Page total' : 'Total' }}</th><td class="ui-number font-bold">{{ $money($expenses->sum('amount')) }}</td></tr></tfoot>@endif
            </table>
        </div>
        @if($expenses->hasPages())
            <div class="ui-panel-heading" style="padding:10px 16px">
                <p class="ui-muted">{{ $expenses->total() }} {{ Str::plural('entry', $expenses->total()) }}</p>
                <div class="ui-actions">
                    <x-ui.button wire:click="previousPage" :disabled="$expenses->onFirstPage()" aria-label="Previous page">Previous</x-ui.button>
                    <span class="ui-muted">Page {{ $expenses->currentPage() }} of {{ $expenses->lastPage() }}</span>
                    <x-ui.button wire:click="nextPage" :disabled="!$expenses->hasMorePages()" aria-label="Next page">Next</x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.panel>
</div>
