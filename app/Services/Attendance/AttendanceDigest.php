<?php

namespace App\Services\Attendance;

use App\Models\AttendanceStaff;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The attendance block of the owner's daily, weekly and monthly emails (OwnerSummaryService), for
 * the current branch. The daily one names only the exceptions; the weekly and monthly ones list
 * everyone followed and name who lost the most time to lateness. Null when there is nothing to say:
 * no terminal staff, or a day nobody was expected in.
 */
class AttendanceDigest
{
    private const MOST_LATE_SHOWN = 3;

    public function __construct(private readonly AttendanceAnalyzer $analyzer)
    {
    }

    public function block(string $period, CarbonInterface $from, CarbonInterface $to, CarbonInterface $previousFrom, CarbonInterface $previousTo): ?array
    {
        if (! AttendanceStaff::query()->where('is_tracked', true)->exists()) {
            return null;
        }

        $day = fn (CarbonInterface $date) => CarbonImmutable::parse($date->toDateString());
        $report = $this->analyzer->range($day($from), $day($to));
        $rows = array_values(array_filter($report['rows'], fn ($row) => $row['staff']->is_tracked));
        $block = ['period' => $period, 'note' => null, 'headline' => null, 'rate' => null, 'previousRate' => null,
            'exceptions' => [], 'rows' => [], 'mostLate' => null, 'perfect' => []];

        if (! $report['covered_until']) {
            $block['note'] = 'No attendance was received for this period. Check that the terminal is connected, or upload its report.';

            return $block;
        }

        $totals = $report['totals'];
        $block['rate'] = $totals['expected'] ? round($totals['present'] / $totals['expected'] * 100, 1) : null;
        $lastDay = $day($to)->format('Y-m-d');
        if ($report['covered_until'] < $lastDay) {
            $block['note'] = 'Recorded up to '.CarbonImmutable::parse($report['covered_until'])->format('D j M').'; later days are not counted.';
        } elseif (($report['coverage'][$lastDay] ?? null) === 'partial') {
            $block['note'] = 'The last day may be incomplete: nothing has arrived from the terminal since.';
        }

        if ($period === 'daily') {
            $key = $day($from)->format('Y-m-d');
            $onLeave = count(array_filter($rows, fn ($row) => $row['days'][$key]['status'] === 'leave'));
            if (! $totals['expected'] && ! $onLeave) {
                return null; // a day off or a holiday
            }
            $block['headline'] = $totals['present'].' of '.$totals['expected'].' staff present'.($onLeave ? ', '.$onLeave.' on leave' : '');
            foreach ($rows as $row) {
                if ($exception = $this->exception($row['days'][$key])) {
                    $block['exceptions'][] = ['name' => $row['staff']->name] + $exception;
                }
            }

            return $block;
        }

        if (! $totals['expected'] && ! $totals['leave']) {
            return null;
        }
        $previous = $this->analyzer->range($day($previousFrom), $day($previousTo))['totals'];
        $block['previousRate'] = $previous['expected'] ? round($previous['present'] / $previous['expected'] * 100, 1) : null;
        $block['headline'] = $totals['present'].' of '.$totals['expected'].' working days attended';

        foreach ($rows as $row) {
            $t = $row['totals'];
            $block['rows'][] = [
                'name' => $row['staff']->name,
                'present' => $t['present'], 'expected' => $t['expected'],
                'late' => $t['late'], 'late_time' => $t['late_minutes'] ? AttendanceAnalyzer::duration($t['late_minutes']) : null,
                'absent' => $t['absent'], 'leave' => $t['leave'], 'long_breaks' => $t['long_breaks'],
                'hours' => $t['minutes_worked'] ? AttendanceAnalyzer::duration($t['minutes_worked']) : null,
            ];
            if ($period === 'monthly' && $t['expected'] > 0 && $t['present'] === $t['expected'] && $t['late'] === 0) {
                $block['perfect'][] = $row['staff']->name;
            }
        }

        $late = array_filter($rows, fn ($row) => $row['totals']['late'] > 0);
        usort($late, fn ($a, $b) => $b['totals']['late_minutes'] <=> $a['totals']['late_minutes']);
        if ($late) {
            $block['mostLate'] = implode(', ', array_map(
                fn ($row) => $row['staff']->name.' ('.AttendanceAnalyzer::duration($row['totals']['late_minutes']).' over '
                    .$row['totals']['late'].($row['totals']['late'] === 1 ? ' day' : ' days').')',
                array_slice($late, 0, self::MOST_LATE_SHOWN),
            ));
        }

        return $block;
    }

    /** What was out of the ordinary about one person's day: ['detail', 'tone' => 'absent' | 'late' | 'note'], or null. */
    private function exception(array $day): ?array
    {
        $parts = [];
        $tone = 'note';
        if ($day['status'] === 'absent') {
            return ['detail' => 'Absent', 'tone' => 'absent'];
        }
        if ($day['status'] === 'leave') {
            return ['detail' => 'On leave'.($day['note'] ? ' ('.$day['note'].')' : ''), 'tone' => 'note'];
        }
        if ($day['status'] === 'late') {
            $parts[] = 'In at '.AttendanceAnalyzer::time($day['in']).', '.AttendanceAnalyzer::duration($day['late_minutes']).' late';
            $tone = 'late';
        }
        if ($day['left_early']) {
            $parts[] = 'left '.AttendanceAnalyzer::duration($day['early_minutes']).' early ('.AttendanceAnalyzer::time($day['out']).')';
        }
        if ($day['long_break']) {
            $parts[] = 'out for '.AttendanceAnalyzer::duration($day['break_minutes']).' in breaks';
        }
        if ($day['no_clock_out']) {
            $parts[] = 'a punch is missing';
        }

        return $parts ? ['detail' => ucfirst(implode('; ', $parts)), 'tone' => $tone] : null;
    }
}
