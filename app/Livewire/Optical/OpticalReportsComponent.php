<?php

namespace App\Livewire\Optical;

use App\Services\OpticalReportService;
use Illuminate\Support\Carbon;
use Livewire\Component;

class OpticalReportsComponent extends Component
{
    public $fromDate;
    public $toDate;

    public function mount()
    {
        $this->fromDate = now()->startOfMonth()->format('Y-m-d');
        $this->toDate = now()->format('Y-m-d');
    }

    public function render()
    {
        $service = app(OpticalReportService::class);
        [$from, $to] = $this->period();
        [$prevFrom, $prevTo] = OpticalReportService::previous($from, $to);

        $sales = $service->sales($from, $to);
        $before = $service->sales($prevFrom, $prevTo);
        $cash = $service->cash($from, $to);
        $cashBefore = $service->cash($prevFrom, $prevTo);
        $remakes = $service->remakes($from, $to, $sales['jobs']);

        return view('livewire.optical.optical-reports-component', [
            'sales' => $sales,
            'cash' => $cash,
            'owed' => $service->owed($from, $to),
            'trend' => $service->trend($from, $to, $sales['fees']),
            'remakes' => $remakes,
            'topProducts' => $service->topProducts($from, $to),
            'labTurnaround' => app(\App\Services\OpticalJobTrackingService::class)->turnaround($from, $to),
            'changes' => [
                'revenue' => OpticalReportService::change($sales['revenue'], $before['revenue']),
                'received' => OpticalReportService::change($cash['net'], $cashBefore['net']),
                'jobs' => OpticalReportService::change($sales['jobs'], $before['jobs']),
                'avgOrder' => OpticalReportService::change($sales['avgOrder'], $before['avgOrder']),
            ],
            'previousLabel' => $prevFrom->format('M j').' – '.$prevTo->format('M j'),
            // Kept for tests and older links that read these figures directly.
            'cancellationFees' => $sales['cancellationFees'],
            'depositsKept' => $sales['depositsKept'],
        ])->layout('layouts.optical');
    }

    /** @return array{0: Carbon, 1: Carbon} the chosen dates, earliest first */
    private function period(): array
    {
        $parse = fn ($date, $fallback) => rescue(fn () => Carbon::createFromFormat('Y-m-d', (string) $date)->startOfDay(), $fallback, false);
        $from = $parse($this->fromDate, today()->startOfMonth());
        $to = $parse($this->toDate, today());
        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }
}
