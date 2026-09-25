<?php

namespace App\Services\Finance;

use App\Services\OpticalProfitService;
use App\Support\BusinessLine;
use Carbon\CarbonInterface;

/**
 * The whole business for subscribers running both clinic and optical.
 *
 * Each line is worked out by its own statement, with its own rules (the clinic counts
 * revenue at the sale, optical when a job is ordered). They are only added together at
 * the totals. Tax is the clinic statement's own tax on clinic profit; optical has no tax
 * setting, so none is invented for it.
 */
class CombinedStatementService
{
    /** Rows shown for every line, in order. */
    public const ROWS = [
        'revenue' => 'Revenue',
        'cost_of_sales' => 'Cost of sales',
        'gross_profit' => 'Gross profit',
        'stock_losses' => 'Stock losses',
        'operating_expenses' => 'Operating expenses',
        'operating_profit' => 'Operating profit',
        'non_operating_expenses' => 'Non-operating expenses',
        'profit_before_tax' => 'Profit before tax',
        'tax' => 'Tax',
        'net_profit' => 'Net profit',
    ];

    public function __construct(
        private ClinicStatementService $clinic,
        private OpticalProfitService $optical,
    ) {}

    public function statement(CarbonInterface $from, CarbonInterface $to): array
    {
        $clinic = $this->clinic->statement($from, $to);
        $optical = $this->optical->statement($from, $to);

        $lines = [
            BusinessLine::CLINIC => [
                'revenue' => $clinic['revenue'],
                'cost_of_sales' => $clinic['cost_of_sales'],
                'gross_profit' => $clinic['gross_profit'],
                'stock_losses' => 0.0,
                'operating_expenses' => $clinic['operating_expenses'],
                'operating_profit' => $clinic['operating_profit'],
                'non_operating_expenses' => $clinic['non_operating_expenses'],
                'profit_before_tax' => $clinic['profit_for_period'],
                'tax' => $clinic['tax_amount'],
                'net_profit' => $clinic['net_profit'],
            ],
            BusinessLine::OPTICAL => [
                'revenue' => $optical['totalRevenue'],
                'cost_of_sales' => $optical['totalCosts'],
                'gross_profit' => $optical['grossProfit'],
                'stock_losses' => $optical['totalLosses'],
                'operating_expenses' => $optical['totalOperating'],
                'operating_profit' => $optical['operatingProfit'],
                'non_operating_expenses' => $optical['totalNonOperating'],
                'profit_before_tax' => $optical['netProfit'],
                'tax' => 0.0,
                'net_profit' => $optical['netProfit'],
            ],
        ];
        $lines = array_map(fn ($line) => array_map(fn ($value) => round((float) $value, 2), $line), $lines);
        $total = [];
        foreach (array_keys(self::ROWS) as $key) {
            $total[$key] = round($lines[BusinessLine::CLINIC][$key] + $lines[BusinessLine::OPTICAL][$key], 2);
        }

        return [
            'lines' => $lines,
            'total' => $total,
            'clinicTaxRate' => (float) $clinic['tax_rate'],
            'uncostedFrames' => $optical['uncostedFrames'],
        ];
    }

    /** The statement as rows for the shared CSV/PDF export (see StatementExporter). */
    public function exportRows(array $statement): array
    {
        $rows = [];
        foreach ([BusinessLine::CLINIC => 'Clinic', BusinessLine::OPTICAL => 'Optical', 'total' => 'Whole business'] as $key => $heading) {
            $values = $key === 'total' ? $statement['total'] : $statement['lines'][$key];
            $rows[] = ['type' => 'heading', 'label' => $heading];
            foreach (self::ROWS as $row => $label) {
                if ($row === 'stock_losses' && $key === BusinessLine::CLINIC) continue;
                if ($row === 'tax' && $key === BusinessLine::OPTICAL) continue;
                $type = $row === 'net_profit' ? ($key === 'total' ? 'final' : 'total') : (in_array($row, ['gross_profit', 'operating_profit', 'profit_before_tax'], true) ? 'total' : 'line');
                $rows[] = ['type' => $type, 'label' => $label, 'amount' => $values[$row]];
            }
        }

        return $rows;
    }
}
