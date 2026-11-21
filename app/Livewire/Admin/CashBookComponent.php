<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\CashMovement;
use App\Services\Finance\CashBook;
use App\Services\Finance\DayCloseService;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Livewire\Component;

/**
 * The branch's collections ledger, one tab per payment method: what came in and went out and
 * the running balance, with money banked, added or counted at the start recorded by hand.
 */
class CashBookComponent extends Component
{
    public string $method = 'cash';
    /** '' the open branch; another branch's id (its book, read-only); 'all' one summary row per branch. Owners only. */
    #[\Livewire\Attributes\Url(as: 'branch')]
    public string $branchView = '';
    public string $fromDate = '';
    public string $toDate = '';

    // Recording money moved by hand.
    public string $moveType = CashMovement::BANKED;
    public string $moveAmount = '';
    public string $moveDate = '';
    public string $moveReference = '';
    public string $moveNotes = '';

    // Starting the book: what each method held on the day it starts.
    public string $startDate = '';
    public array $openingCounts = [];

    public string $floatAmount = '';

    public ?int $cancellingId = null;
    public string $cancelReason = '';

    /** Days whose lines are shown, when the period is long enough to be folded into daily totals. */
    public array $openDays = [];

    /** Up to this many lines, every line shows; above it the book folds into one row per day. */
    public const FOLD_ABOVE = 50;

    protected $queryString = [
        'method' => ['except' => 'cash'],
        'fromDate' => ['except' => ''],
        'toDate' => ['except' => ''],
    ];

    public function mount(): void
    {
        $this->fromDate = $this->validDate($this->fromDate, today()->startOfMonth());
        $this->toDate = $this->validDate($this->toDate, today());
        $this->moveDate = today()->toDateString();
        $this->startDate = today()->toDateString();
        $this->floatAmount = number_format((float) (app(TenantContext::class)->branch()?->till_float ?? 0), 2, '.', '');
        $methods = app(CashBook::class)->methods();
        if (!array_key_exists($this->method, $methods)) $this->method = array_key_first($methods) ?? 'cash';
        AuditTrail::record('report.accessed', 'Opened the cash book');
    }

    private function validDate(string $value, Carbon $fallback): string
    {
        try {
            return $value !== '' ? Carbon::parse($value)->toDateString() : $fallback->toDateString();
        } catch (\Throwable) {
            return $fallback->toDateString();
        }
    }

    public function updatedFromDate(): void
    {
        $this->fromDate = $this->validDate($this->fromDate, today()->startOfMonth());
    }

    public function updatedToDate(): void
    {
        $this->toDate = $this->validDate($this->toDate, today());
    }

    public function selectMethod(string $method): void
    {
        if (array_key_exists($method, app(CashBook::class)->methods())) $this->method = $method;
        $this->openDays = [];
    }

    /** Show or hide one day's lines. */
    public function toggleDay(string $date): void
    {
        $date = $this->validDate($date, today());
        $this->openDays = in_array($date, $this->openDays, true)
            ? array_values(array_diff($this->openDays, [$date]))
            : [...$this->openDays, $date];
    }

    public function recordMovement(): void
    {
        $this->onlyOpenBranch();
        $days = app(DayCloseService::class);
        $this->validate([
            'moveType' => 'required|in:' . CashMovement::BANKED . ',' . CashMovement::ADDED,
            'moveAmount' => 'required|numeric|min:0.01|max:99999999',
            'moveDate' => 'required|date|before_or_equal:today',
            'moveReference' => 'nullable|string|max:255',
            'moveNotes' => 'nullable|string|max:500',
        ], [], ['moveAmount' => 'amount', 'moveDate' => 'date']);

        try {
            $days->recordMovement($this->moveType, $this->method, (float) $this->moveAmount, Carbon::parse($this->moveDate), trim($this->moveReference), trim($this->moveNotes));
        } catch (\RuntimeException $e) {
            $this->addError('moveAmount', $e->getMessage());
            return;
        }
        $this->reset('moveAmount', 'moveReference', 'moveNotes');
        session()->flash('success', 'Recorded on the cash book.');
    }

