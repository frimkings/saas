<?php

namespace App\Livewire\Admin;

use App\Models\Insurer;
use App\Models\InsurerPayment;
use App\Models\PaymentTransaction;
use App\Models\SaleAdjustment;
use App\Models\Sales;
use App\Support\BusinessLine;
use App\Support\FinanceStatements;
use Carbon\Carbon;
use Livewire\Component;

class DailyCashSummaryComponent extends Component
{
    public const COMBINED = 'combined';

    public $reportDate;

    /** clinic, optical, or combined (both businesses together) */
    public string $line = '';

    protected $queryString = ['line' => ['except' => '']];

    public function mount()
    {
        $this->reportDate = Carbon::today()->toDateString();
        $this->line = $this->validLine($this->line);
    }

    /** The lines this subscriber can pick; a single-line subscriber only sees their own. */
    public function lineOptions(): array
    {
        $lines = FinanceStatements::availableLines();

        return count($lines) === 1 ? $lines : [...$lines, self::COMBINED];
    }

    private function validLine(string $line): string
    {
        $options = $this->lineOptions();
        if (in_array($line, $options, true)) return $line;

        // Default to everything the subscriber runs.
        return count($options) === 1 ? $options[0] : self::COMBINED;
    }

    public function print()
    {
        $this->dispatch('print-page');
    }

    public function updatedReportDate(): void
    {
        $this->dispatch('update-payment-chart', ...$this->buildChartPayload());
    }

    public function updatedLine(): void
    {
        $this->line = $this->validLine($this->line);
        $this->dispatch('update-payment-chart', ...$this->buildChartPayload());
    }

    private function salesQuery()
    {
        return Sales::query()->when($this->line !== self::COMBINED, fn ($q) => $q->where('business_line', $this->line));
    }

    /** Payments on this line's sales (including sales deleted since). */
    private function paymentsQuery()
    {
        return PaymentTransaction::query()->when($this->line !== self::COMBINED,
            fn ($q) => $q->whereIn('sale_id', Sales::withTrashed()->where('business_line', $this->line)->select('id')));
    }

    protected function buildChartPayload(): array
    {
        $start    = Carbon::parse($this->reportDate)->startOfDay();
        $end      = Carbon::parse($this->reportDate)->endOfDay();
        $payments = $this->paymentsQuery()->whereBetween('created_at', [$start, $end])
            ->selectRaw('payment_method, SUM(amount) as total, COUNT(*) as cnt')
            ->groupBy('payment_method')
            ->get();


        $labels = [];
        $data   = [];
        $colors = [];
        $counts = [];

        foreach ($payments as $p) {
            $labels[] = \App\Support\PaymentMethods::label($p->payment_method);
            $data[]   = round((float) $p->total, 2);
            $colors[] = \App\Support\PaymentMethods::color($p->payment_method);
            $counts[] = (int) $p->cnt;
        }

        return compact('labels', 'data', 'colors', 'counts');
    }

    public function render()
    {
        $this->line = $this->validLine($this->line);
        $start = Carbon::parse($this->reportDate)->startOfDay();
        $end = Carbon::parse($this->reportDate)->endOfDay();

        $payments = $this->paymentsQuery()->whereBetween('created_at', [$start, $end])
            ->selectRaw('payment_method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('payment_method')
            ->orderBy('payment_method')
            ->get();

        $agg = $this->salesQuery()->whereBetween('created_at', [$start, $end])
            ->selectRaw('
                COUNT(*) as sales_count,
                COALESCE(SUM(total_amount), 0) as gross_sales,
                COALESCE(SUM(amount_paid), 0) as amount_paid,
                COALESCE(SUM(GREATEST(0, total_amount - insurer_amount - amount_paid)), 0) as outstanding,
                COUNT(CASE WHEN is_refunded = 1 THEN 1 END) as refunds_count,
                COALESCE(SUM(CASE WHEN is_refunded = 1 THEN total_amount ELSE 0 END), 0) as refunds_total
            ')
            ->first();

        // With both businesses together, show how much each one collected.
        $collectedByLine = $this->line === self::COMBINED
            ? PaymentTransaction::query()->join('sales', 'sales.id', '=', 'payment_transactions.sale_id')
                ->whereBetween('payment_transactions.created_at', [$start, $end])
                ->selectRaw('sales.business_line as line, SUM(payment_transactions.amount) as total, COUNT(*) as count')
                ->groupBy('sales.business_line')->get()->keyBy('line')
            : collect();

        // Insurance (clinic only): billed to insurers today, paid by insurers today, shortfalls written off today.
        $insurance = null;
        if ($this->line !== BusinessLine::OPTICAL && Insurer::exists()) {
            $insurance = [
                'billed'     => (float) Sales::where('business_line', BusinessLine::CLINIC)->whereBetween('created_at', [$start, $end])->sum('insurer_amount'),
                'received'   => (float) InsurerPayment::whereDate('paid_on', $start->toDateString())->sum('amount'),
                'writtenOff' => (float) SaleAdjustment::where('type', 'insurance_write_off')->whereBetween('created_at', [$start, $end])->sum('amount'),
            ];
        }

        return view('livewire.admin.daily-cash-summary-component', [
            'insurance'         => $insurance,
            'chartPayload'      => $this->buildChartPayload(),
            'payments'          => $payments,
            'salesCount'        => (int) $agg->sales_count,
            'grossSales'        => $agg->gross_sales,
            'amountPaid'        => $agg->amount_paid,
            'outstandingCreated' => $agg->outstanding,
            'refundsCount'      => (int) $agg->refunds_count,
            'refundsTotal'      => $agg->refunds_total,
            'lineOptions'       => $this->lineOptions(),
            'lineLabels'        => [BusinessLine::CLINIC => 'Clinic', BusinessLine::OPTICAL => 'Optical', self::COMBINED => 'Combined'],
            'collectedByLine'   => $collectedByLine,
        ])->layout('layouts.admin.admin-layout');
    }
}
