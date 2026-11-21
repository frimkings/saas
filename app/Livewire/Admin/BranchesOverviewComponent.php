<?php

namespace App\Livewire\Admin;

use App\Models\Branch;
use App\Models\Consultations;
use App\Models\Expense;
use App\Models\LensOrder;
use App\Models\PaymentTransaction;
use App\Models\Sales;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * All branches side by side for the owner (21 Nov 2026): what each billed, the cash it took, its expenses, visits,
 * glasses waiting and patient balances, with the totals. Every other page shows the branch that is open.
 */
class BranchesOverviewComponent extends Component
{
    public const PERIODS = ['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'last_month' => 'Last month', 'custom' => 'Dates'];

    #[Url]
    public string $period = 'month';
    #[Url]
    public string $from = '';
    #[Url]
    public string $to = '';

    public function mount(): void
    {
        // The owner; with one branch the page says how to add another (the dashboard button shows only with two).
        abort_unless(\App\Support\Access\Access::allows(auth()->user(), 'settings.manage'), 403);
        if (! array_key_exists($this->period, self::PERIODS)) $this->period = 'month';
    }

    /** The owner (and whoever may manage settings), in a clinic with more than one branch they can open. */
    public static function allowed(): bool
    {
        return \App\Support\Access\Access::allows(auth()->user(), 'settings.manage') && count(self::branchIds()) > 1;
    }

    /** Branches of this clinic the signed-in person can open. */
    public static function branchIds(): array
    {
        $context = app(TenantContext::class);
        $clinicId = $context->clinicId();
        if (! $clinicId) return [];

        return Branch::withoutGlobalScopes()->where('clinic_id', $clinicId)->where('is_active', true)
            ->whereIn('id', $context->authorizedBranchIds())->orderByDesc('is_default')->orderBy('name')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(): array
    {
        return match ($this->period) {
            'today' => [today(), today()->endOfDay()],
            'week' => [now()->startOfWeek(), now()->endOfDay()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'custom' => [
                strtotime($this->from) ? Carbon::parse($this->from)->startOfDay() : now()->startOfMonth(),
                strtotime($this->to) ? Carbon::parse($this->to)->endOfDay() : now()->endOfDay(),
            ],
            default => [now()->startOfMonth(), now()->endOfDay()],
        };
    }

    public function render()
    {
        $ids = self::branchIds();
        [$start, $end] = $this->range();
        $by = fn ($query) => $query->withoutGlobalScope('branch')->whereIn('branch_id', $ids)->groupBy('branch_id');

        $billed = $by(Sales::query())->where('is_refunded', false)->whereBetween('created_at', [$start, $end])
            ->selectRaw('branch_id, SUM('.Sales::BILLED_SQL.') as agg_value')->pluck('agg_value', 'branch_id');
        $cash = $by(PaymentTransaction::query())->whereBetween('created_at', [$start, $end])
            ->selectRaw('branch_id, SUM(amount) as agg_value')->pluck('agg_value', 'branch_id');
        $expenses = $by(Expense::query())->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('branch_id, SUM(amount) as agg_value')->pluck('agg_value', 'branch_id');
        $visits = $by(Consultations::query())->whereBetween('created_at', [$start, $end])
            ->selectRaw('branch_id, COUNT(*) as agg_value')->pluck('agg_value', 'branch_id');
        // Right now, whatever the period: balances patients owe and glasses waiting to be collected.
        $owed = $by(Sales::query())->where('payment_status', 'partial')->where('is_refunded', false)
            ->selectRaw('branch_id, SUM('.Sales::PATIENT_BALANCE_SQL.') as agg_value')->pluck('agg_value', 'branch_id');
        $ready = $by(LensOrder::query())->whereIn('status', ['Ready', 'Ready for Collection'])
            ->selectRaw('branch_id, COUNT(*) as agg_value')->pluck('agg_value', 'branch_id');

        $names = Branch::withoutGlobalScopes()->whereIn('id', $ids)->pluck('name', 'id');
        $rows = collect($ids)->map(fn ($id) => [
            'id' => $id, 'name' => $names[$id] ?? 'Branch',
            'billed' => round((float) ($billed[$id] ?? 0), 2), 'cash' => round((float) ($cash[$id] ?? 0), 2),
            'expenses' => round((float) ($expenses[$id] ?? 0), 2), 'visits' => (int) ($visits[$id] ?? 0),
            'owed' => round((float) ($owed[$id] ?? 0), 2), 'ready' => (int) ($ready[$id] ?? 0),
        ]);
        $totals = collect(['billed', 'cash', 'expenses', 'visits', 'owed', 'ready'])->mapWithKeys(fn ($key) => [$key => $rows->sum($key)])->all();

        return view('livewire.admin.branches-overview-component', [
            'rows' => $rows, 'totals' => $totals, 'start' => $start, 'end' => $end,
            'current' => app(TenantContext::class)->branchId(),
            'single' => count($ids) < 2,
        ])->layout('layouts.admin.admin-layout');
    }
}
