<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\Expense;
use App\Services\LicenseService;
use App\Support\Feature;
use App\Models\ExpenseCategory;
use App\Models\RecurringExpense;
use App\Services\ClinicAccessService;
use App\Services\Finance\PeriodLockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Expenses for one business line. The clinic and optical routes share this screen;
 * each only ever reads and writes its own line's expenses.
 */
class ExpensesComponent extends Component
{
    use WithPagination, WithFileUploads;

    protected $paginationTheme = 'tailwind';

    public const DEFAULT_OPTICAL_CATEGORIES = ['Rent', 'Salaries & wages', 'Utilities', 'Lab & glazing charges', 'Marketing', 'Repairs & maintenance', 'Transport & delivery', 'Bank & MoMo charges', 'Other'];

    #[Locked]
    public string $businessLine = Expense::CLINIC;

    // Filters
    public string $fromDate    = '';
    public string $toDate      = '';
    public string $categoryId  = '';
    public string $search      = '';
    public string $receipt     = '';
    public int    $perPage     = 15;

    // Form state
    public bool    $showModal  = false;
    public bool    $isEditing  = false;
    public ?int    $expenseId  = null;
    public         $receiptFile = null;
    /** The repeating expense being recorded, if the form was opened from one that is due. */
    #[Locked]
    public ?int    $recurringId = null;

    // Category management
    public bool    $showCategoryModal   = false;
    public bool    $isEditingCategory   = false;
    public ?int    $categoryEditId      = null;

    public array $categoryState = [
        'name'        => '',
        'section'     => 'operating_expense',
        'color'       => '#6c757d',
        'description' => '',
        'is_active'   => true,
    ];

    public array $state = [
        'expense_category_id' => '',
        'expense_date'        => '',
        'description'         => '',
        'payee'               => '',
        'amount'              => '',
        'payment_method'      => '',
        'reference'           => '',
        'notes'               => '',
        'repeat'              => false,
        'frequency'           => 'monthly',
    ];

    protected $queryString = [
        'fromDate'   => ['except' => ''],
        'toDate'     => ['except' => ''],
        'categoryId' => ['except' => ''],
        'search'     => ['except' => ''],
        'receipt'    => ['except' => ''],
    ];