    public function startBook(): void
    {
        $this->onlyOpenBranch();
        $days = app(DayCloseService::class);
        abort_unless(DayCloseService::isOwner(), 403);
        $this->validate([
            'startDate' => 'required|date|before_or_equal:today',
            'openingCounts' => 'array',
            'openingCounts.*' => 'nullable|numeric|min:0|max:99999999',
        ], [], ['startDate' => 'start date', 'openingCounts.*' => 'opening balance']);

        $counts = collect($this->openingCounts)->only(array_keys(app(CashBook::class)->methods()))
            ->filter(fn ($value) => $value !== null && $value !== '');
        if ($counts->isEmpty() || !$counts->has('cash')) {
            $this->addError('openingCounts.cash', 'Enter at least what is in the cash drawer (0 if it is empty).');
            return;
        }
        try {
            foreach ($counts as $key => $value) {
                $days->recordMovement(CashMovement::OPENING, $key, (float) $value, Carbon::parse($this->startDate), null, 'Opening count');
            }
        } catch (\RuntimeException $e) {
            $this->addError('startDate', $e->getMessage());
            return;
        }
        $this->openingCounts = [];
        $this->fromDate = $this->startDate;
        session()->flash('success', 'The cash book starts on ' . Carbon::parse($this->startDate)->format('d M Y') . '.');
    }

    public function saveFloat(): void
    {
        $this->onlyOpenBranch();
        abort_unless(DayCloseService::isOwner(), 403);
        $this->validate(['floatAmount' => 'required|numeric|min:0|max:99999999'], [], ['floatAmount' => 'float']);
        $branch = app(TenantContext::class)->branch();
        abort_unless($branch, 404);
        $old = (float) $branch->till_float;
        $branch->forceFill(['till_float' => round((float) $this->floatAmount, 2)])->save();
        AuditTrail::record('branch.till_float', 'Set the till float to ' . number_format((float) $this->floatAmount, 2), $branch, ['till_float' => $old], ['till_float' => (float) $this->floatAmount]);
        session()->flash('success', 'Till float saved.');
    }

    public function askCancel(int $id): void
    {
        $this->onlyOpenBranch();
        $this->cancellingId = $id;
        $this->cancelReason = '';
        $this->resetErrorBag('cancelReason');
    }

    public function cancelMovement(): void
    {
        $this->onlyOpenBranch();
        $days = app(DayCloseService::class);
        $this->validate(['cancelReason' => 'required|string|min:5|max:500'], [], ['cancelReason' => 'reason']);
        $movement = CashMovement::findOrFail($this->cancellingId);
        try {
            $days->cancelMovement($movement, trim($this->cancelReason));
        } catch (\RuntimeException $e) {
            $this->addError('cancelReason', $e->getMessage());
            return;
        }
        $this->cancellingId = null;
        session()->flash('success', 'Entry cancelled. It stays on the book, crossed out.');
    }

    public function updatedBranchView(): void
    {
        if (\App\Support\BranchView::ids($this->branchView) === null) $this->branchView = '';
        $this->openDays = [];
        $this->cancellingId = null;
    }

    /** Entries are made on the open branch's own book; another branch's is only looked at here. */
    private function onlyOpenBranch(): void
    {
        abort_if(\App\Support\BranchView::ids($this->branchView) !== null && ! $this->viewingOpenBranch(), 403, 'Switch to that branch to change its cash book.');
    }

    private function viewingOpenBranch(): bool
    {
        return (string) $this->branchView === (string) app(TenantContext::class)->branchId();
    }

    /** Run the book's own sums as if that branch were open, then put the open branch back. */
    private function atBranch(int $branchId, \Closure $work): mixed
    {
        $context = app(TenantContext::class);
        [$user, $clinic, $branch, $authorized] = [$context->user() ?? auth()->user(), $context->clinic(), $context->branch(), $context->authorizedBranchIds()];
        $target = \App\Models\Branch::withoutGlobalScopes()->where('clinic_id', $clinic->id)->findOrFail($branchId);
        try {
            $context->set($user, $clinic, $target, $authorized);
            return $work();
        } finally {
            $context->set($user, $clinic, $branch, $authorized);
        }
    }

