@php
    $money = fn ($amount) => currency().' '.number_format((float) $amount, 2);
    $isManager = auth()->user()->hasAnyRole(['Manager', 'Super Admin']);
    $change = $previousTotal > 0 ? ($totalInRange - $previousTotal) / $previousTotal * 100 : null;
    $splitTotal = max(0.01, (float) $categoryTotals->sum('total'));
    $filtered = $categoryId !== '' || $search !== '' || $receipt !== '';
    $dot = fn ($category) => $category?->color ?: '#94a3b8';
@endphp
<div class="clinic-ui ui-page space-y-4" x-data="{ ...expenseForm('{{ today()->toDateString() }}'), cats: false }" x-on:keydown.escape.window="if ($event.target.closest?.('.app-confirm, dialog, .swal2-container')) return; open ? close() : (cats = false)">
    <style>
        .ex-filters select.ui-input{min-width:180px;padding-right:34px;text-overflow:ellipsis}
        .ex-fresh .ui-error{display:none}.ex-fresh [aria-invalid=true]{border-color:var(--clinic-line)}
        .ex-sum{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) minmax(0,2fr);gap:12px}
        @media(max-width:1100px){.ex-sum{grid-template-columns:repeat(3,minmax(0,1fr))}.ex-split{grid-column:1/-1}}
        @media(max-width:640px){.ex-sum{grid-template-columns:minmax(0,1fr)}}
        .ex-card{border:1px solid var(--clinic-line);border-radius:12px;background:#fff;padding:14px 16px;min-width:0;display:flex;flex-direction:column;gap:3px;text-align:left}
        .ex-card>span{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:var(--clinic-muted)}
        .ex-card b{font-size:21px;font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .ex-card small{font-size:12px;color:var(--clinic-muted)}
        button.ex-card{cursor:pointer}button.ex-card:hover{border-color:#9fb9bd}
        .ex-chg{font-size:12px;font-weight:700}.ex-chg.up{color:#b91c1c}.ex-chg.down{color:#047857}
        .ex-bar{display:flex;height:12px;border-radius:999px;overflow:hidden;background:#f1f5f9;margin:6px 0 8px}
        .ex-bar button{height:100%;border:0;padding:0;cursor:pointer}.ex-bar button:hover{filter:brightness(.9)}
        .ex-legend{display:flex;flex-wrap:wrap;gap:4px 12px;font-size:12px}
        .ex-legend button{border:0;background:none;padding:0;color:var(--clinic-ink);cursor:pointer;display:inline-flex;align-items:center;gap:5px}
        .ex-legend button:hover{text-decoration:underline}.ex-legend b{font-size:12px;font-weight:600;color:var(--clinic-muted)}
        .ex-dot{display:inline-block;width:9px;height:9px;border-radius:3px;flex:none}
        .ex-due{border:1px solid #fde68a;background:#fffbeb;border-radius:12px;padding:10px 14px}
        .ex-due h2{font-size:13px;font-weight:800;color:#78350f;margin:0 0 6px}
        .ex-due li{display:flex;flex-wrap:wrap;align-items:center;gap:6px 12px;padding:6px 0;border-top:1px solid #fde68a;font-size:13px}
        .ex-due li:first-child{border-top:0}
        .ex-filters{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;padding:12px 16px;border-bottom:1px solid var(--clinic-line)}
        .ex-filters .ui-field{margin:0}.ex-filters .ex-search{flex:1;min-width:200px}
        .ex-chip{border:1px solid var(--clinic-line);background:#fff;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:600;color:var(--clinic-muted);cursor:pointer;white-space:nowrap}
        .ex-chip[aria-pressed=true]{background:var(--clinic-accent);border-color:var(--clinic-accent);color:#fff}
        .ex-table tbody tr{cursor:pointer}.ex-table tbody tr:hover{background:#f6fafa}
        .ex-table td{vertical-align:middle}
        .ex-open{border:0;background:none;padding:0;text-align:left;font:inherit;color:inherit;cursor:pointer}
        .ex-open:focus-visible{outline:2px solid var(--clinic-accent);outline-offset:2px}
        .ex-overlay{position:fixed;inset:0;z-index:1040;background:rgb(15 23 42 / .45)}
        .ex-drawer{position:fixed;top:0;right:0;z-index:1041;height:100dvh;width:min(520px,100vw);background:#fff;display:flex;flex-direction:column;box-shadow:-16px 0 40px rgb(15 23 42 / .2);outline:none}
        .ex-drawer header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:16px 20px;border-bottom:1px solid var(--clinic-line)}
        .ex-drawer header h2{margin:0;font-size:17px;font-weight:800}
        .ex-drawer .ex-body{flex:1;overflow-y:auto;padding:16px 20px}
        .ex-drawer footer{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:12px 20px;border-top:1px solid var(--clinic-line);background:#fafcfc}
        .ex-x{border:0;background:none;font-size:22px;line-height:1;color:var(--clinic-muted);cursor:pointer;padding:2px 6px;border-radius:6px}.ex-x:hover{background:#f1f5f9}
        .ex-pay{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
        .ex-pay label{display:flex;align-items:center;gap:8px;border:1px solid var(--clinic-line);border-radius:8px;padding:8px 10px;font-size:13px;cursor:pointer}
        .ex-pay label:has(input:checked){border-color:var(--clinic-accent);background:#e8f4f3;font-weight:700}
    </style>

    <header class="ui-heading">
        @if($isOptical)
            <div><p class="ui-muted">Optical workspace / Finance</p><h1>Optical Expenses</h1><p class="ui-muted">Shop running costs, receipts, and categories. They feed the optical Profit &amp; Loss and are kept separate from clinic expenses.</p></div>
        @else
            <div><p class="ui-muted">Clinic workspace / Finance</p><h1>Expenses</h1><p class="ui-muted">Track clinic spending, receipts, and categories.</p></div>
        @endif
        <div class="ui-actions">
            @if($statementLink)<x-ui.button :href="$statementLink['url']">{{ $statementLink['label'] }}</x-ui.button>@endif
            @if($isManager)<x-ui.button x-on:click="cats = true">Categories</x-ui.button>@endif
            <x-ui.button wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv">Export CSV</x-ui.button>
            <x-ui.button variant="primary" x-on:click="create()">Record expense</x-ui.button>
        </div>
    </header>

    {{-- Repeating bills that are due, or due within a week. --}}
    @if($due->isNotEmpty())
        <section class="ex-due" aria-label="Repeating expenses due">
            <h2>⏰ Repeating expenses due</h2>
            <ul class="m-0 list-none p-0">
                @foreach($due as $item)
                    <li wire:key="due-{{ $item->id }}">
                        <span class="ex-dot" style="background:{{ $dot($item->category) }}"></span>
                        <span class="min-w-0 flex-1"><b>{{ $item->description }}</b>@if($item->payee) <span class="text-slate-600">· {{ $item->payee }}</span>@endif</span>
                        <span class="whitespace-nowrap tabular-nums font-semibold">{{ $money($item->amount) }}</span>
                        <span class="whitespace-nowrap text-xs {{ $item->isOverdue() ? 'font-bold text-red-700' : 'text-amber-900' }}">{{ $item->isOverdue() ? 'Overdue since' : ($item->next_due_date->isToday() ? 'Due today,' : 'Due') }} {{ $item->next_due_date->format('d M') }}</span>
                        <span class="flex gap-1">
                            <button type="button" class="ui-button ui-button-primary" x-on:click="recordDue($el)" data-recurring="{{ json_encode(['id' => $item->id, 'form' => [
                                'expense_date' => $item->next_due_date->toDateString(), 'amount' => (string) $item->amount, 'payee' => $item->payee ?? '',
                                'expense_category_id' => (string) ($item->expense_category_id ?? ''), 'description' => $item->description, 'payment_method' => $item->payment_method ?? '',
                            ]]) }}">Record</button>
                            @if($isManager)<x-ui.button wire:click="skipRecurring({{ $item->id }})" wire:confirm="Skip {{ $item->description }} due {{ $item->next_due_date->format('d M') }}? Nothing is recorded and it moves to its next date.">Skip</x-ui.button>@endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="ex-sum" aria-label="Spending summary">
        <div class="ex-card">
            <span>Spent in these dates</span>
            <b>{{ $money($totalInRange) }}</b>
            @if($change !== null)
                <span class="ex-chg {{ $change > 0 ? 'up' : ($change < 0 ? 'down' : '') }}" title="Compared with {{ $previousLabel }}">{{ $change > 0 ? '▲' : ($change < 0 ? '▼' : '■') }} {{ number_format(abs($change), 1) }}% <span class="font-normal text-slate-500">vs {{ $previousLabel }}</span></span>
            @else
                <small>Nothing recorded {{ $previousLabel }} to compare</small>
            @endif
        </div>
        <div class="ex-card">
            <span>Biggest cost</span>
            @php $top = $categoryTotals->first(); @endphp
            <b>{{ $top ? (optional($top->category)->name ?? 'Uncategorised') : '—' }}</b>
            <small>{{ $top ? $money($top->total).' · '.number_format($top->total / $splitTotal * 100, 0).'% of spending' : 'No spending in these dates' }}</small>
        </div>
        <button type="button" class="ex-card" wire:click="$set('receipt', '{{ $receipt === 'missing' ? '' : 'missing' }}')" aria-pressed="{{ $receipt === 'missing' ? 'true' : 'false' }}">
            <span>Without a receipt</span>
            <b class="{{ $missingReceipts ? 'text-amber-700' : '' }}">{{ $missingReceipts }}</b>
            <small>{{ $receipt === 'missing' ? 'Showing only these · click to show all' : ($missingReceipts ? 'Click to list them' : 'Every expense has its receipt') }}</small>
        </button>
        <div class="ex-card ex-split">
            <span>Where the money went</span>
            @if($categoryTotals->isNotEmpty())
                <div class="ex-bar" role="img" aria-label="Spending by category">
                    @foreach($categoryTotals as $row)
                        <button type="button" wire:click="$set('categoryId', '{{ $row->expense_category_id }}')" style="width:{{ $row->total / $splitTotal * 100 }}%;background:{{ $dot($row->category) }}" title="{{ optional($row->category)->name ?? 'Uncategorised' }}: {{ $money($row->total) }}" aria-label="Show {{ optional($row->category)->name ?? 'Uncategorised' }}"></button>
                    @endforeach
                </div>
                <div class="ex-legend">
                    @foreach($categoryTotals->take(6) as $row)
                        <button type="button" wire:click="$set('categoryId', '{{ $row->expense_category_id }}')"><span class="ex-dot" style="background:{{ $dot($row->category) }}"></span>{{ optional($row->category)->name ?? 'Uncategorised' }} <b>{{ number_format($row->total / $splitTotal * 100, 0) }}%</b></button>
                    @endforeach
                </div>
            @else
                <small>No spending recorded in these dates.</small>
            @endif
        </div>
    </section>

    <x-ui.panel style="padding:0">
        <h2 class="sr-only">Expense records</h2>
        <div class="ex-filters">
            <x-date-range from="fromDate" to="toDate" presets="finance" label="Dates" />
            <x-ui.field label="Category" name="categoryId" :options="['' => 'All categories'] + $categories->sortBy('name')->pluck('name', 'id')->all()" wire:model.live="categoryId" />
            <div class="ex-search"><x-ui.field label="Search" name="search" wire:model.live.debounce.300ms="search" placeholder="Paid to, description or reference" /></div>
            <button type="button" class="ex-chip" wire:click="$set('receipt', '{{ $receipt === 'missing' ? '' : 'missing' }}')" aria-pressed="{{ $receipt === 'missing' ? 'true' : 'false' }}">No receipt</button>
            @if($filtered)<x-ui.button wire:click="resetFilters">Reset filters</x-ui.button>@endif
            <span class="ui-muted text-xs" wire:loading role="status">Updating…</span>
        </div>
        <div class="ui-table-wrap">
            <table class="ui-table ex-table">
                <caption class="sr-only">Expenses matching the selected dates and filters. Choose one to view or edit it.</caption>
                <thead><tr><th scope="col">Date</th><th scope="col">Paid to &amp; description</th><th scope="col">Category</th><th scope="col">Paid with</th><th scope="col"><span class="sr-only">Receipt</span></th><th scope="col" class="ui-number">Amount</th></tr></thead>
                <tbody wire:loading.class="opacity-50">
                @forelse($expenses as $expense)
                    {{-- The row carries its own details, so opening it needs no server call. --}}
                    <tr wire:key="expense-{{ $expense->id }}" x-on:click="edit($el)" data-expense="{{ json_encode(['id' => $expense->id, 'receiptUrl' => $expense->receipt_url, 'form' => [
                        'expense_date' => $expense->expense_date->toDateString(), 'amount' => (string) $expense->amount, 'payee' => $expense->payee ?? '',
                        'expense_category_id' => (string) ($expense->expense_category_id ?? ''), 'description' => $expense->description,
                        'payment_method' => $expense->payment_method ?? '', 'reference' => $expense->reference ?? '', 'notes' => $expense->notes ?? '',
                    ]]) }}">
                        <td class="whitespace-nowrap">{{ $expense->expense_date->format('d M Y') }}</td>
                        <td>
                            <button type="button" class="ex-open" x-on:click.stop="edit($el.closest('tr'))" aria-label="Open expense {{ $expense->description }}">
                                @if($expense->payee)<b class="block">{{ $expense->payee }}</b>@endif
                                <span class="{{ $expense->payee ? 'text-slate-600' : 'font-semibold' }}">{{ $expense->description }}</span>
                                @if($expense->recurring_expense_id)<span class="ml-1 text-[11px] text-slate-500" title="Repeating expense">↻</span>@endif
                            </button>
                            @if($expense->reference)<span class="block text-xs text-slate-500">Ref {{ $expense->reference }}</span>@endif
                        </td>
                        <td class="whitespace-nowrap"><span class="ex-dot" style="background:{{ $dot($expense->category) }}"></span> {{ optional($expense->category)->name ?? 'Uncategorised' }}</td>
                        <td class="whitespace-nowrap text-slate-600">{{ \App\Models\Expense::PAYMENT_METHODS[$expense->payment_method] ?? '—' }}</td>
                        <td>@if($expense->receipt_path)<a href="{{ $expense->receipt_url }}" target="_blank" rel="noopener" title="View receipt" aria-label="View receipt for {{ $expense->description }}" x-on:click.stop class="text-base no-underline">📎</a>@endif</td>
                        <td class="ui-number whitespace-nowrap font-semibold">{{ $money($expense->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="ui-empty">
                        @if($filtered)
                            <h2>No expenses match these filters</h2><p class="ui-muted">Try other dates or clear the filters.</p>
                            <x-ui.button wire:click="resetFilters">Reset filters</x-ui.button>
                        @else
                            <h2>No expenses recorded in these dates</h2>
                            <p class="ui-muted">Record rent, salaries, utilities and other running costs so {{ $isOptical ? 'Profit & Loss' : 'the income statement' }} shows real profit. Set rent and salaries to repeat and they come due by themselves.</p>
                            @if($isOptical && $allCategories->isEmpty() && $isManager)<x-ui.button wire:click="addDefaultCategories">Add common categories</x-ui.button>@endif
                        @endif
                        <x-ui.button variant="primary" x-on:click="create()">Record expense</x-ui.button>
                    </div></td></tr>
                @endforelse
                </tbody>
                @if($expenses->isNotEmpty())<tfoot><tr><th colspan="5" scope="row" class="ui-number">{{ $expenses->hasPages() ? 'Page total' : 'Total' }}</th><td class="ui-number font-bold">{{ $money($expenses->sum('amount')) }}</td></tr></tfoot>@endif
            </table>
        </div>
        <div class="ui-panel-heading" style="padding:10px 16px">
            <p class="ui-muted">{{ $expenses->total() }} {{ Str::plural('expense', $expenses->total()) }}</p>
            <div class="ui-actions">
                @if($expenses->hasPages())
                    <x-ui.button wire:click="previousPage" :disabled="$expenses->onFirstPage()" aria-label="Previous page">Previous</x-ui.button>
                    <span class="ui-muted">Page {{ $expenses->currentPage() }} of {{ $expenses->lastPage() }}</span>
                    <x-ui.button wire:click="nextPage" :disabled="!$expenses->hasMorePages()" aria-label="Next page">Next</x-ui.button>
                @endif
                <x-ui.field label="Rows per page" name="perPage" :options="[15 => '15', 30 => '30', 50 => '50']" wire:model.live="perPage" />
            </div>
        </div>
    </x-ui.panel>

    {{-- Categories and repeating expenses, in a side panel opened in the browser. --}}
    @if($isManager)
        <div class="ex-overlay" x-show="cats" x-cloak x-on:click="cats = false" aria-hidden="true"></div>
        <aside class="ex-drawer" x-show="cats" x-cloak role="dialog" aria-modal="true" aria-labelledby="ex-categories-title" tabindex="-1" x-effect="cats && $nextTick(() => $el.focus())">
            <header>
                <div><h2 id="ex-categories-title">Categories &amp; repeating expenses</h2><p class="ui-muted text-xs">The {{ $isOptical ? 'Profit & Loss' : 'income statement' }} section each category reports under, and bills that repeat.</p></div>
                <button type="button" class="ex-x" x-on:click="cats = false" aria-label="Close">×</button>
            </header>
            <div class="ex-body space-y-5" id="expense-categories">
                <section>
                    <div class="mb-2 flex items-center justify-between gap-2"><h3 class="text-sm font-bold">Categories</h3>
                        <span class="flex gap-2">@if($isOptical && $allCategories->isEmpty())<x-ui.button wire:click="addDefaultCategories">Add common categories</x-ui.button>@endif<x-ui.button wire:click="openCreateCategory">New category</x-ui.button></span></div>
                    <ul class="m-0 list-none divide-y divide-slate-100 p-0 text-sm">
                        @forelse($allCategories as $cat)
                            <li wire:key="expense-category-{{ $cat->id }}" class="flex items-center gap-2 py-2 {{ $cat->is_active ? '' : 'opacity-60' }}">
                                <span class="ex-dot" style="background:{{ $cat->color ?: '#94a3b8' }}"></span>
                                <span class="min-w-0 flex-1"><b class="font-semibold">{{ $cat->name }}</b><span class="block text-xs text-slate-500">{{ $sectionLabels[$cat->section ?? 'operating_expense'] ?? $cat->section }}@if($cat->description) · {{ $cat->description }}@endif</span></span>
                                <x-ui.button wire:click="toggleCategoryActive({{ $cat->id }})" aria-label="Toggle active status for {{ $cat->name }}" aria-pressed="{{ $cat->is_active ? 'true' : 'false' }}">{{ $cat->is_active ? 'Active' : 'Inactive' }}</x-ui.button>
                                <x-ui.button wire:click="openEditCategory({{ $cat->id }})" aria-label="Edit category {{ $cat->name }}">Edit</x-ui.button>
                            </li>
                        @empty
                            <li class="py-2 text-slate-500">No categories yet.</li>
                        @endforelse
                    </ul>
                </section>
                <section>
                    <h3 class="mb-1 text-sm font-bold">Repeating expenses</h3>
                    <p class="mb-2 text-xs text-slate-500">To start one, record an expense and tick "Repeat this expense". Each comes due on its date for someone to record or skip.</p>
                    <ul class="m-0 list-none divide-y divide-slate-100 p-0 text-sm">
                        @forelse($recurring as $item)
                            <li wire:key="recurring-{{ $item->id }}" class="flex items-center gap-2 py-2">
                                <span class="min-w-0 flex-1"><b class="font-semibold">{{ $item->description }}</b> <span class="tabular-nums">{{ $money($item->amount) }}</span><span class="block text-xs text-slate-500">{{ \App\Models\RecurringExpense::FREQUENCIES[$item->frequency] ?? $item->frequency }} · next due {{ $item->next_due_date->format('d M Y') }}@if($item->payee) · {{ $item->payee }}@endif</span></span>
                                <x-ui.button wire:click="stopRecurring({{ $item->id }})" wire:confirm="Stop repeating {{ $item->description }}? Expenses already recorded are kept.">Stop</x-ui.button>
                            </li>
                        @empty
                            <li class="py-2 text-slate-500">None yet.</li>
                        @endforelse
                    </ul>
                </section>
            </div>
        </aside>
    @endif

    @if($showCategoryModal)
    <x-ui.modal :title="$isEditingCategory ? 'Edit category' : 'New category'" state="showCategoryModal">
        <form wire:submit="saveCategory" class="ui-form">
            <x-ui.field label="Category name (required)" name="categoryState.name" wire:model="categoryState.name" required maxlength="100" />
            <x-ui.field label="Color" name="categoryState.color" type="color" wire:model="categoryState.color" />
            <x-ui.field label="Income statement section (required)" name="categoryState.section" :options="$sectionLabels" wire:model="categoryState.section" required />
            <x-ui.field label="Description" name="categoryState.description" wire:model="categoryState.description" maxlength="255" />
            <label class="ui-check"><input type="checkbox" wire:model="categoryState.is_active"> Active</label>
            <div class="ui-actions">
                <x-ui.button wire:click="$set('showCategoryModal', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveCategory">{{ $isEditingCategory ? 'Update category' : 'Create category' }}</x-ui.button>
                <span wire:loading wire:target="saveCategory" role="status">Saving…</span>
            </div>
        </form>
    </x-ui.modal>
    @endif

    {{-- Record or edit an expense, in a side panel. It opens and closes in the browser; only Save calls the server. --}}
    <div class="ex-overlay" x-show="open" x-cloak x-on:click="close()" aria-hidden="true"></div>
    <aside class="ex-drawer" x-show="open" x-cloak role="dialog" aria-modal="true" aria-labelledby="ex-form-title" :class="{ 'ex-fresh': fresh }">
        <form x-on:submit.prevent="save()" class="contents">
            <header>
                <div>
                    <h2 id="ex-form-title" x-text="id ? 'Edit expense' : 'Record expense'">Record expense</h2>
                    <p x-show="recurringId" x-cloak class="text-xs text-slate-500">Repeating expense due <span x-text="form.expense_date"></span>. Change the amount if this bill differs.</p>
                </div>
                <button type="button" class="ex-x" x-on:click="close()" aria-label="Close">×</button>
            </header>
            <div class="ex-body ui-form">
                <div class="ui-grid">
                    <x-ui.field :label="'Amount ('.currency().') (required)'" name="state.amount" type="number" min="0.01" step="0.01" x-model="form.amount" x-ref="amount" required />
                    <x-ui.field label="Date (required)" name="state.expense_date" type="date" x-model="form.expense_date" required />
                </div>
                <x-ui.field label="Paid to" name="state.payee" x-model="form.payee" maxlength="150" list="ex-payees" placeholder="e.g. landlord, ECG, supplier" autocomplete="off" />
                <datalist id="ex-payees">@foreach($payees as $payee)<option value="{{ $payee }}">@endforeach</datalist>
                <x-ui.field label="Category" name="state.expense_category_id" :options="['' => 'Uncategorised'] + $categories->mapWithKeys(fn ($category) => [(string) $category->id => $category->name])->all()" x-model="form.expense_category_id" />
                <x-ui.field label="Description (required)" name="state.description" x-model="form.description" required maxlength="255" placeholder="e.g. Monthly rent payment" />
                <fieldset class="ui-field">
                    <legend class="mb-1 text-sm font-semibold">Paid with</legend>
                    <div class="ex-pay">
                        @foreach(\App\Models\Expense::PAYMENT_METHODS as $key => $label)
                            <label><input type="radio" name="ex-payment-method" value="{{ $key }}" x-model="form.payment_method"> {{ $label }}</label>
                        @endforeach
                    </div>
                    @if($isOptical)<p class="mt-1 text-xs text-slate-500">Cash from till is taken off the cash expected in End of day takings.</p>@endif
                    @error('state.payment_method')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                </fieldset>
                <x-ui.field label="Receipt / invoice number" name="state.reference" x-model="form.reference" maxlength="100" />
                <div class="ui-actions" x-show="receiptUrl" x-cloak>
                    <a :href="receiptUrl" target="_blank" rel="noopener" class="ui-button ui-button-secondary">View current receipt</a>
                    <button type="button" class="ui-button ui-button-danger" x-on:click="removeReceipt($el)" data-confirm-title="Remove this receipt?" data-confirm-button="Remove" data-confirm-danger="true">Remove receipt</button>
                </div>
                {{-- The one other call: the photo uploads when chosen. --}}
                <x-ui.field label="Receipt / photo (JPG, PNG, PDF · maximum 5 MB)" name="receiptFile" type="file" wire:model="receiptFile" x-ref="file" x-on:change="hasFile = $event.target.files.length > 0" accept=".jpg,.jpeg,.png,.pdf" />
                <p class="ui-muted" wire:loading wire:target="receiptFile" role="status">Uploading receipt…</p>
                <x-ui.field label="Notes" name="state.notes" x-model="form.notes" maxlength="1000" />
                <div class="rounded-lg border border-slate-200 p-3" x-show="! id && ! recurringId">
                    <label class="ui-check"><input type="checkbox" x-model="form.repeat"> Repeat this expense (rent, salaries, utilities)</label>
                    <div x-show="form.repeat" x-cloak class="mt-2">
                        <x-ui.field label="How often" name="state.frequency" :options="\App\Models\RecurringExpense::FREQUENCIES" x-model="form.frequency" />
                        <p class="text-xs text-slate-500">It comes due on this schedule at the top of this page, ready to record in one step.</p>
                    </div>
                </div>
            </div>
            <footer>
                <button type="submit" class="ui-button ui-button-primary" :disabled="saving" wire:loading.attr="disabled" wire:target="receiptFile" x-text="saving ? 'Saving…' : (id ? 'Update expense' : 'Record expense')">Record expense</button>
                <button type="button" class="ui-button ui-button-secondary" x-on:click="close()">Cancel</button>
                @if($isManager)
                    <span class="flex-1"></span>
                    <button type="button" x-show="id" class="ui-button ui-button-danger" x-on:click="remove($el)" data-confirm-title="Delete this expense?" data-confirm-button="Delete" data-confirm-danger="true">Delete</button>
                @endif
            </footer>
        </form>
    </aside>
</div>
