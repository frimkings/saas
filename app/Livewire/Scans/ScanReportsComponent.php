<?php

namespace App\Livewire\Scans;

use App\Services\Scans\ScanReports;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Diagnostic Scans → Reports (managers): referrals per partner clinic for any dates, by source,
 * by scan and by referring doctor, how fast results are reported and sent, and partners who have
 * gone quiet. Downloadable for Excel.
 */
class ScanReportsComponent extends Component
{
    public string $from = '';
    public string $to = '';

    public const PRESETS = ['this_month' => 'This month', 'last_month' => 'Last month', 'last_90' => 'Last 90 days', 'this_year' => 'This year'];

    public function mount(): void
    {
        abort_unless(DiagnosticScansComponent::canManage(), 403);
        $this->preset('this_month');
    }

    public function preset(string $key): void
    {
        [$from, $to] = match ($key) {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'last_90' => [now()->subDays(89), now()],
            'this_year' => [now()->startOfYear(), now()],
            default => [now()->startOfMonth(), now()],
        };
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    /** The chosen days, put right if they are missing or the wrong way round. */
    private function range(): array
    {
        $from = rescue(fn () => Carbon::createFromFormat('Y-m-d', $this->from)->startOfDay(), now()->startOfMonth(), false);
        $to = rescue(fn () => Carbon::createFromFormat('Y-m-d', $this->to)->startOfDay(), now()->startOfDay(), false);

        return $from->lte($to) ? [$from, $to] : [$to, $from];
    }

    public function render()
    {
        abort_unless(DiagnosticScansComponent::canManage(), 403);
        [$from, $to] = $this->range();

        return view('livewire.scans.scan-reports-component', [
            'report' => app(ScanReports::class)->for($from, $to),
            'rangeFrom' => $from,
            'rangeTo' => $to,
            'presets' => self::PRESETS,
            'dormantDays' => ScanReports::DORMANT_DAYS,
        ]);
    }
}
