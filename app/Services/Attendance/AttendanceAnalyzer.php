<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDepartment;
use App\Models\AttendanceDevice;
use App\Models\AttendanceHoliday;
use App\Models\AttendanceImport;
use App\Models\AttendanceLeave;
use App\Models\AttendanceLeaveType;
use App\Models\AttendanceOvertime;
use App\Models\AttendancePunch;
use App\Models\AttendanceRoster;
use App\Models\AttendanceSetting;
use App\Models\AttendanceShift;
use App\Models\AttendanceStaff;
use Carbon\CarbonImmutable;

/**
 * Works out a month of attendance for the current branch from its punches and working hours.
 * Every punch counts: in order they are taken as in, out, in, out…, so time on site is the sum of
 * the stretches between and the gaps are breaks. A day is only judged when
 * an uploaded report or a connected terminal covers it: later days are unknown, not absences, and
 * the latest day heard of is still in progress, so nobody is absent or gone early on it yet. Holidays and leave are
 * days off for those they cover; staff with hours of their own are judged against those.
 */
class AttendanceAnalyzer
{
    /** Staff ids to report on; null = everyone at the branch. */
    private ?array $only = null;

    /** The branch's departments by id, read at the start of each report. */
    private $departments = null;

    /** The same analyzer limited to these people: a department's, for its head. Null = everyone. */
    public function forStaff(?array $staffIds): static
    {
        $limited = clone $this;
        $limited->only = $staffIds;

        return $limited;
    }

    /** A rule for one person: their department's if it sets one, else the branch's. */
    private function rule(AttendanceStaff $staff, string $rule, AttendanceSetting $settings)
    {
        return $this->departments?->get($staff->attendance_department_id)?->{$rule} ?? $settings->{$rule};
    }

    /** Punches this close together are one punch: the terminal recognising the same person twice. */
    public const SAME_PUNCH_MINUTES = 2;

    public const STATUSES = [
        'on_time' => 'On time',
        'late' => 'Late',
        'absent' => 'Absent',
        'off' => 'Day off',
        'holiday' => 'Holiday',
        'leave' => 'On leave',
        'worked_off' => 'Worked on a day off',
        'none' => 'No data',
    ];

    public function month(CarbonImmutable $month): array
    {
        return $this->range($month->startOfMonth(), $month->endOfMonth());
    }

    /** The same for any run of days, first to last inclusive: a week for the owner's weekly email, say. */
    public function range(CarbonImmutable $first, CarbonImmutable $last, ?string $assume = null): array
    {
        // $assume: how to treat days nothing has been received for ('partial' for today's live view).
        $first = $first->startOfDay();
        $last = $last->startOfDay();
        $settings = AttendanceSetting::current();
        $this->departments = AttendanceDepartment::query()->get()->keyBy('id');

        $days = [];
        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $days[$day->format('Y-m-d')] = $day;
        }
        $coverage = $this->coverage($first, $last);
        // Attendance is counted from the first day anyone punched at this branch.
        $firstPunch = AttendancePunch::query()->min('punched_at');
        $countFrom = $firstPunch ? CarbonImmutable::parse($firstPunch)->format('Y-m-d') : null;

        // A day either side as well: a night shift's punches after midnight count on the day it began.
        $before = $first->subDay();
        $punches = [];
        AttendancePunch::query()
            ->whereBetween('punched_at', [$before->format('Y-m-d 00:00:00'), $last->addDay()->format('Y-m-d 23:59:59')])
            ->when($this->only !== null, fn ($query) => $query->whereIn('attendance_staff_id', $this->only))
            ->orderBy('punched_at')->toBase()->select(['attendance_staff_id', 'punched_at'])->cursor()
            ->each(function ($punch) use (&$punches) {
                $punches[$punch->attendance_staff_id][substr($punch->punched_at, 0, 10)][] = substr($punch->punched_at, 11, 5);
            });