    public function render()
    {
        $ids = \App\Support\BranchView::ids($this->branchView);
        $options = \App\Support\BranchView::canPick() ? \App\Support\BranchView::options() : [];
        $from = Carbon::parse(min($this->fromDate, $this->toDate));
        $to = Carbon::parse(max($this->fromDate, $this->toDate));

        // All branches: one row per branch for the chosen method and dates, from each branch's own book.
        if ($ids !== null && $this->branchView === 'all') {
            $rows = collect($ids)->map(fn ($id) => $this->atBranch($id, function () use ($id, $options, $from, $to) {
                $book = app(CashBook::class);
                $entries = $book->entries($this->method, $from, $to);
                return ['id' => $id, 'name' => $options[$id] ?? 'Branch', 'started' => (bool) $book->startDate(),
                    'opening' => $entries->first()['balance'] ?? 0, 'in' => round($entries->sum('in'), 2), 'out' => round($entries->sum('out'), 2),
                    'closing' => $entries->last()['balance'] ?? 0, 'methods' => $book->methods()];
            }));
            $methods = $rows->reduce(fn ($all, $row) => $all + $row['methods'], []);

            return view('livewire.admin.cash-book-component', [
                'summaryRows' => $rows, 'methods' => $methods, 'branchOptions' => $options, 'readOnly' => true,
                'branch' => null, 'started' => true, 'isOwner' => DayCloseService::isOwner(),
            ])->layout('layouts.admin.admin-layout');
        }

        // Another branch's book, read-only.
        if ($ids !== null && ! $this->viewingOpenBranch()) {
            return $this->atBranch($ids[0], fn () => $this->bookView($from, $to, $options, true));
        }

        return $this->bookView($from, $to, $options, false);
    }

    private function bookView(Carbon $from, Carbon $to, array $options, bool $readOnly)
    {
        $book = app(CashBook::class);
        $methods = $book->methods();
        $entries = $book->entries($this->method, $from, $to);
        $balances = collect($methods)->map(fn ($label, $key) => $book->balanceBefore($key, today()->addDay()));

        // A long period folds into one row per day (totals and closing balance); a day's lines
        // show when it is opened. Short periods show every line as before.
        $lines = $entries->reject(fn ($entry) => $entry['kind'] === 'opening');
        $folded = $lines->count() > self::FOLD_ABOVE;
        $days = $lines->groupBy(fn ($entry) => $entry['date']->toDateString())->map(fn ($dayEntries, $date) => [
            'date' => Carbon::parse($date),
            'in' => round($dayEntries->sum('in'), 2),
            'out' => round($dayEntries->sum('out'), 2),
            'balance' => $dayEntries->last()['balance'],
            'count' => $dayEntries->count(),
            'open' => ! $folded || in_array($date, $this->openDays, true) || ($this->cancellingId && $dayEntries->contains('movement_id', $this->cancellingId)),
            'entries' => $dayEntries,
        ]);

        return view('livewire.admin.cash-book-component', [
            'methods' => $methods,
            'entries' => $entries,
            'openingEntry' => $entries->firstWhere('kind', 'opening'),
            'days' => $days,
            'folded' => $folded,
            'opening' => $entries->first()['balance'] ?? 0,
            'closing' => $entries->last()['balance'] ?? 0,
            'totalIn' => round($entries->sum('in'), 2),
            'totalOut' => round($entries->sum('out'), 2),
            'balances' => $balances,
            'started' => $book->startDate(),
            'isOwner' => DayCloseService::isOwner(),
            'branch' => app(TenantContext::class)->branch(),
            'branchOptions' => $options,
            'readOnly' => $readOnly,
            'summaryRows' => null,
        ])->layout('layouts.admin.admin-layout');
    }
}
