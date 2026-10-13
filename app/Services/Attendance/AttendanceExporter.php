<?php

namespace App\Services\Attendance;

use App\Models\Setting;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The attendance sheet for a run of days as a file: a CSV that opens in Excel, or a PDF to print. Staff not followed are left out. */
class AttendanceExporter
{
    public function __construct(private readonly AttendanceAnalyzer $analyzer)
    {
    }

    public function csv(CarbonImmutable $first, CarbonImmutable $last): StreamedResponse
    {
        $report = $this->analyzer->range($first, $last);
        $label = AttendancePeriod::label($first, $last);
        $rows = $this->tracked($report);

        return response()->streamDownload(function () use ($report, $rows, $label) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads names with accents correctly
            fputcsv($out, ['Attendance', $label, $this->place()]);
            fputcsv($out, ['Recorded up to', $report['covered_until'] ? CarbonImmutable::parse($report['covered_until'])->format('j M Y') : 'Nothing received']);
            fputcsv($out, []);

            fputcsv($out, ['SUMMARY']);
            fputcsv($out, ['Employee ID', 'Name', 'Department', 'Days expected', 'Present', 'On time', 'Late', 'Minutes late', 'Absent', 'On leave',
                'Left early', 'Missing punch', 'Worked on days off', 'Usual arrival', 'Hours on site', 'Long breaks', 'Hours on break', 'Overtime hours (approved)', 'Overtime hours (waiting)']);
            foreach ($rows as $row) {
                $t = $row['totals'];
                fputcsv($out, [$this->text($row['staff']->employee_no), $row['staff']->name, $row['staff']->department, $t['expected'], $t['present'], $t['on_time'], $t['late'],
                    $t['late_minutes'], $t['absent'], $t['leave'], $t['left_early'], $t['no_clock_out'], $t['worked_off'], AttendanceAnalyzer::time($t['average_arrival']),
                    round($t['minutes_worked'] / 60, 2), $t['long_breaks'], round($t['break_minutes'] / 60, 2),
                    round($t['overtime_minutes'] / 60, 2), round($t['overtime_pending'] / 60, 2)]);
            }
            fputcsv($out, []);

            fputcsv($out, ['DAILY']);
            fputcsv($out, ['Employee ID', 'Name', 'Date', 'Day', 'In', 'Out', 'Hours on site', 'Status', 'Minutes late', 'Left early (minutes)', 'Missing punch', 'Note', 'Break (minutes)', 'Long break', 'All punches', 'Overtime (minutes)', 'Overtime approved (minutes)']);
            foreach ($rows as $row) {
                foreach ($row['days'] as $key => $day) {
                    if (in_array($day['status'], ['none', 'off'], true)) {
                        continue;
                    }
                    fputcsv($out, [$this->text($row['staff']->employee_no), $row['staff']->name, $key, $report['days'][$key]->format('D'), AttendanceAnalyzer::time($day['in']), AttendanceAnalyzer::time($day['out']),
                        $day['minutes_worked'] !== null ? round($day['minutes_worked'] / 60, 2) : null, AttendanceAnalyzer::STATUSES[$day['status']],
                        $day['late_minutes'] ?: null, $day['early_minutes'] ?: null, $day['no_clock_out'] ? 'Yes' : null, $day['note'],
                        $day['break_minutes'] ?: null, $day['long_break'] ? 'Yes' : null,
                        implode(' ', array_map(fn ($punch) => ($punch['kind'] === 'in' ? 'In ' : 'Out ').AttendanceAnalyzer::time($punch['time']).';', $day['punches'])),
                        $day['overtime_minutes'] ?: null, $day['overtime_minutes'] && $day['overtime_status'] !== 'pending' ? $day['overtime_approved'] : null]);
                }
            }
            fclose($out);
        }, $this->fileName($first, $last).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function pdf(CarbonImmutable $first, CarbonImmutable $last): StreamedResponse
    {
        $report = $this->analyzer->range($first, $last);
        $pdf = Pdf::loadView('pdf.attendance-sheet', [
            'label' => AttendancePeriod::label($first, $last),
            // A landscape page holds a month of columns.
            'withGrid' => AttendancePeriod::days($first, $last) <= 31,
            'report' => $report,
            'rows' => $this->tracked($report),
            'place' => $this->place(),
            'appSettings' => Setting::getSettings(),
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(fn () => print($pdf->output()), $this->fileName($first, $last).'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** 'attendance-2026-10' for a whole month, otherwise 'attendance-2026-10-01-to-2026-10-12'. */
    private function fileName(CarbonImmutable $first, CarbonImmutable $last): string
    {
        $wholeMonth = $first->day === 1 && $last->isSameDay($first->endOfMonth());

        return 'attendance-'.($wholeMonth ? $first->format('Y-m') : $first->format('Y-m-d').($first->isSameDay($last) ? '' : '-to-'.$last->format('Y-m-d')));
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
