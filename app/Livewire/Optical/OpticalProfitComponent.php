<?php

namespace App\Livewire\Optical;

use App\Services\ClinicAccessService;
use App\Services\Finance\PeriodLockService;
use App\Services\OpticalProfitService;
use App\Support\BusinessLine;
use Illuminate\Support\Carbon;
use Livewire\Component;

/** Optical profit and loss for a chosen period, with the previous period alongside. */
class OpticalProfitComponent extends Component
{
    public string $from = '';
    public string $to = '';
    public string $lockNotes = '';

    protected $queryString = ['from', 'to'];

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

    public function render()
    {
        [$from, $to] = $this->period();
        // Same number of days immediately before, for comparison.
        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->copy()->subDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1);
        $service = app(OpticalProfitService::class);
        $pl = $service->statement($from, $to);
        $lock = app(PeriodLockService::class)->find(BusinessLine::OPTICAL, $from, $to);
        // Sales, refunds and cancellations can still move a closed period's figures.
        $lockedNet = $lock?->snapshot['netProfit'] ?? null;
        $lockDrift = $lockedNet !== null && abs($lockedNet - $pl['netProfit']) >= 0.01 ? (float) $lockedNet : null;

        return view('livewire.optical.optical-profit-component', [
            'pl' => $pl,
            'previous' => $service->statement($previousFrom, $previousTo),
            'fromDate' => $from, 'toDate' => $to, 'previousFrom' => $previousFrom, 'previousTo' => $previousTo,
            'lock' => $lock, 'lockedNet' => $lockDrift,
            'canLock' => $to->lt(today()),
            'switcher' => \App\Support\FinanceStatements::switcherLinks(auth()->user(), $from->toDateString(), $to->toDateString()),
        ])->layout('layouts.optical');
    }
}
