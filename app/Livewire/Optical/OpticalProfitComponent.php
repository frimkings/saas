<?php

namespace App\Livewire\Optical;

use App\Services\ClinicAccessService;
use App\Services\Finance\PeriodLockService;
use App\Services\OpticalProfitService;
use App\Support\BusinessLine;
use Illuminate\Support\Carbon;
use Livewire\Component;

/** Optical profit and loss for a chosen period, compared with the previous period, last year or recent months. */
class OpticalProfitComponent extends Component
{
    public string $from = '';
    public string $to = '';
    public string $lockNotes = '';
    public string $compare = 'previous';

    public const COMPARE = [
        'previous' => 'Previous period',
        'year' => 'Same period last year',
        'months' => 'Last 3 months side by side',
        'none' => 'No comparison',
    ];

    protected $queryString = ['from', 'to', 'compare' => ['except' => 'previous']];

    public function mount(): void
    {
        $this->from = $this->validDate($this->from) ?? now()->startOfMonth()->toDateString();
        $this->to = $this->validDate($this->to) ?? now()->toDateString();
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : null;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(): array
    {
        $from = Carbon::parse($this->validDate($this->from) ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($this->validDate($this->to) ?? now()->toDateString());
        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }

    public function setPeriod(string $period): void
    {
        [$from, $to] = match ($period) {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [now()->startOfYear(), now()],
            default => [now()->startOfMonth(), now()],
        };
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    /** Close the period: optical expenses dated inside it can no longer be changed. */
    public function lockPeriod(): void
    {
        abort_if(!auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        app(ClinicAccessService::class)->assertWritable('optical');
        $this->validate(['lockNotes' => 'nullable|string|max:1000']);
        [$from, $to] = $this->period();
        abort_unless($to->lt(today()), 422, 'A period can only be locked once it has ended.');
        $service = app(OpticalProfitService::class);
        app(PeriodLockService::class)->lock(BusinessLine::OPTICAL, $from, $to, trim($this->lockNotes), $service->snapshot($service->statement($from, $to)));
        $this->lockNotes = '';
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Period locked.']);
    }

    public function unlockPeriod(): void
    {
        abort_if(!auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        app(ClinicAccessService::class)->assertWritable('optical');
        [$from, $to] = $this->period();
        app(PeriodLockService::class)->unlock(BusinessLine::OPTICAL, $from, $to);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Period unlocked.']);
    }

    /**
     * The period to compare with, like for like: month to date against the same days last
     * month, whole months against the months before, year to date against last year.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function previousPeriod(Carbon $from, Carbon $to): array
    {
        $monthStart = $from->isSameDay($from->copy()->startOfMonth());
        if ($monthStart && $to->isSameDay($to->copy()->endOfMonth())) {
            $months = ($to->year - $from->year) * 12 + $to->month - $from->month + 1;
            return [$from->copy()->subMonthsNoOverflow($months), $from->copy()->subDay()];
        }
        if ($monthStart && $from->isSameMonth($to)) {
            $start = $from->copy()->subMonthNoOverflow();
            return [$start, $to->copy()->subMonthNoOverflow()->min($start->copy()->endOfMonth())];
        }
        if ($from->isSameDay($from->copy()->startOfYear()) && $from->isSameYear($to)) {
            return [$from->copy()->subYear(), $to->copy()->subYearNoOverflow()];
        }
        $days = (int) $from->diffInDays($to) + 1;
        return [$from->copy()->subDays($days), $from->copy()->subDay()];
    }

    /** @return list<array{label: string, from: Carbon, to: Carbon}> columns to show, the chosen period last unless comparing months */
    private function columns(Carbon $from, Carbon $to): array
    {
        $column = fn (Carbon $a, Carbon $b) => ['from' => $a, 'to' => $b, 'label' => $a->isSameDay($a->copy()->startOfMonth()) && $b->isSameDay($b->copy()->endOfMonth()) && $a->isSameMonth($b)
            ? $a->format('M Y') : $a->format('d M').' – '.$b->format('d M Y')];
        return match ($this->compare) {
            'none' => [$column($from, $to)],
            'year' => [$column($from, $to), $column($from->copy()->subYear(), $to->copy()->subYearNoOverflow())],
            // The month the period ends in, to date, and the two whole months before it.
            'months' => collect([2, 1, 0])->map(function ($back) use ($to, $column) {
                $start = $to->copy()->startOfMonth()->subMonthsNoOverflow($back);
                return $column($start, $back === 0 ? $to->copy() : $start->copy()->endOfMonth());
            })->all(),
            default => [$column($from, $to), $column(...self::previousPeriod($from, $to))],
        };
    }

    public function render()
    {
        if (! array_key_exists($this->compare, self::COMPARE)) $this->compare = 'previous';
        [$from, $to] = $this->period();
        $service = app(OpticalProfitService::class);
        $columns = collect($this->columns($from, $to))->map(fn ($column) => $column + ['pl' => $service->statement($column['from'], $column['to'])])->all();
        // The chosen period is the first column, or the last when months run side by side.
        $current = $this->compare === 'months' ? count($columns) - 1 : 0;
        $pl = $columns[$current]['pl'];
        $baseline = match ($this->compare) { 'none' => null, 'months' => $columns[$current - 1]['pl'], default => $columns[1]['pl'] };
        $lock = app(PeriodLockService::class)->find(BusinessLine::OPTICAL, $from, $to);
        // Sales, refunds and cancellations can still move a closed period's figures.
        $lockedNet = $lock?->snapshot['netProfit'] ?? null;
        $periodNet = $lockedNet === null ? null : ($this->compare === 'months' ? $service->statement($from, $to)['netProfit'] : $pl['netProfit']);
        $lockDrift = $lockedNet !== null && abs($lockedNet - $periodNet) >= 0.01 ? (float) $lockedNet : null;

        return view('livewire.optical.optical-profit-component', [
            'pl' => $pl,
            'columns' => $columns,
            'baseline' => $baseline,
            'baselineLabel' => match ($this->compare) { 'none' => null, 'months' => $columns[$current - 1]['label'], default => $columns[1]['label'] },
            'fromDate' => $from, 'toDate' => $to,
            'lock' => $lock, 'lockedNet' => $lockDrift, 'periodNet' => $periodNet,
            'canLock' => $to->lt(today()),
            'switcher' => \App\Support\FinanceStatements::switcherLinks(auth()->user(), $from->toDateString(), $to->toDateString()),
        ])->layout('layouts.optical');
    }
}
