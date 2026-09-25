<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Services\LicenseService;
use App\Support\Feature;
use App\Models\IncomeStatementEntry;
use App\Models\IncomeStatementTemplate;
use App\Models\Setting;
use App\Services\Finance\ClinicStatementService;
use App\Services\Finance\PeriodLockService;
use App\Support\BusinessLine;
use Carbon\Carbon;
use Livewire\Component;

class IncomeStatementComponent extends Component
{
    // The clinic's books; optical has its own Profit & Loss.
    private const LINE = BusinessLine::CLINIC;

    public $fromDate;
    public $toDate;

    public $section = IncomeStatementEntry::OPERATING_EXPENSE;
    public $name = '';
    public $amount = '';
    public $percentage = '';
    public $entryDate;
    public $notes = '';
    public $selectedPreset = '';
    public $customName = '';
    public $templateSection = IncomeStatementEntry::OPERATING_EXPENSE;
    public $templateName = '';
    public $templateAmount = '';
    public $templatePercentage = '';
    public $templateNotes = '';
    public $lockNotes = '';
    public $editingEntryId = null;
    public $editingName = '';
    public $editingAmount = '';
    public $editingPercentage = '';
    public $editingDate = '';
    public $editingNotes = '';

    protected $queryString = [
        'fromDate' => ['except' => ''],
        'toDate' => ['except' => ''],
    ];

    protected $entryPresets = [
        IncomeStatementEntry::OPERATING_EXPENSE => [
            "Doctor's Salary",
            'Nurse Salary',
            'Locum',
            'Media',
            'Electricity',
            'Internet',
            'Consumables',
            'Rent',
            'DVLA Printing',
            'Lenses Fixing',
        ],
        IncomeStatementEntry::NON_OPERATING_EXPENSE => [
            'Loans',
            'Loan Interest',
            'Bank Charges',
            'Asset Disposal Loss',
            'Other Non-operating Expense',
        ],
        IncomeStatementEntry::TAX => [
            'Tax',
            'Corporate Tax',
            'Income Tax',
        ],
    ];

    public function mount()
    {
        abort_if(!LicenseService::has(Feature::ADVANCED_REPORTS), 403, 'Advanced reporting requires a Pro license.');
        $user = auth()->user();
        abort_if(!$user?->hasRole('Super Admin') && !$user?->can('manage billing'), 403);
        AuditTrail::record('report.accessed', 'Accessed income statement page');
        $this->fromDate = $this->normalizeDate($this->fromDate, now()->startOfMonth());
        $this->toDate = $this->normalizeDate($this->toDate, now()->endOfMonth());
        $this->entryDate = now()->format('Y-m-d');
    }

    public function updatedFromDate()
    {
        $this->normalizeDates();
    }

    public function updatedToDate()
    {
        $this->normalizeDates();
    }

    public function updatedSection()
    {
        $this->selectedPreset = '';
        $this->name = '';
        $this->customName = '';
        $this->amount = '';
        $this->percentage = '';
    }

    public function updatedTemplateSection()
    {
        $this->templateAmount = '';
        $this->templatePercentage = '';
    }

    public function updatedSelectedPreset($value)
    {
        if ($value !== 'custom') {
            $this->name = $value;
        } else {
            $this->name = '';
        }
    }

    protected function normalizeDates()
    {
        $this->fromDate = $this->normalizeDate($this->fromDate, now()->startOfMonth());
        $this->toDate = $this->normalizeDate($this->toDate, now()->endOfMonth());
    }

    protected function normalizeDate($date, $fallback)
    {
        try {
            return Carbon::parse($date ?: $fallback)->format('Y-m-d');
        } catch (\Exception $e) {
            return Carbon::parse($fallback)->format('Y-m-d');
        }
    }