        $shifts = AttendanceShift::query()->get()->keyBy('id');
        $roster = [];
        // Thousands of rows at a hospital: plain rows, no models or date objects.
        AttendanceRoster::query()->whereBetween('date', [$before->format('Y-m-d'), $last->format('Y-m-d')])
            ->when($this->only !== null, fn ($query) => $query->whereIn('attendance_staff_id', $this->only))
            ->toBase()->select(['attendance_staff_id', 'date', 'attendance_shift_id'])->cursor()
            ->each(function ($entry) use (&$roster) {
                $roster[$entry->attendance_staff_id][substr($entry->date, 0, 10)] = (int) ($entry->attendance_shift_id ?? 0);
            });

        $decided = [];
        AttendanceOvertime::query()->whereBetween('date', [$first->format('Y-m-d'), $last->format('Y-m-d')])->get()
            ->each(function ($entry) use (&$decided) {
                $decided[$entry->attendance_staff_id][$entry->date->format('Y-m-d')] = $entry;
            });

        $holidays = AttendanceHoliday::query()
            ->whereBetween('date', [$first->format('Y-m-d'), $last->format('Y-m-d')])->orderBy('date')->get()
            ->mapWithKeys(fn ($holiday) => [$holiday->date->format('Y-m-d') => $holiday->name])->all();
        $leave = [];
        $leaveTypes = [];
        $away = [];
        $unpaidTypes = AttendanceLeaveType::options()->filter(fn ($type) => ! $type->is_paid)->keys()->all();
        AttendanceLeave::query()->approved()
            ->where('starts_on', '<=', $last->format('Y-m-d'))->where('ends_on', '>=', $first->format('Y-m-d'))->get()
            ->each(function ($entry) use (&$leave, &$leaveTypes, &$away, $days, $unpaidTypes) {
                [$starts, $ends, $typeName] = [$entry->starts_on->format('Y-m-d'), $entry->ends_on->format('Y-m-d'), $entry->typeName()];
                if ($entry->isPartDay()) {
                    $away[$entry->attendance_staff_id][$starts][] = ['from' => $entry->from_time, 'to' => $entry->to_time, 'label' => $typeName,
                        'unpaid' => in_array($entry->type, $unpaidTypes, true)];

                    return;
                }
                foreach ($days as $key => $day) {
                    if ($key >= $starts && $key <= $ends) {
                        $leave[$entry->attendance_staff_id][$key] = $typeName;
                        $leaveTypes[$entry->attendance_staff_id][$key] = $entry->type;
                    }
                }
            });

        // With hundreds of people this loop runs tens of thousands of times, so whatever does not
        // change from day to day (weekdays, each shift's hours, each person's rules) is worked out once.
        $calendar = [];
        foreach ([$before->format('Y-m-d') => $before] + $days as $key => $day) {
            $calendar[$key] = [$day->dayOfWeekIso, $day->addDay()->format('Y-m-d')];
        }
        $shiftHours = $this->shiftHours($shifts);
        $needsApproval = (bool) ($settings->overtime_needs_approval ?? true);