    public function mount(?string $businessLine = null): void
    {
        $this->businessLine = $businessLine ?? (request()->routeIs('optical.*') ? Expense::OPTICAL : Expense::CLINIC);
        abort_unless(in_array($this->businessLine, [Expense::CLINIC, Expense::OPTICAL], true), 404);

        if ($this->isOptical()) {
            abort_unless(app(ClinicAccessService::class)->access('optical')['allowed'], 403);
            abort_if(!auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        } else {
            // Fix #12: generic 403 — don't leak feature flag names to unauthenticated/low-role users
            abort_if(!LicenseService::has(Feature::EXPENSE_TRACKING), 403);
            abort_if(!auth()->user()?->hasAnyRole(['Manager', 'Super Admin', 'Cashier']), 403);
        }

        $this->fromDate = $this->validDate($this->fromDate) ?? Carbon::now()->startOfMonth()->toDateString();
        $this->toDate   = $this->validDate($this->toDate) ?? Carbon::now()->toDateString();
        $this->state['expense_date'] = today()->toDateString();
    }

    private function isOptical(): bool
    {
        return $this->businessLine === Expense::OPTICAL;
    }

    /** Expenses of this screen's business line only. */
    private function lineExpenses()
    {
        return Expense::businessLine($this->businessLine);
    }

    /** Optical writes also need the optical module to be writable (clinic writes are checked by middleware). */
    private function assertWritable(): void
    {
        if ($this->isOptical()) app(ClinicAccessService::class)->assertWritable('optical');
    }

    /** Both statements read expenses directly, so a closed period freezes that line's expenses. */
    private function lockedPeriodMessage(?string ...$dates): ?string
    {
        foreach (array_filter($dates) as $date) {
            if ($lock = app(PeriodLockService::class)->covering($this->businessLine, Carbon::parse($date)->toDateString())) {
                return 'The period ' . $lock->from_date->format('d M Y') . ' – ' . $lock->to_date->format('d M Y') . ' is locked. Unlock it on the '
                    . ($this->isOptical() ? 'Profit & Loss' : 'Income Statement') . ' page first.';
            }
        }
        return null;
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : null;
    }

    public function updatedFromDate(): void   { $this->resetPage(); }
    public function updatedToDate(): void     { $this->resetPage(); }
    public function updatedCategoryId(): void { $this->resetPage(); }
    public function updatedSearch(): void     { $this->resetPage(); }
    public function updatedReceipt(): void    { $this->resetPage(); }
    public function updatedPerPage(): void    { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->fromDate   = Carbon::now()->startOfMonth()->toDateString();
        $this->toDate     = Carbon::now()->toDateString();
        $this->categoryId = '';
        $this->search     = '';
        $this->receipt    = '';
        $this->resetPage();
    }

    // ── Repeating expenses ───────────────────────────────────────────────

    private function lineRecurring()
    {
        return RecurringExpense::businessLine($this->businessLine);
    }

    /** Open the form filled in from a repeating expense that is due; the amount can be changed before saving. */
    public function recordRecurring(int $id): void
    {
        $recurring = $this->lineRecurring()->where('is_active', true)->findOrFail($id);
        $this->resetForm();
        $this->recurringId = $recurring->id;
        $this->state = array_merge($this->state, [
            'expense_category_id' => $recurring->expense_category_id ?? '',
            'expense_date'        => $recurring->next_due_date->toDateString(),
            'description'         => $recurring->description,
            'payee'               => $recurring->payee ?? '',
            'amount'              => $recurring->amount,
            'payment_method'      => $recurring->payment_method ?? '',
        ]);
        $this->showModal = true;
    }

    /** Not paid this time (or paid elsewhere): move on to the next due date. */
    public function skipRecurring(int $id): void
    {
        abort_if(!auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        $this->assertWritable();
        $recurring = $this->lineRecurring()->where('is_active', true)->findOrFail($id);
        $skipped = $recurring->next_due_date->format('d M Y');
        $recurring->advance();
        AuditTrail::record('expense.recurring_skipped', "Skipped {$recurring->description} due {$skipped}", $recurring, force: true);
        $this->dispatch('notify', ...['type' => 'success', 'message' => "Skipped. {$recurring->description} is next due {$recurring->next_due_date->format('d M Y')}."]);
    }

    public function stopRecurring(int $id): void
    {
        abort_if(!auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        $this->assertWritable();
        $recurring = $this->lineRecurring()->findOrFail($id);
        $recurring->update(['is_active' => false]);
        AuditTrail::record('expense.recurring_stopped', "Stopped repeating {$recurring->description}", $recurring, force: true);
        $this->dispatch('notify', ...['type' => 'success', 'message' => "{$recurring->description} will no longer repeat. Expenses already recorded are kept."]);
    }

    // openCreate, openEdit and recordRecurring fill the form on the server, for callers that
    // drive it from PHP; the page itself fills the form in the browser and calls saveExpense.
    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $expense = $this->lineExpenses()->findOrFail($id);

        $this->expenseId = $id;
        $this->isEditing = true;
        $this->state = [
            'expense_category_id' => $expense->expense_category_id ?? null,
            'expense_date'        => $expense->expense_date->format('Y-m-d'),
            'description'         => $expense->description,
            'payee'               => $expense->payee ?? '',
            'amount'              => $expense->amount,
            'payment_method'      => $expense->payment_method ?? '',
            'reference'           => $expense->reference ?? '',
            'notes'               => $expense->notes ?? '',
            'repeat'              => false,
            'frequency'           => 'monthly',
        ];
        $this->showModal = true;
    }

    /**
     * The one server call the expense form makes: the page opens, fills and closes the form
     * in the browser (resources/js/expense-form.js) and sends it here to be checked and saved.
     * Returns whether it saved, so the browser knows to close the form.
     */
    public function saveExpense(array $form, ?int $id = null, ?int $recurringId = null, bool $hasFile = false): bool
    {
        $this->resetValidation();
        // A file picked and then abandoned in an earlier form is not attached to this one.
        if (! $hasFile) $this->receiptFile = null;
        $this->isEditing = $id !== null;
        $this->expenseId = $id;
        $this->recurringId = $id === null ? $recurringId : null;
        $this->state = array_merge($this->state, array_intersect_key($form, $this->state));
        $this->state['repeat'] = filter_var($this->state['repeat'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->save();

        return ! $this->getErrorBag()->any();
    }

    public function save(): void
    {
        $this->assertWritable();
        $data = $this->validateForm();
        $repeat = ! $this->isEditing && ! $this->recurringId && ! empty($data['repeat']) ? $data['frequency'] : null;
        unset($data['repeat'], $data['frequency']);
        $data['recorded_by'] = auth()->id();
        $existing = $this->isEditing ? $this->lineExpenses()->findOrFail($this->expenseId) : null;
        if ($message = $this->lockedPeriodMessage($data['expense_date'], $existing?->expense_date?->toDateString())) {
            $this->addError('state.expense_date', $message);
            return;
        }

        if ($this->isEditing) {
            $expense = $existing;
            if ($this->receiptFile) {
                if ($expense->receipt_path) {
                    Storage::disk('public')->delete($expense->receipt_path);
                }
                $data['receipt_path'] = $this->receiptFile->store(\App\Support\Tenancy\TenantStorage::branch('expense-receipts'), 'public');
            }
            $old = $expense->only(array_keys($data));
            $expense->update($data);
            AuditTrail::record('expense.updated', "Updated expense: {$expense->description} (" . currency() . " {$expense->amount})", $expense, $old, $data);
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Expense updated.']);
        } else {
            if ($this->receiptFile) {
                $data['receipt_path'] = $this->receiptFile->store(\App\Support\Tenancy\TenantStorage::branch('expense-receipts'), 'public');
            }
            $message = 'Expense recorded.';
            $expense = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $repeat, &$message) {
                $recurring = $this->recurringId ? $this->lineRecurring()->where('is_active', true)->lockForUpdate()->findOrFail($this->recurringId) : null;
                if ($repeat) {
                    $recurring = RecurringExpense::create([
                        'business_line' => $this->businessLine, 'expense_category_id' => $data['expense_category_id'],
                        'description' => $data['description'], 'payee' => $data['payee'], 'amount' => $data['amount'],
                        'payment_method' => $data['payment_method'], 'frequency' => $repeat, 'created_by' => auth()->id(),
                        'next_due_date' => RecurringExpense::after(Carbon::parse($data['expense_date']), $repeat),
                    ]);
                    $message = 'Expense recorded. It repeats ' . strtolower(RecurringExpense::FREQUENCIES[$repeat]) . ', next due ' . $recurring->next_due_date->format('d M Y') . '.';
                } elseif ($recurring) {
                    $recurring->advance();
                    $message = "Expense recorded. {$recurring->description} is next due {$recurring->next_due_date->format('d M Y')}.";
                }
                return Expense::create($data + ['business_line' => $this->businessLine, 'recurring_expense_id' => $recurring?->id]);
            });
            AuditTrail::record('expense.created', "Recorded expense: {$expense->description} (" . currency() . " {$expense->amount})", $expense, [], $data);
            $this->dispatch('notify', ...['type' => 'success', 'message' => $message]);
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function deleteReceipt(int $id): void
    {
        $this->assertWritable();
        $expense = $this->lineExpenses()->findOrFail($id);
        if ($expense->receipt_path) {
            Storage::disk('public')->delete($expense->receipt_path);
        }
        $expense->update(['receipt_path' => null]);
        AuditTrail::record('expense.receipt_deleted', "Removed receipt from: {$expense->description}", $expense, force: true);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Receipt removed.']);
    }

    public function confirmDelete(int $id): void
    {
        abort_if(!auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);

        $this->dispatch('show-delete-confirmation', ...[
            'id'     => $id,
            'method' => 'deleteExpense',
        ]);
    }

    public function deleteExpense(int $id): void
    {
        abort_if(!auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        $this->assertWritable();

        $expense = $this->lineExpenses()->findOrFail($id);
        if ($message = $this->lockedPeriodMessage($expense->expense_date->toDateString())) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => $message]);
            return;
        }
        AuditTrail::record('expense.deleted', "Deleted expense: {$expense->description} (" . currency() . " {$expense->amount})", $expense, $expense->toArray(), []);
        $expense->delete();
        if ($this->expenseId === $id) {
            $this->showModal = false;
            $this->resetForm();
        }
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Expense deleted.']);
    }

    public function exportCsv()
    {
        [$from, $to] = $this->dateRange();

        $fileName = ($this->isOptical() ? 'Optical_' : '') . 'Expenses_' . $from->toDateString() . '_to_' . $to->toDateString() . '.csv';

        $expenses = $this->buildQuery()
            ->with('category', 'recorder')
            ->get();

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        // Fix #2: sanitize values against CSV formula injection
        $sanitize = static function ($v): string {
            $v = (string) $v;
            return ($v !== '' && preg_match('/^[=+\-@\t\r]/', $v)) ? "'" . $v : $v;
        };

        $callback = function () use ($expenses, $sanitize) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, ['#', 'Date', 'Category', 'Paid to', 'Description', 'Amount', 'Paid with', 'Reference', 'Notes', 'Recorded By']);

            foreach ($expenses as $expense) {
                fputcsv($file, array_map($sanitize, [
                    $expense->id,
                    $expense->expense_date->format('Y-m-d'),
                    optional($expense->category)->name ?? '—',
                    $expense->payee ?? '',
                    $expense->description,
                    number_format($expense->amount, 2),
                    Expense::PAYMENT_METHODS[$expense->payment_method] ?? '',
                    $expense->reference ?? '',
                    $expense->notes ?? '',
                    optional($expense->recorder)->name ?? '—',
                ]));
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ── Category management ──────────────────────────────────────────────

    public function openCreateCategory(): void
    {
        abort_if(!auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        $this->resetCategoryForm();
        $this->showCategoryModal = true;
    }

    public function openEditCategory(int $id): void
    {
        abort_if(!auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        $cat = ExpenseCategory::findOrFail($id);
        $this->categoryEditId      = $id;
        $this->isEditingCategory   = true;
        $this->categoryState = [
            'name'        => $cat->name,
            'section'     => $cat->section ?? ExpenseCategory::OPERATING,
            'color'       => $cat->color ?? '#6c757d',
            'description' => $cat->description ?? '',
            'is_active'   => (bool) $cat->is_active,
        ];
        $this->showCategoryModal = true;
    }

    public function saveCategory(): void
    {
        abort_if(!auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        $this->assertWritable();

        $clinicId = ExpenseCategory::clinicIdForWrite();
        $data = $this->validate([
            'categoryState.name'        => ['required', 'string', 'max:100',
                Rule::unique('expense_categories', 'name')->where('clinic_id', $clinicId)->ignore($this->categoryEditId)],
            'categoryState.section'     => 'required|in:operating_expense,non_operating_expense',
            'categoryState.color'       => 'required|string|max:20',
            'categoryState.description' => 'nullable|string|max:255',
            'categoryState.is_active'   => 'boolean',
        ], [], [
            'categoryState.name'    => 'category name',
            'categoryState.section' => 'section',
            'categoryState.color'   => 'color',
        ])['categoryState'];

        if ($this->isEditingCategory) {
            ExpenseCategory::findOrFail($this->categoryEditId)->update($data);
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Category updated.']);
        } else {
            ExpenseCategory::create($data);
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Category created.']);
        }

        $this->showCategoryModal = false;
        $this->resetCategoryForm();
    }

    public function toggleCategoryActive(int $id): void
    {
        abort_if(!auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        $this->assertWritable();
        $cat = ExpenseCategory::findOrFail($id);
        $cat->update(['is_active' => !$cat->is_active]);
    }

    /** One-click starter categories for a new optical shop. */
    public function addDefaultCategories(): void
    {
        abort_if(!auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        abort_unless($this->isOptical(), 404);
        $this->assertWritable();
        foreach (self::DEFAULT_OPTICAL_CATEGORIES as $name) {
            ExpenseCategory::firstOrCreate(['name' => $name], ['section' => ExpenseCategory::OPERATING, 'is_active' => true]);
        }
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Common categories added.']);
    }

    private function resetCategoryForm(): void
    {
        $this->resetErrorBag();
        $this->categoryEditId    = null;
        $this->isEditingCategory = false;
        $this->categoryState = [
            'name'        => '',
            'section'     => 'operating_expense',
            'color'       => '#6c757d',
            'description' => '',
            'is_active'   => true,
        ];
    }

    // ── Expense form helpers ─────────────────────────────────────────────

    private function dateRange(): array
    {
        $from = Carbon::parse($this->fromDate ?: now()->startOfMonth())->startOfDay();
        $to   = Carbon::parse($this->toDate   ?: now())->endOfDay();

        if ($from->gt($to)) {
            [$a, $b] = [$from->copy(), $to->copy()];
            $from = ($a->lt($b) ? $a : $b)->startOfDay();
            $to   = ($a->lt($b) ? $b : $a)->endOfDay();
        }

        return [$from, $to];
    }

    private function buildQuery(?Carbon $from = null, ?Carbon $to = null)
    {
        if (! $from || ! $to) [$from, $to] = $this->dateRange();

        return $this->lineExpenses()->with('category')
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->when($this->categoryId, fn ($q) => $q->where('expense_category_id', $this->categoryId))
            ->when($this->receipt === 'missing', fn ($q) => $q->whereNull('receipt_path'))
            ->when($this->search, fn ($q) => $q->where(function ($inner) {
                $inner->where('description', 'like', '%' . $this->search . '%')
                      ->orWhere('payee', 'like', '%' . $this->search . '%')
                      ->orWhere('reference', 'like', '%' . $this->search . '%');
            }))
            ->orderByDesc('expense_date')
            ->orderByDesc('id');
    }

    private function validateForm(): array
    {
        $clinicId = ExpenseCategory::clinicIdForWrite();
        $data = $this->validate([
            'state.expense_category_id' => ['nullable', Rule::exists('expense_categories', 'id')->where('clinic_id', $clinicId)],
            'state.expense_date'        => 'required|date',
            'state.description'         => 'required|string|max:255',
            'state.payee'               => 'nullable|string|max:150',
            'state.amount'              => 'required|numeric|min:0.01',
            'state.payment_method'      => ['nullable', Rule::in(array_keys(Expense::PAYMENT_METHODS))],
            'state.reference'           => 'nullable|string|max:100',
            'state.notes'               => 'nullable|string|max:1000',
            'state.repeat'              => 'boolean',
            'state.frequency'           => ['required_if:state.repeat,true', Rule::in(array_keys(RecurringExpense::FREQUENCIES))],
            'receiptFile'               => 'nullable|file|max:5120|mimes:jpg,jpeg,png,pdf',
        ], [], [
            'state.expense_category_id' => 'category',
            'state.expense_date'        => 'date',
            'state.description'         => 'description',
            'state.payee'               => 'paid to',
            'state.amount'              => 'amount',
            'state.payment_method'      => 'paid with',
            'state.reference'           => 'reference',
            'state.frequency'           => 'how often',
        ])['state'];

        foreach (['expense_category_id', 'payee', 'payment_method'] as $optional) {
            if (trim((string) ($data[$optional] ?? '')) === '') $data[$optional] = null;
        }
        if ($data['payee'] !== null) $data['payee'] = trim($data['payee']);

        return $data;
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->expenseId   = null;
        $this->isEditing   = false;
        $this->receiptFile = null;
        $this->recurringId = null;
        $this->state = [
            'expense_category_id' => '',
            'expense_date'        => today()->toDateString(),
            'description'         => '',
            'payee'               => '',
            'amount'              => '',
            'payment_method'      => '',
            'reference'           => '',
            'notes'               => '',
            'repeat'              => false,
            'frequency'           => 'monthly',
        ];
    }

    public function render()
    {
        [$from, $to] = $this->dateRange();

        $expenses       = $this->buildQuery()->with('recorder')->paginate($this->perPage);
        // Most-used categories first, so the usual choice is at the top of the list.
        $categories     = ExpenseCategory::where('is_active', true)
            ->withCount(['expenses as recent_uses' => fn ($q) => $q->where('business_line', $this->businessLine)->where('expense_date', '>=', today()->subDays(180))])
            ->orderByDesc('recent_uses')->orderBy('name')->get();
        $allCategories  = ExpenseCategory::orderBy('name')->get();
        $sectionLabels  = ExpenseCategory::sectionLabels();

        $totalInRange = (float) $this->buildQuery()->sum('amount');
        // The same number of days just before, with the same filters.
        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $previousTotal = (float) $this->buildQuery($from->copy()->subDays($days), $from->copy()->subDay())->sum('amount');
        $previousLabel = $from->copy()->subDays($days)->format('d M') . ' – ' . $from->copy()->subDay()->format('d M');
        $missingReceipts = $this->lineExpenses()->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])->whereNull('receipt_path')->count();

        // Every category's share of spending in these dates (other filters aside), for the split bar.
        $categoryTotals = $this->lineExpenses()->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('expense_category_id, SUM(amount) as total')
            ->groupBy('expense_category_id')
            ->with('category')
            ->orderByDesc('total')
            ->get();
        $recurring = $this->lineRecurring()->with('category')->where('is_active', true)->orderBy('next_due_date')->get();
        $due = $recurring->filter(fn ($item) => $item->next_due_date->lte(today()->addDays(7)));
        $payees = $this->lineExpenses()->whereNotNull('payee')->where('expense_date', '>=', today()->subYear())
            ->distinct()->orderBy('payee')->limit(100)->pluck('payee');

        // Where this line's expenses are reported: the optical P&L or the clinic income statement.
        $statementLink = $this->isOptical()
            ? ['label' => 'Profit & Loss', 'url' => route('optical.profit', ['from' => $from->toDateString(), 'to' => $to->toDateString()])]
            : (LicenseService::has(Feature::ADVANCED_REPORTS) && (auth()->user()?->hasRole('Super Admin') || auth()->user()?->can('manage billing'))
                ? ['label' => 'Income statement', 'url' => route('admin.income-statement', ['fromDate' => $this->fromDate, 'toDate' => $this->toDate])]
                : null);
        $isOptical = $this->isOptical();

        return view('livewire.admin.expenses-component', compact(
            'expenses', 'categories', 'allCategories', 'sectionLabels',
            'totalInRange', 'previousTotal', 'previousLabel', 'missingReceipts', 'categoryTotals',
            'recurring', 'due', 'payees', 'statementLink', 'isOptical'
        ))->layout($isOptical ? 'layouts.optical' : 'layouts.admin.admin-layout');
    }
}