    protected function dateRange()
    {
        $from = Carbon::parse($this->fromDate)->startOfDay();
        $to = Carbon::parse($this->toDate)->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    public function setThisMonth()
    {
        $this->fromDate = now()->startOfMonth()->format('Y-m-d');
        $this->toDate = now()->endOfMonth()->format('Y-m-d');
    }

    public function setLastMonth()
    {
        $this->fromDate = now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d');
        $this->toDate = now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d');
    }

    public function saveEntry()
    {
        if ($this->isLocked) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'This period is locked. Unlock it before changing entries.']);
            return;
        }

        if (trim($this->customName) !== '') {
            $this->name = $this->customName;
        } elseif ($this->selectedPreset) {
            $this->name = $this->selectedPreset;
        }

        $rules = [
            'section' => 'required|in:operating_expense,non_operating_expense,tax',
            'name' => 'required|string|max:255',
            'entryDate' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ];

        if ($this->section === IncomeStatementEntry::TAX) {
            $rules['percentage'] = 'required|numeric|min:0|max:100';
        } else {
            $rules['amount'] = 'required|numeric|min:0';
        }

        $this->validate($rules);

        $entry = $this->matchingEntryQuery($this->section, $this->name)->latest('id')->first();
        $payload = [
            'section' => $this->section,
            'name' => $this->name,
            'amount' => $this->section === IncomeStatementEntry::TAX ? 0 : $this->amount,
            'percentage' => $this->section === IncomeStatementEntry::TAX ? $this->percentage : null,
            'entry_date' => $this->entryDate,
            'notes' => $this->notes,
            'is_active' => true,
        ];

        if ($entry) {
            $entry->update($payload);
        } else {
            IncomeStatementEntry::create($payload + ['created_by' => auth()->id(), 'business_line' => self::LINE]);
        }

        $this->removeDuplicateEntries($this->section, $this->name);
        $this->resetEntryForm();
        $this->dispatch('notify', ...['type' => 'success', 'message' => $entry ? 'Income statement line updated.' : 'Income statement line saved.']);
    }

    public function deleteEntry($entryId)
    {
        if ($this->isLocked) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'This period is locked. Unlock it before deleting entries.']);
            return;
        }

        $entry = IncomeStatementEntry::businessLine(self::LINE)->findOrFail($entryId);
        $entry->update(['deleted_by' => auth()->id()]);
        $entry->delete();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Income statement line deleted.']);
    }

    public function editEntry($entryId)
    {
        if ($this->isLocked) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'This period is locked. Unlock it before editing entries.']);
            return;
        }

        $entry = IncomeStatementEntry::businessLine(self::LINE)->findOrFail($entryId);
        $this->editingEntryId = $entry->id;
        $this->editingName = $entry->name;
        $this->editingAmount = $entry->amount;
        $this->editingPercentage = $entry->percentage;
        $this->editingDate = $entry->entry_date->format('Y-m-d');
        $this->editingNotes = $entry->notes;
    }

    public function cancelEdit()
    {
        $this->editingEntryId = null;
        $this->editingName = '';
        $this->editingAmount = '';
        $this->editingPercentage = '';
        $this->editingDate = '';
        $this->editingNotes = '';
        $this->resetErrorBag();
    }

    public function updateEntry()
    {
        if ($this->isLocked) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'This period is locked. Unlock it before editing entries.']);
            return;
        }

        $entry = IncomeStatementEntry::businessLine(self::LINE)->findOrFail($this->editingEntryId);
        $rules = [
            'editingName' => 'required|string|max:255',
            'editingDate' => 'required|date',
            'editingNotes' => 'nullable|string|max:1000',
        ];

        if ($entry->section === IncomeStatementEntry::TAX) {
            $rules['editingPercentage'] = 'required|numeric|min:0|max:100';
        } else {
            $rules['editingAmount'] = 'required|numeric|min:0';
        }

        $this->validate($rules);

        $entry->update([
            'name' => $this->editingName,
            'amount' => $entry->section === IncomeStatementEntry::TAX ? 0 : $this->editingAmount,
            'percentage' => $entry->section === IncomeStatementEntry::TAX ? $this->editingPercentage : null,
            'entry_date' => $this->editingDate,
            'notes' => $this->editingNotes,
        ]);

        $this->removeDuplicateEntries($entry->section, $this->editingName);
        $this->cancelEdit();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Income statement line updated.']);
    }

    public function saveTemplate()
    {
        $rules = [
            'templateSection' => 'required|in:operating_expense,non_operating_expense,tax',
            'templateName' => 'required|string|max:255',
            'templateNotes' => 'nullable|string|max:1000',
        ];

        if ($this->templateSection === IncomeStatementEntry::TAX) {
            $rules['templatePercentage'] = 'required|numeric|min:0|max:100';
        } else {
            $rules['templateAmount'] = 'required|numeric|min:0';
        }

        $this->validate($rules);

        IncomeStatementTemplate::create([
            'section' => $this->templateSection,
            'name' => $this->templateName,
            'amount' => $this->templateSection === IncomeStatementEntry::TAX ? 0 : $this->templateAmount,
            'percentage' => $this->templateSection === IncomeStatementEntry::TAX ? $this->templatePercentage : null,
            'notes' => $this->templateNotes,
            'is_active' => true,
            'created_by' => auth()->id(),
            'business_line' => self::LINE,
        ]);

        $this->templateName = '';
        $this->templateAmount = '';
        $this->templatePercentage = '';
        $this->templateNotes = '';
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Recurring template saved.']);
    }

    public function applyTemplates()
    {
        if ($this->isLocked) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'This period is locked. Unlock it before applying templates.']);
            return;
        }

        $created = 0;
        $updated = 0;

        foreach ($this->templates as $template) {
            $entry = $this->matchingEntryQuery($template->section, $template->name)->latest('id')->first();
            $payload = [
                'section' => $template->section,
                'name' => $template->name,
                'amount' => $template->amount,
                'percentage' => $template->percentage,
                'entry_date' => $this->fromDate,
                'notes' => $template->notes,
                'is_active' => true,
            ];

            if ($entry) {
                $entry->update($payload);
                $updated++;
            } else {
                IncomeStatementEntry::create($payload + ['created_by' => auth()->id(), 'business_line' => self::LINE]);
                $created++;
            }

            $this->removeDuplicateEntries($template->section, $template->name);
        }

        $this->dispatch('notify', ...['type' => 'success', 'message' => "{$created} recurring template line(s) created, {$updated} updated."]);
    }

    protected function matchingEntryQuery($section, $name)
    {
        [$from, $to] = $this->dateRange();

        return IncomeStatementEntry::businessLine(self::LINE)->where('is_active', true)
            ->where('section', $section)
            ->where('name', $name)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]);
    }

    protected function removeDuplicateEntries($section, $name)
    {
        $entries = $this->matchingEntryQuery($section, $name)
            ->latest('id')
            ->get();

        if ($entries->count() <= 1) {
            return;
        }

        $entries->skip(1)->each(function ($entry) {
            $entry->update(['deleted_by' => auth()->id()]);
            $entry->delete();
        });
    }

    public function deleteTemplate($templateId)
    {
        IncomeStatementTemplate::businessLine(self::LINE)->findOrFail($templateId)->update(['is_active' => false]);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Recurring template removed.']);
    }

    public function lockPeriod()
    {
        [$from, $to] = $this->dateRange();

        app(PeriodLockService::class)->lock(self::LINE, $from, $to, $this->lockNotes);

        $this->lockNotes = '';
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Income statement period locked.']);
    }

    public function unlockPeriod()
    {
        [$from, $to] = $this->dateRange();
        app(PeriodLockService::class)->unlock(self::LINE, $from, $to);

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Income statement period unlocked.']);
    }

    protected function resetEntryForm()
    {
        $this->section = IncomeStatementEntry::OPERATING_EXPENSE;
        $this->name = '';
        $this->selectedPreset = '';
        $this->customName = '';
        $this->amount = '';
        $this->percentage = '';
        $this->entryDate = now()->format('Y-m-d');
        $this->notes = '';
        $this->resetErrorBag();
    }

    public function getRevenueLinesProperty()
    {
        [$from, $to] = $this->dateRange();

        return app(ClinicStatementService::class)->revenueLines($from, $to);
    }

    public function getCostOfSalesLinesProperty()
    {
        [$from, $to] = $this->dateRange();

        return app(ClinicStatementService::class)->costLines($from, $to);
    }

    public function getEntriesProperty()
    {
        [$from, $to] = $this->dateRange();

        return app(ClinicStatementService::class)->entries($from, $to);
    }

    public function getTemplatesProperty()
    {
        return IncomeStatementTemplate::businessLine(self::LINE)->where('is_active', true)
            ->orderBy('section')
            ->orderBy('name')
            ->get();
    }

    public function getPeriodLockProperty()
    {
        [$from, $to] = $this->dateRange();

        return app(PeriodLockService::class)->find(self::LINE, $from, $to);
    }

    public function getIsLockedProperty()
    {
        return (bool) $this->periodLock;
    }

    public function getUncategorizedWarningsProperty()
    {
        $revenue = $this->revenueLines->firstWhere('name', 'Uncategorized');
        $cost = $this->costOfSalesLines->firstWhere('name', 'Uncategorized');

        return [
            'revenue' => $revenue ? (float) $revenue->amount : 0,
            'cost' => $cost ? (float) $cost->amount : 0,
        ];
    }

    protected function previousDateRange()
    {
        [$from, $to] = $this->dateRange();
        $days = $from->diffInDays($to) + 1;
        $previousTo = $from->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();

        return [$previousFrom, $previousTo];
    }

    public function getComparisonProperty()
    {
        [$previousFrom, $previousTo] = $this->previousDateRange();
        $previous = app(ClinicStatementService::class)->statement($previousFrom, $previousTo);

        return [
            'from' => $previousFrom,
            'to' => $previousTo,
            'statement' => $previous,
        ];
    }

    public function getStatementProperty()
    {
        [$from, $to] = $this->dateRange();

        return app(ClinicStatementService::class)->statement($from, $to);
    }

    public function render()
    {
        $this->normalizeDates();

        return view('livewire.admin.income-statement-component', [
            'sections' => IncomeStatementEntry::sections(),
            'entryPresets' => $this->entryPresets,
            'revenueLines' => $this->revenueLines,
            'costOfSalesLines' => $this->costOfSalesLines,
            'statement' => $this->statement,
            'entries' => $this->entries,
            'templates' => $this->templates,
            'comparison' => $this->comparison,
            'uncategorizedWarnings' => $this->uncategorizedWarnings,
            'periodLock' => $this->periodLock,
            'isLocked' => $this->isLocked,
            'clinicSettings' => Setting::getSettings(),
            'switcher' => \App\Support\FinanceStatements::switcherLinks(auth()->user(), $this->fromDate, $this->toDate),
        ])->layout('layouts.admin.admin-layout');
    }
}
