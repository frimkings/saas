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

    /** How long a finished month's figures are kept before being worked out again. */
    public const KEEP_MINUTES = 10;

    private ?array $only = null;

    public function __construct(private AttendanceAnalyzer $analyzer) {}

    /**
     * One month's figures: the branch's totals, each followed person's, and lateness by weekday.
     * A month already over is kept for a few minutes, as six months for a large staff takes time;
     * a correction to an old punch shows here once that has passed.
     */
    private function month(CarbonImmutable $first, CarbonImmutable $last, bool $over): array
    {
        $work = function () use ($first, $last) {
            $report = $this->analyzer->range($first, $last);
            $part = ['totals' => array_fill_keys(['expected', 'present', 'late', 'absent', 'leave', 'late_minutes', 'overtime_minutes'], 0), 'people' => [],
                'weekdays' => array_fill_keys(array_keys(self::WEEKDAYS), ['late' => 0, 'present' => 0])];
            $weekdayOf = array_map(fn ($day) => $day->dayOfWeekIso, $report['days']);
            foreach ($report['rows'] as $row) {
                if (! $row['staff']->is_tracked) {
                    continue;
                }
                $t = $row['totals'];
                foreach (array_keys($part['totals']) as $sum) {
                    $part['totals'][$sum] += $t[$sum];
                }
                $part['people'][$row['staff']->id] = ['late' => $t['late'], 'absent' => $t['absent'], 'late_minutes' => $t['late_minutes'],
                    'rate' => $t['expected'] ? (int) round($t['present'] / $t['expected'] * 100) : null];
                foreach ($row['days'] as $date => $day) {
                    if ($day['status'] === 'on_time' || $day['status'] === 'late') {
                        $part['weekdays'][$weekdayOf[$date]]['present']++;
                        $part['weekdays'][$weekdayOf[$date]]['late'] += (int) ($day['status'] === 'late');
                    }
                }
            }

            return $part;
        };
        if (! $over) {
            return $work();
        }
        $key = 'attendance.trends.'.(app(\App\Support\Tenancy\TenantContext::class)->branchId() ?? 0).'.'.$first->format('Y-m').'.'.md5(json_encode($this->only));

        return \Illuminate\Support\Facades\Cache::remember($key, now()->addMinutes(self::KEEP_MINUTES), $work);
    }

    /** The same, for these people only (a department's, or a head's own): null = everyone. */
    public function only(?array $staffIds): static
    {
        $limited = clone $this;
        $limited->analyzer = $this->analyzer->forStaff($staffIds);
        $limited->only = $staffIds;

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

        // The people as they are now, in name order; a month's figures are held by their id.
        $staff = \App\Models\AttendanceStaff::query()->where('is_tracked', true)->when($this->only !== null, fn ($query) => $query->whereIn('id', $this->only))
            ->orderBy('name')->get()->keyBy('id');
        $thisMonth = CarbonImmutable::now($until->getTimezone())->format('Y-m');
        for ($i = $count - 1; $i >= 0; $i--) {
            $first = $until->startOfMonth()->subMonthsNoOverflow($i);
            $key = $first->format('Y-m');
            $part = $this->month($first, $i === 0 ? $until : $first->endOfMonth()->startOfDay(), $i > 0 && $key < $thisMonth);
            foreach ($part['people'] as $id => $figures) {
                if (! isset($staff[$id])) {
                    continue;
                }
                $people[$id] ??= ['staff' => $staff[$id], 'months' => [], 'late' => 0, 'absent' => 0, 'late_minutes' => 0];
                $people[$id]['months'][$key] = ['late' => $figures['late'], 'absent' => $figures['absent'], 'rate' => $figures['rate']];
                $people[$id]['late'] += $figures['late'];
                $people[$id]['absent'] += $figures['absent'];
                $people[$id]['late_minutes'] += $figures['late_minutes'];
            }
            foreach ($part['weekdays'] as $weekday => $counted) {
                $weekdays[$weekday]['present'] += $counted['present'];
                $weekdays[$weekday]['late'] += $counted['late'];
            }
            $months[$key] = ['label' => $first->format('M Y')] + $part['totals']
                + ['rate' => $part['totals']['expected'] ? (int) round($part['totals']['present'] / $part['totals']['expected'] * 100) : null];
        }
        // Name order, as before, for people level on late arrivals and absences.
        $people = array_replace(array_intersect_key($staff->all(), $people), $people);

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
