<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Services\Finance\CombinedStatementService;
use App\Services\Finance\PeriodLockService;
use App\Support\BusinessLine;
use App\Support\FinanceStatements;
use Illuminate\Support\Carbon;
use Livewire\Component;

/** Clinic and optical side by side, and the whole business, for subscribers running both. */
class CombinedStatementComponent extends Component
{
    public string $from = '';
    public string $to = '';

    protected $queryString = ['from', 'to'];

    public function mount(): void
    {
        abort_unless(FinanceStatements::canViewCombined(auth()->user()), 403);
        AuditTrail::record('report.accessed', 'Accessed combined financial statement');
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

    public function render()
    {
        [$from, $to] = $this->period();
        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->copy()->subDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1);
        $service = app(CombinedStatementService::class);
        $locks = app(PeriodLockService::class);

        return view('livewire.admin.combined-statement-component', [
            'statement' => $service->statement($from, $to),
            'previous' => $service->statement($previousFrom, $previousTo),
            'rows' => CombinedStatementService::ROWS,
            'fromDate' => $from, 'toDate' => $to, 'previousFrom' => $previousFrom, 'previousTo' => $previousTo,
            'locked' => [
                BusinessLine::CLINIC => (bool) $locks->find(BusinessLine::CLINIC, $from, $to),
                BusinessLine::OPTICAL => (bool) $locks->find(BusinessLine::OPTICAL, $from, $to),
            ],
            'switcher' => FinanceStatements::switcherLinks(auth()->user(), $from->toDateString(), $to->toDateString()),
        ])->layout('layouts.admin.admin-layout');
    }
}
