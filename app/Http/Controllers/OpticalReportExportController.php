<?php

namespace App\Http\Controllers;

use App\Services\Finance\StatementExporter;
use App\Services\OpticalReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** The printable optical reports: end of day takings, sales by category and aged receivables. */
class OpticalReportExportController extends Controller
{
    public const REPORTS = [
        'end-of-day' => 'End of day takings',
        'sales' => 'Sales by category',
        'owed' => 'Aged receivables',
    ];

    public function __invoke(Request $request, string $report, string $format, OpticalReportService $service, StatementExporter $exporter)
    {
        $data = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $from = Carbon::parse($data['from'] ?? today()->toDateString());
        $to = Carbon::parse($data['to'] ?? today()->toDateString());
        if ($from->gt($to)) [$from, $to] = [$to, $from];

        [$rows, $notes] = match ($report) {
            'end-of-day' => [$service->endOfDayRows($from, $to), ['Money counts on the day it was received, whenever the job was ordered. Refunds count on the day they were paid out.']],
            'sales' => [$service->salesRows($from, $to), ['Jobs count on the day they were ordered; cancelled jobs and quotations are left out. Fees kept on cancelled jobs count on the day of cancellation.']],
            'owed' => [$service->owedRows(), ['Every job with a balance to pay today, by how long ago it was ordered. Partner clinic jobs are included; their statements show payments on account.']],
        };
        // Aged receivables is a picture of today, not of a date range.
        if ($report === 'owed') $from = $to = today();

        $document = [
            'title' => 'Optical '.self::REPORTS[$report].($report === 'owed' ? ' as of '.today()->format('M j, Y') : ''),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'generated_at' => now(),
            'generated_by' => $request->user()?->name ?? 'System',
            'status' => null,
            'rows' => $rows,
            'notes' => $notes,
        ];
        $filename = 'optical-'.$report.'-'.($report === 'owed' ? today()->toDateString() : $from->toDateString().'-to-'.$to->toDateString());

        return match ($format) {
            'pdf' => $exporter->pdf($document, $filename),
            'csv' => $exporter->csv($document, $filename),
            default => $exporter->preview($document),
        };
    }
}