        $rows = [];
        foreach (AttendanceStaff::query()->when($this->only !== null, fn ($query) => $query->whereIn('id', $this->only))->with('unit:id,name')->orderByDesc('is_tracked')->orderBy('name')->get() as $staff) {
            $staffDays = [];
            $from = max($countFrom ?? '9999-12-31', $staff->starts_on?->format('Y-m-d') ?? '');
            $person = $this->personRules($staff, $settings);
            $rostered = $roster[$staff->id] ?? [];
            $onLeave = $leave[$staff->id] ?? [];
            $partDays = $away[$staff->id] ?? [];
            $answers = $decided[$staff->id] ?? [];
            // The hours expected of them each day, from the day before the range so a night shift
            // that began then keeps its morning punches; then those punches move to its day.
            $expected = [];
            $mine = $punches[$staff->id] ?? [];
            foreach ($calendar as $key => [$weekday, $next]) {
                $hours = $expected[$key] = $this->hoursOn($person, $shiftHours, $rostered[$key] ?? null, $weekday);
                if ($hours && isset($mine[$next]) && $this->minutes($hours['end']) >= 1440) {
                    $mine = $this->withNightPunches($mine, $key, $next, $hours);
                }
            }
            foreach ($days as $key => $day) {
                $state = $key < $from ? null : ($coverage[$key] ?? $assume);
                $hours = $expected[$key];
                $excused = isset($holidays[$key]) ? 'holiday' : (isset($onLeave[$key]) ? 'leave' : null);
                $note = $holidays[$key] ?? $onLeave[$key] ?? null;
                $break = $hours['break'] ?? $person['break_minutes'] ?? 60;
                // For pay: the length of the working day, the kind of whole-day leave, and unpaid leave for part of the day.
                $forPay = ['scheduled_minutes' => $hours ? $this->minutes($hours['end']) - $this->minutes($hours['start']) : null,
                    'leave_type' => $excused === 'leave' ? $leaveTypes[$staff->id][$key] : null, 'unpaid_minutes' => 0];
                // Leave for part of the day: they are expected for the rest of it.
                if ($hours && ! $excused && $part = $partDays[$key] ?? null) {
                    foreach ($part as $time) {
                        $forPay['unpaid_minutes'] += $time['unpaid'] ? max(0, min($this->minutes($time['to']), $this->minutes($hours['end'])) - max($this->minutes($time['from']), $this->minutes($hours['start']))) : 0;
                    }
                    [$hours, $break, $allDay] = $this->lessTimeAway($hours, $part, $break);
                    $excused = $allDay ? 'leave' : null;
                    $note = implode(', ', array_map(fn ($a) => $a['label'].' '.self::time($a['from']).' to '.self::time($a['to']), $part));
                }
                $staffDays[$key] = $this->day($mine[$key] ?? [], $hours, $person['grace_minutes'], $state, $excused, $break, $person['overtime_after_minutes'] ?? 30)
                    + ['note' => $note, 'hours' => $hours] + $forPay;
                $staffDays[$key] = $this->withOvertimeDecision($staffDays[$key], $answers[$key] ?? null, $needsApproval);
            }
            $rows[] = ['staff' => $staff, 'days' => $staffDays, 'totals' => $this->totals($staffDays)];
        }

        $tracked = array_filter($rows, fn ($row) => $row['staff']->is_tracked);
        $sum = fn (string $key) => array_sum(array_map(fn ($row) => $row['totals'][$key], $tracked));
        $coveredDays = array_keys($coverage);

