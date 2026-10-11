<?php

namespace App\Services\Attendance;

use App\Models\Setting;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The month's attendance sheet as a file: a CSV that opens in Excel, or a PDF to print. Staff not followed are left out. */
class AttendanceExporter
{
    public function __construct(private readonly AttendanceAnalyzer $analyzer)
    {
    }

    public function csv(CarbonImmutable $month): StreamedResponse
    {
        $report = $this->analyzer->month($month);
        $rows = $this->tracked($report);

        return response()->streamDownload(function () use ($report, $rows, $month) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads names with accents correctly
            fputcsv($out, ['Attendance', $month->format('F Y'), $this->place()]);
            fputcsv($out, ['Recorded up to', $report['covered_until'] ? CarbonImmutable::parse($report['covered_until'])->format('j M Y') : 'Nothing received']);
            fputcsv($out, []);

            fputcsv($out, ['SUMMARY']);
            fputcsv($out, ['Employee ID', 'Name', 'Department', 'Days expected', 'Present', 'On time', 'Late', 'Minutes late', 'Absent', 'On leave',
                'Left early', 'No clock-out', 'Worked on days off', 'Usual arrival', 'Hours on site']);
            foreach ($rows as $row) {
                $t = $row['totals'];
                fputcsv($out, [$this->text($row['staff']->employee_no), $row['staff']->name, $row['staff']->department, $t['expected'], $t['present'], $t['on_time'], $t['late'],
                    $t['late_minutes'], $t['absent'], $t['leave'], $t['left_early'], $t['no_clock_out'], $t['worked_off'], AttendanceAnalyzer::time($t['average_arrival']),
                    round($t['minutes_worked'] / 60, 2)]);
            }
            fputcsv($out, []);

            fputcsv($out, ['DAILY']);
            fputcsv($out, ['Employee ID', 'Name', 'Date', 'Day', 'In', 'Out', 'Hours on site', 'Status', 'Minutes late', 'Left early (minutes)', 'No clock-out', 'Note']);
            foreach ($rows as $row) {
                foreach ($row['days'] as $key => $day) {
                    if (in_array($day['status'], ['none', 'off'], true)) {
                        continue;
                    }
                    fputcsv($out, [$this->text($row['staff']->employee_no), $row['staff']->name, $key, $report['days'][$key]->format('D'), AttendanceAnalyzer::time($day['in']), AttendanceAnalyzer::time($day['out']),
                        $day['minutes_worked'] !== null ? round($day['minutes_worked'] / 60, 2) : null, AttendanceAnalyzer::STATUSES[$day['status']],
                        $day['late_minutes'] ?: null, $day['early_minutes'] ?: null, $day['no_clock_out'] ? 'Yes' : null, $day['note']]);
                }
            }
            fclose($out);
        }, 'attendance-'.$month->format('Y-m').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function pdf(CarbonImmutable $month): StreamedResponse
    {
        $report = $this->analyzer->month($month);
        $pdf = Pdf::loadView('pdf.attendance-sheet', [
            'month' => $month,
            'report' => $report,
            'rows' => $this->tracked($report),
            'place' => $this->place(),
            'appSettings' => Setting::getSettings(),
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(fn () => print($pdf->output()), 'attendance-'.$month->format('Y-m').'.pdf', ['Content-Type' => 'application/pdf']);
    }

    private function tracked(array $report): array
    {
        return array_values(array_filter($report['rows'], fn ($row) => $row['staff']->is_tracked));
    }

    private function place(): string
    {
        $context = app(TenantContext::class);

        return trim(($context->clinic()?->name ?? '').' · '.($context->branch()?->name ?? ''), ' ·');
    }

    /** Keeps "001" as text when the file is opened in Excel. */
    private function text(string $value): string
    {
        return ctype_digit($value) && str_starts_with($value, '0') ? '="'.$value.'"' : $value;
    }
}
