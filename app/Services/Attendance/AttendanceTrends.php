<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

/**
 * How attendance has moved month by month: the branch as a whole, each followed person, and
 * which weekdays people are late on. The latest month runs only up to the day given.
 */
class AttendanceTrends
{
    public const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public function __construct(private AttendanceAnalyzer $analyzer) {}

    /** The same, for these people only (a department's, or a head's own): null = everyone. */
    public function only(?array $staffIds): static
    {
        $limited = clone $this;
        $limited->analyzer = $this->analyzer->forStaff($staffIds);

        return $limited;
    }

    /**
     * ['months' => 'Y-m' => [label, expected, present, late, absent, leave, rate (% or null), late_minutes, overtime_minutes],
     *  'people' => [[staff, months => 'Y-m' => [late, absent, rate], late, absent, late_minutes, change]], most trouble first,
     *  'weekdays' => ISO weekday => [late, present]].
     * A person's 'change' is their late arrivals and absences in the latest month less the month before.
     */
    public function months(CarbonImmutable $until, int $count = 6): array
    {
        $until = $until->startOfDay();
        $months = [];
        $people = [];
        $weekdays = array_fill_keys(array_keys(self::WEEKDAYS), ['late' => 0, 'present' => 0]);

        for ($i = $count - 1; $i >= 0; $i--) {
            $first = $until->startOfMonth()->subMonthsNoOverflow($i);
            $report = $this->analyzer->range($first, $i === 0 ? $until : $first->endOfMonth()->startOfDay());
            $key = $first->format('Y-m');
            $month = ['label' => $first->format('M Y'), 'expected' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'late_minutes' => 0, 'overtime_minutes' => 0];

            foreach ($report['rows'] as $row) {
                if (! $row['staff']->is_tracked) {
                    continue;
                }
                $t = $row['totals'];
                foreach (['expected', 'present', 'late', 'absent', 'leave', 'late_minutes', 'overtime_minutes'] as $sum) {
                    $month[$sum] += $t[$sum];
                }
                $id = $row['staff']->id;
                $people[$id] ??= ['staff' => $row['staff'], 'months' => [], 'late' => 0, 'absent' => 0, 'late_minutes' => 0];
                $people[$id]['months'][$key] = ['late' => $t['late'], 'absent' => $t['absent'], 'rate' => $t['expected'] ? (int) round($t['present'] / $t['expected'] * 100) : null];
                $people[$id]['late'] += $t['late'];
                $people[$id]['absent'] += $t['absent'];
                $people[$id]['late_minutes'] += $t['late_minutes'];
                foreach ($row['days'] as $date => $day) {
                    if (in_array($day['status'], ['on_time', 'late'], true)) {
                        $weekday = $report['days'][$date]->dayOfWeekIso;
                        $weekdays[$weekday]['present']++;
                        $weekdays[$weekday]['late'] += (int) ($day['status'] === 'late');
                    }
                }
            }
            $month['rate'] = $month['expected'] ? (int) round($month['present'] / $month['expected'] * 100) : null;
            $months[$key] = $month;
        }

        $keys = array_keys($months);
        [$latest, $previous] = [$keys[count($keys) - 1], $keys[count($keys) - 2] ?? null];
        foreach ($people as &$person) {
            $now = $person['months'][$latest] ?? ['late' => 0, 'absent' => 0];
            $before = $previous ? ($person['months'][$previous] ?? ['late' => 0, 'absent' => 0]) : ['late' => 0, 'absent' => 0];
            $person['change'] = ($now['late'] + $now['absent']) - ($before['late'] + $before['absent']);
        }
        unset($person);
        $people = array_values($people);
        usort($people, fn ($a, $b) => [$b['late'] + $b['absent'], $a['staff']->name] <=> [$a['late'] + $a['absent'], $b['staff']->name]);

        return ['months' => $months, 'people' => $people, 'weekdays' => $weekdays];
    }
}