        return [
            'days' => $days,
            'coverage' => $coverage,
            'covered_until' => $coveredDays ? max($coveredDays) : null,
            'settings' => $settings,
            'holidays' => $holidays,
            'rows' => $rows,
            'totals' => [
                'staff' => count($tracked),
                'late' => $sum('late'),
                'absent' => $sum('absent'),
                'leave' => $sum('leave'),
                'present' => $sum('present'),
                'expected' => $sum('expected'),
            ],
        ];
    }

    /**
     * What counts of a day's overtime. 'overtime_minutes' is what was worked past the end of the
     * day; 'overtime_approved' what counts, and 'overtime_status' is 'pending' while a manager
     * has still to answer, else 'approved' or 'declined'. Where the branch doesn't ask for
     * approval it all counts unless a manager has said otherwise.
     */
    private function withOvertimeDecision(array $day, ?AttendanceOvertime $decision, bool $needsApproval): array
    {
        $day += ['overtime_status' => null, 'overtime_approved' => 0];
        if (! $day['overtime_minutes']) {
            return $day;
        }
        if ($decision) {
            $day['overtime_status'] = $decision->status;
            $day['overtime_approved'] = $decision->status === AttendanceOvertime::APPROVED ? min($decision->minutes, $day['overtime_minutes']) : 0;
        } elseif ($needsApproval) {
            $day['overtime_status'] = 'pending';
        } else {
            $day['overtime_status'] = AttendanceOvertime::APPROVED;
            $day['overtime_approved'] = $day['overtime_minutes'];
        }

        return $day;
    }

    /** How long after a night shift ends a punch still belongs to it (a late clock-out). */
    public const NIGHT_SHIFT_TAIL_MINUTES = 180;

    /** 'Y-m-d' => the hours expected of one person (null on a day off), for each day first to last. */
    public function schedule(AttendanceStaff $staff, CarbonImmutable $first, CarbonImmutable $last): array
    {
        $settings = AttendanceSetting::current();
        $this->departments = AttendanceDepartment::query()->get()->keyBy('id');
        $shifts = AttendanceShift::query()->get()->keyBy('id');
        $roster = AttendanceRoster::query()->where('attendance_staff_id', $staff->id)->whereBetween('date', [$first->format('Y-m-d'), $last->format('Y-m-d')])->get()
            ->mapWithKeys(fn ($entry) => [$entry->date->format('Y-m-d') => $entry->attendance_shift_id ?? 0])->all();
        $person = $this->personRules($staff, $settings);
        $shiftHours = $this->shiftHours($shifts);
        $schedule = [];
        for ($day = $first->startOfDay(); $day->lte($last); $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $schedule[$key] = $this->hoursOn($person, $shiftHours, $roster[$key] ?? null, $day->dayOfWeekIso);
        }

        return $schedule;
    }

    /** The same for many people at once, with one look at the roster: staff id => 'Y-m-d' => hours or null. */
    public function schedules(iterable $staff, CarbonImmutable $first, CarbonImmutable $last): array
    {
        $settings = AttendanceSetting::current();
        $this->departments = AttendanceDepartment::query()->get()->keyBy('id');
        $shiftHours = $this->shiftHours(AttendanceShift::query()->get()->keyBy('id'));
        $people = collect($staff)->keyBy('id');
        $roster = [];
        AttendanceRoster::query()->whereIn('attendance_staff_id', $people->keys())->whereBetween('date', [$first->format('Y-m-d'), $last->format('Y-m-d')])
            ->toBase()->select(['attendance_staff_id', 'date', 'attendance_shift_id'])->cursor()
            ->each(function ($entry) use (&$roster) {
                $roster[$entry->attendance_staff_id][substr($entry->date, 0, 10)] = (int) ($entry->attendance_shift_id ?? 0);
            });
        $calendar = [];
        for ($day = $first->startOfDay(); $day->lte($last); $day = $day->addDay()) {
            $calendar[$day->format('Y-m-d')] = $day->dayOfWeekIso;
        }
        $schedules = [];
        foreach ($people as $id => $person) {
            $rules = $this->personRules($person, $settings);
            foreach ($calendar as $key => $weekday) {
                $schedules[$id][$key] = $this->hoursOn($rules, $shiftHours, $roster[$id][$key] ?? null, $weekday);
            }
        }

        return $schedules;
    }

    /**
     * The shift someone is on that date by the roster, else their weekly shifts: a shift id, 0 for
     * a day off, or null when they follow usual hours and have no shift.
     */
    public function shiftChoice(AttendanceStaff $staff, CarbonImmutable $day): ?int
    {
        $entry = AttendanceRoster::query()->where('attendance_staff_id', $staff->id)->whereDate('date', $day->format('Y-m-d'))->first();
        if ($entry) {
            return $entry->attendance_shift_id ?? 0;
        }
        $week = $staff->shift_week ?? [];
        $choice = $week[$day->dayOfWeekIso] ?? $week[(string) $day->dayOfWeekIso] ?? null;

        return $choice === null || $choice === '' ? null : (int) $choice;
    }

    /** The hours expected of one person on one date (see hoursFor), for asking outside a report. */
    public function expectedHours(AttendanceStaff $staff, CarbonImmutable $day): ?array
    {
        $entry = AttendanceRoster::query()->where('attendance_staff_id', $staff->id)->whereDate('date', $day->format('Y-m-d'))->first();
        $this->departments = AttendanceDepartment::query()->get()->keyBy('id');

        return $this->hoursFor($staff, $day, AttendanceSetting::current(), AttendanceShift::query()->get()->keyBy('id'),
            $entry ? [$day->format('Y-m-d') => $entry->attendance_shift_id ?? 0] : []);
    }

    /**
     * The hours expected of someone on a day: the roster for that date, else their weekly shift
     * pattern, else their own hours, else their department's, else the branch's. Null is a day off. A shift adds its name
     * and break allowance; a night shift's end runs past midnight ('30:00' = 6 AM next morning).
     *
     * @param  array<string, int>  $roster  'Y-m-d' => shift id, 0 for a day off
     */
    private function hoursFor(AttendanceStaff $staff, CarbonImmutable $day, AttendanceSetting $settings, $shifts, array $roster): ?array
    {
        return $this->hoursOn($this->personRules($staff, $settings), $this->shiftHours($shifts), $roster[$day->format('Y-m-d')] ?? null, $day->dayOfWeekIso);
    }

    /** Each shift as the working hours of a day, by shift id. */
    private function shiftHours($shifts): array
    {
        return $shifts->map(fn (AttendanceShift $shift) => $shift->hours())->all();
    }

    /**
     * What is fixed for one person whatever the day: their weekly shifts, the hours they follow
     * on a day without a shift (their own, else their department's, else the branch's), and the
     * grace, break and overtime rules that apply to them (their department's, else the branch's).
     */
    private function personRules(AttendanceStaff $staff, AttendanceSetting $settings): array
    {
        $department = $this->departments?->get($staff->attendance_department_id);

        return [
            'week' => $staff->shift_week ?? [],
            'hours' => $staff->hours ?: ($department?->hours ?: ($settings->hours ?? [])),
            'grace_minutes' => $department?->grace_minutes ?? $settings->grace_minutes,
            'break_minutes' => $department?->break_minutes ?? $settings->break_minutes,
            'overtime_after_minutes' => $department?->overtime_after_minutes ?? $settings->overtime_after_minutes,
        ];
    }

    /** One day's hours for a person: $rostered is the roster's shift id for the date (0 = day off), or null for none. */
    private function hoursOn(array $person, array $shiftHours, ?int $rostered, int $weekday): ?array
    {
        $choice = $rostered;
        if ($choice === null) {
            $choice = $person['week'][$weekday] ?? null;
            $choice = $choice === null || $choice === '' ? null : (int) $choice;
        }
        if ($choice === 0) {
            return null;
        }

        return $choice !== null && isset($shiftHours[$choice]) ? $shiftHours[$choice] : ($person['hours'][$weekday] ?? null);
    }

    /**
     * On a night shift, the next morning's punches up to a little after the shift ends are moved
     * to the day the shift began, written past midnight ('05:58' becomes '29:58').
     */
    private function withNightPunches(array $punches, string $key, string $next, array $hours): array
    {
        $until = $this->minutes($hours['end']) - 1440 + self::NIGHT_SHIFT_TAIL_MINUTES;
        foreach ($punches[$next] ?? [] as $i => $time) {
            if ($this->minutes($time) <= $until) {
                $punches[$key][] = self::clock($this->minutes($time) + 1440);
                unset($punches[$next][$i]);
            }
        }
        if (isset($punches[$next])) {
            $punches[$next] = array_values($punches[$next]);
        }

        return $punches;
    }

    /**
     * A working day with approved time away taken out of it. Time away at the start of the day
     * moves the start, at the end moves the end, and in between adds to the break allowed.
     * Returns [hours, break allowed, whether nothing of the day is left].
     */
    private function lessTimeAway(array $hours, array $away, int $break): array
    {
        $start = $this->minutes($hours['start']);
        $end = $this->minutes($hours['end']);
        usort($away, fn ($a, $b) => strcmp($a['from'], $b['from']));
        foreach ($away as $time) {
            $from = $this->minutes($time['from']);
            $to = $this->minutes($time['to']);
            if ($from <= $start && $to > $start) {
                $start = min($to, $end);
            } elseif ($to >= $end && $from < $end) {
                $end = max($from, $start);
            } else {
                $break += max(0, min($to, $end) - max($from, $start));
            }
        }

        return [['start' => self::clock($start), 'end' => self::clock($end)] + $hours, $break, $start >= $end];
    }

    /**
     * Where each followed person is right now, for the live board: 'in', 'out' (stepped out, or
     * gone for the day once 'left' is set), 'due' (not in yet, still on time), 'missing' (not in
     * and late), 'leave', 'holiday' or 'off'. $now is the time now where the branch is.
     */
    public function today(CarbonImmutable $now): array
    {
        $key = $now->format('Y-m-d');
        $lastNight = $now->subDay()->format('Y-m-d');
        $now0 = $this->minutes($now->format('H:i'));
        $settings = AttendanceSetting::current();
        $people = [];
        foreach ($this->range($now->subDay(), $now, 'partial')['rows'] as $row) {
            $staff = $row['staff'];
            if (! $staff->is_tracked || ($staff->starts_on && $staff->starts_on->format('Y-m-d') > $key)) {
                continue;
            }
            $day = $row['days'][$key];
            $clock = $now0;
            // Still on the night shift that began yesterday: that is the day they are on.
            $night = $row['days'][$lastNight];
            if (! $day['punches'] && $night['hours'] && $this->minutes($night['hours']['end']) >= 1440
                && $now0 + 1440 <= $this->minutes($night['hours']['end']) + self::NIGHT_SHIFT_TAIL_MINUTES
                && ! in_array($night['status'], ['leave', 'holiday'], true)) {
                $day = $night;
                $clock = $now0 + 1440;
            }
            $hours = $day['hours'];
            $last = $day['punches'] ? end($day['punches']) : null;
            $person = ['staff' => $staff, 'time' => $last['time'] ?? null, 'in' => $day['in'], 'late_minutes' => $day['late_minutes'],
                'note' => $day['note'], 'expected' => $hours['start'] ?? null, 'left' => false, 'shift' => $hours['shift'] ?? null];
            if ($last) {
                $person['state'] = $last['kind'];
                $person['left'] = $last['kind'] === 'out' && (! $hours || $clock >= $this->minutes($hours['end']));
            } elseif (in_array($day['status'], ['leave', 'holiday', 'off'], true)) {
                $person['state'] = $day['status'];
            } else {
                $person['state'] = ! $hours ? 'off' : ($clock > $this->minutes($hours['start']) + $this->rule($row['staff'], 'grace_minutes', $settings) ? 'missing' : 'due');
            }
            $people[] = $person;
        }

        return $people;
    }

    /** ['Y-m-d' => 'complete' | 'partial'] for the days of the range some uploaded report covers. */
    private function coverage(CarbonImmutable $first, CarbonImmutable $last): array
    {
        $coverage = [];
        $imports = AttendanceImport::query()
            ->where('period_start', '<=', $last->format('Y-m-d'))
            ->where('period_end', '>=', $first->format('Y-m-d'))
            ->get(['period_start', 'period_end', 'exported_at']);

        foreach ($imports as $import) {
            $exported = $import->exported_at->format('Y-m-d');
            $from = max($first->format('Y-m-d'), $import->period_start->format('Y-m-d'));
            $to = min($last->format('Y-m-d'), $import->period_end->format('Y-m-d'), $exported);
            for ($day = CarbonImmutable::parse($from); $day->format('Y-m-d') <= $to; $day = $day->addDay()) {
                $key = $day->format('Y-m-d');
                if ($key < $exported) {
                    $coverage[$key] = 'complete';
                } else {
                    $coverage[$key] ??= 'partial';
                }
            }
        }
        // A connected terminal accounts for every day from its first event to its latest.
        $devices = AttendanceDevice::query()->whereNotNull('first_event_at')->whereNotNull('last_event_at')->get(['first_event_at', 'last_event_at']);
        foreach ($devices as $device) {
            $latest = $device->last_event_at->format('Y-m-d');
            $from = max($first->format('Y-m-d'), $device->first_event_at->format('Y-m-d'));
            $to = min($last->format('Y-m-d'), $latest);
            for ($day = CarbonImmutable::parse($from); $day->format('Y-m-d') <= $to; $day = $day->addDay()) {
                $key = $day->format('Y-m-d');
                if ($key < $latest) {
                    $coverage[$key] = 'complete';
                } else {
                    $coverage[$key] ??= 'partial';
                }
            }
        }
        // Phone clock-in accounts for every day since it was switched on, today still in progress.
        $settings = AttendanceSetting::current();
        if ($settings->phone_clock_in && $settings->phone_clock_in_since) {
            $today = CarbonImmutable::now(app(\App\Support\Tenancy\TenantContext::class)->clinic()?->default_timezone ?: config('app.timezone'))->format('Y-m-d');
            $from = max($first->format('Y-m-d'), $settings->phone_clock_in_since->format('Y-m-d'));
            for ($day = CarbonImmutable::parse($from); $day->format('Y-m-d') <= min($last->format('Y-m-d'), $today); $day = $day->addDay()) {
                $key = $day->format('Y-m-d');
                if ($key < $today) {
                    $coverage[$key] = 'complete';
                } else {
                    $coverage[$key] ??= 'partial';
                }
            }
        }
        ksort($coverage);

        return $coverage;
    }

    /**
     * One person's day. $times are that day's punches as 'HH:MM', in order; $hours the working
     * hours or null on a day off; $state 'complete', 'partial' or null when the day is not covered;
     * $excused 'holiday' or 'leave' when a working day is not expected of them; $breakAllowed the
     * minutes they may be out between punches before it is flagged; $overtimeAfter how long past
     * the end of the day they must stay before it is overtime ('overtime_minutes', counted from
     * the end of the day; on a day off or a holiday, all the time on site).
     *
     * 'punches' lists every punch as ['time', 'kind' => 'in' | 'out']. 'out' is the departure: the
     * last punch when they pair up, null when one is missing ('no_clock_out').
     */
    public function day(array $times, ?array $hours, int $grace, ?string $state, ?string $excused = null, int $breakAllowed = 60, int $overtimeAfter = 30): array
    {
        $day = ['status' => 'none', 'in' => null, 'out' => null, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0,
            'left_early' => false, 'no_clock_out' => false, 'minutes_worked' => null, 'in_progress' => $state === 'partial',
            'punches' => [], 'break_minutes' => 0, 'long_break' => false];
        if ($state === null) {
            return $day;
        }

        $times = $this->distinctPunches($times);
        if (! $times) {
            // On the day the report was made, someone not in yet may still come.
            $day['status'] = ! $hours ? 'off' : ($excused ?? ($state === 'complete' ? 'absent' : 'none'));

            return $day;
        }

        $paired = count($times) % 2 === 0;
        $worked = 0;
        foreach ($times as $i => $time) {
            $day['punches'][] = ['time' => $time, 'kind' => $i % 2 === 0 ? 'in' : 'out'];
            if ($i % 2 === 1) {
                $worked += $this->minutes($time) - $this->minutes($times[$i - 1]);
            } elseif ($i > 0) {
                $day['break_minutes'] += $this->minutes($time) - $this->minutes($times[$i - 1]);
            }
        }
        $day['in'] = $times[0];
        $day['out'] = $paired ? end($times) : null;
        $in = $this->minutes($day['in']);
        $out = $day['out'] ? $this->minutes($day['out']) : null;
        $day['minutes_worked'] = count($times) > 1 ? $worked : null;
        $day['long_break'] = $day['break_minutes'] > $breakAllowed;
        if (! $hours || $excused) {
            $day['status'] = 'worked_off';
            $day['overtime_minutes'] = $paired && $worked >= max(1, $overtimeAfter) ? $worked : 0;

            return $day;
        }

        $start = $this->minutes($hours['start']);
        $late = $in > $start + $grace;
        $day['status'] = $late ? 'late' : 'on_time';
        $day['late_minutes'] = $late ? $in - $start : 0;
        $beyond = $out !== null ? $out - $this->minutes($hours['end']) : 0;
        $day['overtime_minutes'] = $beyond >= max(1, $overtimeAfter) ? $beyond : 0;
        if ($state === 'complete') {
            $day['no_clock_out'] = $out === null;
            $end = $this->minutes($hours['end']);
            if ($out !== null && $out < $end) {
                $day['left_early'] = true;
                $day['early_minutes'] = $end - $out;
            }
        }

        return $day;
    }

    /** The day's punches in order, with any within SAME_PUNCH_MINUTES of the one before left out. */
    private function distinctPunches(array $times): array
    {
        $times = array_values(array_unique($times));
        sort($times);
        $kept = [];
        foreach ($times as $time) {
            if (! $kept || $this->minutes($time) - $this->minutes(end($kept)) > self::SAME_PUNCH_MINUTES) {
                $kept[] = $time;
            }
        }

        return $kept;
    }

    private function totals(array $days): array
    {
        $totals = ['on_time' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0, 'worked_off' => 0, 'late_minutes' => 0,
            'left_early' => 0, 'no_clock_out' => 0, 'minutes_worked' => 0, 'long_breaks' => 0, 'break_minutes' => 0,
            'early_minutes' => 0, 'overtime_minutes' => 0, 'overtime_pending' => 0];
        $arrivals = [];
        foreach ($days as $day) {
            if (isset($totals[$day['status']])) {
                $totals[$day['status']]++;
            }
            $totals['late_minutes'] += $day['late_minutes'];
            $totals['left_early'] += (int) $day['left_early'];
            $totals['no_clock_out'] += (int) $day['no_clock_out'];
            $totals['minutes_worked'] += (int) $day['minutes_worked'];
            $totals['long_breaks'] += (int) $day['long_break'];
            $totals['break_minutes'] += $day['break_minutes'];
            $totals['early_minutes'] += $day['early_minutes'];
            // Overtime that counts, and overtime still waiting for a manager's answer.
            $totals['overtime_minutes'] += $day['overtime_approved'] ?? 0;
            $totals['overtime_pending'] += ($day['overtime_status'] ?? null) === 'pending' ? $day['overtime_minutes'] : 0;
            if (in_array($day['status'], ['on_time', 'late'], true)) {
                $arrivals[] = $this->minutes($day['in']);
            }
        }
        $totals['present'] = $totals['on_time'] + $totals['late'];
        // Working days that have been judged: the days this person was expected in.
        $totals['expected'] = $totals['present'] + $totals['absent'];
        $totals['average_arrival'] = $arrivals ? self::clock((int) round(array_sum($arrivals) / count($arrivals))) : null;

        return $totals;
    }

    private function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    /** 485 -> '08:05'. */
    public static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** '17:05' -> '5:05 PM', for showing; times are kept as 24-hour 'HH:MM'. '29:58' (a night shift's next morning) -> '5:58 AM +1'. */
    public static function time(?string $time): ?string
    {
        if (! $time) {
            return null;
        }
        $hour = (int) substr($time, 0, 2);
        $nextDay = $hour >= 24;
        $hour %= 24;

        return sprintf('%d:%s %s%s', $hour % 12 ?: 12, substr($time, 3, 2), $hour < 12 ? 'AM' : 'PM', $nextDay ? ' +1' : '');
    }

    /** 135 -> '2h 15m', 60 -> '1h', 40 -> '40m'. */
    public static function duration(int $minutes): string
    {
        return $minutes >= 60 ? intdiv($minutes, 60).'h'.($minutes % 60 ? ' '.($minutes % 60).'m' : '') : $minutes.'m';
    }
}
