<?php

namespace App\Http\Controllers;

use App\Services\Finance\ClinicStatementService;
use App\Services\Finance\PeriodLockService;
use App\Services\Finance\StatementExporter;
use App\Support\BusinessLine;
use App\Support\FinanceStatements;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;

class IncomeStatementExportController extends Controller
{
    private const DEFAULT_TEMPLATE_NOTE = 'Default recurring value from income statement setup.';

    public function exportCsv(Request $request)
    {
        return $this->csv($request);
    }

    public function exportPdf(Request $request)
    {
        return $this->pdf($request);
    }

    public function csv(Request $request)
    {
        $report = $this->buildReport($request);

        return app(StatementExporter::class)->csv($this->document($report), $this->filename($report));
    }

    public function pdf(Request $request)
    {
        $report = $this->buildReport($request);

        return app(StatementExporter::class)->pdf($this->document($report), $this->filename($report));
    }

    public function preview(Request $request)
    {
        return app(StatementExporter::class)->preview($this->document($this->buildReport($request)));
    }

    private function filename(array $report): string
    {
        return 'income-statement-' . $report['from'] . '-to-' . $report['to'];
    }

    /** The clinic statement in the shared export layout (see StatementExporter). */
    private function document(array $report): array
    {
        $statement = $report['statement'];
        $rows = [];
        $section = function (string $heading, $lines) use (&$rows) {
            $rows[] = ['type' => 'heading', 'label' => $heading];
            foreach ($lines as $line) {
                $rows[] = ['type' => 'line', 'label' => $line['name'], 'amount' => $line['amount'], 'note' => $line['notes'] ?? null];
            }
        };
        $section('Revenue', $report['revenue_lines']);
        $rows[] = ['type' => 'total', 'label' => 'Total revenue', 'amount' => $statement['revenue']];
        $section('Cost of sales', $report['cost_lines']);
        $rows[] = ['type' => 'total', 'label' => 'Total cost of sales', 'amount' => $statement['cost_of_sales']];
        $rows[] = ['type' => 'total', 'label' => 'Gross profit', 'amount' => $statement['gross_profit']];
        $section('Operating expenses', $report['operating_lines']);
        $rows[] = ['type' => 'total', 'label' => 'Operating profit', 'amount' => $statement['operating_profit']];
        $section('Non-operating expenses', $report['non_operating_lines']);
        $rows[] = ['type' => 'total', 'label' => 'Profit for the period', 'amount' => $statement['profit_for_period']];
        $rows[] = ['type' => 'line', 'label' => 'Tax (' . number_format($statement['tax_rate'], 2) . '%)', 'amount' => $statement['tax_amount']];
        $rows[] = ['type' => 'final', 'label' => 'Net profit', 'amount' => $statement['net_profit']];
        $lock = app(PeriodLockService::class)->find(BusinessLine::CLINIC, Carbon::parse($report['from']), Carbon::parse($report['to']));

        return [
            'title' => 'Income Statement',
            'from' => $report['from'],
            'to' => $report['to'],
            'generated_at' => $report['generated_at'],
            'generated_by' => $report['generated_by'],
            'status' => $lock ? 'Period locked ' . $lock->locked_at?->format('M d, Y') . ($lock->lockedBy ? ' by ' . $lock->lockedBy->name : '') : null,
            'rows' => $rows,
            'notes' => [],
        ];
    }

    protected function buildReport(Request $request)
    {
        abort_unless(FinanceStatements::canViewClinic($request->user()), 403);
        $from = $this->normalizeDate($request->input('from'), now()->startOfMonth());
        $to = $this->normalizeDate($request->input('to'), now()->endOfMonth());
        $fromCarbon = Carbon::parse($from)->startOfDay();
        $toCarbon = Carbon::parse($to)->endOfDay();

        if ($fromCarbon->gt($toCarbon)) {
            [$fromCarbon, $toCarbon] = [$toCarbon->copy()->startOfDay(), $fromCarbon->copy()->endOfDay()];
            $from = $fromCarbon->toDateString();
            $to = $toCarbon->toDateString();
        }

        $statement = app(ClinicStatementService::class)->statement($fromCarbon, $toCarbon);
        $entryLine = fn ($line) => ['name' => $line->name, 'amount' => (float) $line->amount, 'notes' => $this->exportNote($line->notes)];
        $trackedLine = fn ($line) => ['name' => $line['name'], 'amount' => $line['amount'], 'notes' => 'Expense Tracker'];

        return [
            'from' => $from,
            'to' => $to,
            'generated_at' => now(),
            'generated_by' => optional($request->user())->name ?? 'System',
            'clinicSettings' => Setting::getSettings(),
            'revenue_lines' => $statement['revenue_lines']->map(fn ($line) => ['name' => $line->name, 'amount' => (float) $line->amount]),
            'cost_lines' => $statement['cost_lines']->map(fn ($line) => ['name' => $line->name, 'amount' => (float) $line->amount]),
            'operating_lines' => $statement['tracked_operating_lines']->map($trackedLine)->concat($statement['operating_lines']->map($entryLine)),
            'non_operating_lines' => $statement['tracked_non_operating_lines']->map($trackedLine)->concat($statement['non_operating_lines']->map($entryLine)),
            'statement' => $statement,
        ];
    }

    protected function normalizeDate($date, $fallback)
    {
        try {
            return Carbon::parse($date ?: $fallback)->format('Y-m-d');
        } catch (\Exception $e) {
            return Carbon::parse($fallback)->format('Y-m-d');
        }
    }

    protected function exportNote($note)
    {
        return trim((string) $note) === self::DEFAULT_TEMPLATE_NOTE ? null : $note;
    }

}
