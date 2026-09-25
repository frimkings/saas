<div class="clinic-ui ui-page">
    <header class="ui-heading">
        @if($isOptical)
            <div><p class="ui-muted">Optical workspace / Finance</p><h1>Optical Expenses</h1><p class="ui-muted">Shop running costs, receipts, and categories. They feed the optical Profit &amp; Loss and are kept separate from clinic expenses.</p></div>
        @else
            <div><p class="ui-muted">Clinic workspace / Finance</p><h1>Expenses</h1><p class="ui-muted">Track clinic spending, receipts, and categories.</p></div>
        @endif
        <div class="ui-actions">
            @if($statementLink)<x-ui.button :href="$statementLink['url']">{{ $statementLink['label'] }}</x-ui.button>@endif
            @if($isOptical && $allCategories->isEmpty())<x-ui.button wire:click="addDefaultCategories">Add common categories</x-ui.button>@endif
            <x-ui.button wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv">Export CSV</x-ui.button>
            <x-ui.button variant="primary" wire:click="openCreate">Record expense</x-ui.button>
        </div>
    </header>
    <div class="ui-stats">
        <x-ui.stat label="Filtered period total" :value="currency().' '.number_format($totalInRange, 2)" />
        <x-ui.stat label="Today · all expenses" :value="currency().' '.number_format($todayTotal, 2)" />
        <x-ui.panel class="ui-stat">
            <p class="ui-muted">Top categories · selected dates</p>
            <div class="ui-actions">
                @forelse($topCategories as $tc)
                    <x-ui.badge>{{ optional($tc->category)->name ?? 'Uncategorised' }}: {{ currency() }} {{ number_format($tc->total, 2) }}</x-ui.badge>
                @empty <p class="ui-muted">No category totals for this period.</p> @endforelse
            </div>
        </x-ui.panel>
    </div>
    <div class="ui-stack">
        <x-ui.panel>
            <div class="ui-form">
                <h2>Expense records</h2>
                <div class="ui-grid">
                    <x-date-range from="fromDate" to="toDate" presets="finance" label="Dates" />
                    <x-ui.field label="Category" name="categoryId" :options="['' => 'All categories'] + $categories->pluck('name', 'id')->all()" wire:model.live="categoryId" />
                    <x-ui.field label="Search description or reference" name="search" wire:model.live.debounce.300ms="search" placeholder="Search expenses" />
                </div>
                <x-ui.button wire:click="resetFilters">Reset filters</x-ui.button>
                <span class="ui-muted" wire:loading role="status">Updating expenses…</span>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <caption class="sr-only">Expenses matching the selected dates and filters</caption>
                    <thead><tr><th scope="col">Date</th><th scope="col">Category</th><th scope="col">Description</th><th scope="col">Reference</th><th scope="col" class="ui-number">Amount</th><th scope="col">Recorded by</th><th scope="col">Actions</th></tr></thead>
                    <tbody wire:loading.class="opacity-50">
                    @forelse($expenses as $expense)
                        <tr wire:key="expense-{{ $expense->id }}">
                            <td>{{ $expense->expense_date->format('d M Y') }}</td>
                            <td><x-ui.badge>{{ optional($expense->category)->name ?? 'Uncategorised' }}</x-ui.badge></td>
                            <td>{{ $expense->description }}@if($expense->notes)<p class="ui-muted">{{ Str::limit($expense->notes, 60) }}</p>@endif</td>
                            <td>{{ $expense->reference ?: '—' }}</td>
                            <td class="ui-number">{{ currency() }} {{ number_format($expense->amount, 2) }}</td>
                            <td>{{ optional($expense->recorder)->name ?? '—' }}<p class="ui-muted">{{ $expense->created_at->format('d M, h:i A') }}</p></td>
                            <td><div class="ui-actions">
                                @if($expense->receipt_path)<x-ui.button :href="$expense->receipt_url" target="_blank" rel="noopener">Receipt</x-ui.button>@endif
                                <x-ui.button wire:click="openEdit({{ $expense->id }})" aria-label="Edit expense {{ $expense->description }}">Edit</x-ui.button>
                                @if(auth()->user()->hasAnyRole(['Manager', 'Super Admin']))
                                @if($isOptical)
                                <x-ui.button variant="danger" wire:click="deleteExpense({{ $expense->id }})" wire:confirm="Delete this expense?" aria-label="Delete expense {{ $expense->description }}">Delete</x-ui.button>
                                @else
                                <x-ui.button variant="danger" wire:click="confirmDelete({{ $expense->id }})" aria-label="Delete expense {{ $expense->description }}">Delete</x-ui.button>
                                @endif
                                @endif
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="ui-empty">
                            <h2>No expenses match this period and filters</h2><p class="ui-muted">Adjust your filters or record a new expense.</p>
                            <x-ui.button wire:click="resetFilters">Reset filters</x-ui.button>
                            <x-ui.button variant="primary" wire:click="openCreate">Record expense</x-ui.button>
                        </div></td></tr>
                    @endforelse
                    </tbody>
                    @if($expenses->isNotEmpty())<tfoot><tr><th colspan="4" scope="row" class="ui-number">Page total</th><td class="ui-number">{{ currency() }} {{ number_format($expenses->sum('amount'), 2) }}</td><td colspan="2"></td></tr></tfoot>@endif
                </table>
            </div>
            <div class="ui-panel-heading">
                <p class="ui-muted">{{ $expenses->total() }} expenses</p>
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
        @if(auth()->user()->hasAnyRole(['Manager', 'Super Admin']))
        <x-ui.panel>
            <div class="ui-panel-heading">
                <div><h2>Expense categories</h2><p class="ui-muted">Manage categories and the {{ $isOptical ? 'Profit & Loss' : 'income statement' }} section each one reports under.</p></div>
                <x-ui.button wire:click="$toggle('showCategoryPanel')" aria-expanded="{{ $showCategoryPanel ? 'true' : 'false' }}" aria-controls="expense-categories">{{ $showCategoryPanel ? 'Hide categories' : 'Manage categories' }}</x-ui.button>
            </div>
            @if($showCategoryPanel)
            <div id="expense-categories">
                <div class="ui-panel-heading"><x-ui.button wire:click="openCreateCategory">New category</x-ui.button></div>
                <div class="ui-table-wrap"><table class="ui-table">
                    <thead><tr><th scope="col">Category</th><th scope="col">Statement section</th><th scope="col">Description</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                    <tbody>@forelse($allCategories as $cat)
                        <tr wire:key="expense-category-{{ $cat->id }}">
                            <td>{{ $cat->name }}</td><td>{{ $sectionLabels[$cat->section ?? 'operating_expense'] ?? $cat->section }}</td><td>{{ $cat->description ?: '—' }}</td>
                            <td><x-ui.button wire:click="toggleCategoryActive({{ $cat->id }})" aria-label="Toggle active status for {{ $cat->name }}" aria-pressed="{{ $cat->is_active ? 'true' : 'false' }}">{{ $cat->is_active ? 'Active' : 'Inactive' }}</x-ui.button></td>
                            <td><x-ui.button wire:click="openEditCategory({{ $cat->id }})" aria-label="Edit category {{ $cat->name }}">Edit</x-ui.button></td>
                        </tr>
                    @empty<tr><td colspan="5">No categories yet.</td></tr>@endforelse</tbody>
                </table></div>
            </div>
            @endif
        </x-ui.panel>
        @endif
    </div>
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
    @if($showModal)
    <x-ui.modal :title="$isEditing ? 'Edit expense' : 'Record expense'" state="showModal">
        <form wire:submit="save" class="ui-form">
            <div class="ui-grid">
                <x-ui.field label="Date (required)" name="state.expense_date" type="date" wire:model="state.expense_date" required />
                <x-ui.field :label="'Amount ('.currency().') (required)'" name="state.amount" type="number" min="0.01" step="0.01" wire:model="state.amount" required />
            </div>
            <x-ui.field label="Category" name="state.expense_category_id" :options="['' => 'Uncategorised'] + $categories->pluck('name', 'id')->all()" wire:model="state.expense_category_id" />
            <x-ui.field label="Description (required)" name="state.description" wire:model="state.description" required maxlength="255" placeholder="e.g. Monthly rent payment" />
            <x-ui.field label="Receipt / invoice number" name="state.reference" wire:model="state.reference" maxlength="100" />
            <x-ui.field label="Notes" name="state.notes" wire:model="state.notes" maxlength="1000" />
            @if($isEditing && $editingReceiptUrl)
                <div class="ui-actions">
                    <x-ui.button :href="$editingReceiptUrl" target="_blank" rel="noopener">View current receipt</x-ui.button>
                    <x-ui.button variant="danger" wire:click="deleteReceipt({{ $expenseId }})" wire:confirm="Remove this receipt?" wire:loading.attr="disabled">Remove receipt</x-ui.button>
                </div>
            @endif
            <x-ui.field label="Receipt / photo (JPG, PNG, PDF · maximum 5 MB)" name="receiptFile" type="file" wire:model.live="receiptFile" accept=".jpg,.jpeg,.png,.pdf" />
            <p class="ui-muted" wire:loading wire:target="receiptFile" role="status">Uploading receipt…</p>
            @if($receiptFile)<p class="ui-muted">File selected. Save the expense to attach it.</p>@endif
            <div class="ui-actions">
                <x-ui.button wire:click="$set('showModal', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save,receiptFile,deleteReceipt">{{ $isEditing ? 'Update expense' : 'Record expense' }}</x-ui.button>
                <span wire:loading wire:target="save" role="status">Saving…</span>
            </div>
        </form>
    </x-ui.modal>
    @endif
</div>
