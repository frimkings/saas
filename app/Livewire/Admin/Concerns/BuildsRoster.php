<?php

namespace App\Livewire\Admin\Concerns;

use App\Models\AttendanceSetting;
use App\Models\AttendanceStaff;
use App\Services\Attendance\AttendanceAnalyzer;
use Carbon\CarbonImmutable;

/**
 * The roster: a department's people by day, shift codes, cover, rest warnings, rotation patterns,
 * swaps, and a roster brought in from Excel. Used by the Roster page.
 */
trait BuildsRoster
{
    public int $rosterPage = 1;
    /** A day in the week or month on the roster, 'Y-m-d'. */
    #[\Livewire\Attributes\Url(as: 'date')]
    public string $rosterWeek = '';
    /** 'week' or 'month'. */
    #[\Livewire\Attributes\Url(as: 'span')]
    public string $rosterSpan = 'week';
    /** The department on the roster: an id, 'none', or '' where the branch has no departments. */
    #[\Livewire\Attributes\Url(as: 'department')]
    public string $rosterUnit = '';
    // A roster brought in from Excel: the file, then what it would change, before it is applied.
    public bool $showRosterUpload = false;
    public $rosterFile = null;
    public ?array $rosterPreview = null;

    // Filling the roster from a rotation pattern: the cycle, day by day ('0' = off, else a shift id).
    public bool $showPattern = false;
    public array $patternDays = [];
    public string $patternAddShift = '';
    public string $patternAddCount = '2';
    public string $patternFrom = '';
    public string $patternTo = '';
    public array $patternPeople = [];
    public string $patternStagger = '0';
    public string $patternName = '';
    public string $patternId = '';

    // Swapping two people's shifts over some days.
    public bool $showSwap = false;
    public string $swapA = '';
    public string $swapB = '';
    public string $swapFrom = '';
    public string $swapTo = '';

    public function shiftRosterWeek(int $by): void
    {
        $anchor = CarbonImmutable::parse($this->rosterWeek ?: 'today');
        $this->rosterWeek = ($this->rosterSpan === 'month' ? $anchor->startOfMonth()->addMonthsNoOverflow($by < 0 ? -1 : 1) : $anchor->addWeeks($by < 0 ? -1 : 1))->format('Y-m-d');
    }

    public function updatedRosterUnit(): void
    {
        abort_unless($this->rosterUnit === '' || isset($this->rosterUnits()[$this->rosterUnit]), 403);
        $this->rosterPage = 1;
        $this->reset('rosterPreview');
    }

    /** Departments this person can roster: id (or 'none') => name. Empty where the branch has none. */
    private function rosterUnits(): array
    {
        if (! \App\Support\AttendancePlan::hospital()) {
            return [];
        }
        $user = auth()->user();
        $manager = \App\Support\AttendanceScope::manager($user);
        $departments = \App\Models\AttendanceDepartment::query()->orderBy('name')
            ->when(! $manager, fn ($query) => $query->whereIn('id', \App\Support\AttendanceScope::headed($user)))->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all();
        if ($departments && $manager && AttendanceStaff::query()->where('is_tracked', true)->whereNull('attendance_department_id')->exists()) {
            $departments['none'] = 'No department';
        }

        return $departments;
    }

    /** The people on the roster: the department picked, or everyone the person may see. */
    private function rosterStaffIds(): ?array
    {
        if ($this->rosterUnit === '') {
            return \App\Support\AttendanceScope::staffIds(auth()->user(), '');
        }
        abort_unless(isset($this->rosterUnits()[$this->rosterUnit]), 403);

        return AttendanceStaff::query()->when($this->rosterUnit === 'none', fn ($query) => $query->whereNull('attendance_department_id'),
            fn ($query) => $query->where('attendance_department_id', (int) $this->rosterUnit))->pluck('id')->all();
    }

    /** [first day, last day] on the roster. */
    private function rosterRange(): array
    {
        $anchor = CarbonImmutable::parse($this->rosterWeek ?: 'today')->startOfDay();
        if ($this->rosterSpan === 'month') {
            return [$anchor->startOfMonth(), $anchor->endOfMonth()->startOfDay()];
        }
        $monday = $anchor->startOfWeek(\Carbon\CarbonInterface::MONDAY);

        return [$monday, $monday->addDays(6)];
    }

    /** Read a roster filled in from the downloaded file, and show what it would change. */
    public function readRosterFile(\App\Services\Attendance\AttendanceRosterFile $file, \App\Services\Attendance\AttendanceStaffImport $reader): void
    {
        $this->authorizePart('shifts');
        $this->authorizeView();
        $this->validate(['rosterFile' => 'required|file|max:5120'], ['rosterFile.required' => 'Choose the file first.'], ['rosterFile' => 'file']);
        $ids = $this->rosterStaffIds();
        $staff = AttendanceStaff::query()->where('is_tracked', true)->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))->get();
        $this->rosterPreview = $file->read($reader->rows($this->rosterFile, 'rosterFile'), $staff, \App\Support\ShiftCodes::for(\App\Models\AttendanceShift::query()->get()),
            CarbonImmutable::now($this->timezone())->startOfDay());
        $this->reset('rosterFile');
    }

    public function applyRosterFile(\App\Services\Attendance\AttendanceRosterFile $file): void
    {
        $this->authorizePart('shifts');
        $this->authorizeView();
        $changes = $this->rosterPreview['changes'] ?? [];
        // Only people this person may roster, and nothing before today, whatever came back from the browser.
        $ids = $this->rosterStaffIds();
        $today = CarbonImmutable::now($this->timezone())->format('Y-m-d');
        $changes = array_values(array_filter($changes, fn ($change) => ($ids === null || in_array((int) $change[0], $ids, true)) && $change[2] >= $today));
        $count = $file->apply($changes, auth()->id());
        \App\Models\AuditTrail::record('attendance.roster_uploaded', "Brought in a roster from a file: {$count} days changed for ".count(array_unique(array_column($changes, 0))).' people',
            null, [], ['from' => $this->rosterPreview['from'] ?? null, 'to' => $this->rosterPreview['to'] ?? null], null, true);
        $this->reset('rosterPreview', 'showRosterUpload');
        $this->dispatch('notify', type: 'success', message: "Roster updated: {$count} ".($count === 1 ? 'day' : 'days').' changed.');
    }

    /** One person's shift on one date: '' follows their usual hours, '0' is a day off, else a shift. */
    public function setRoster(int $staffId, string $date, string $choice): void
    {
        $this->authorizePart('shifts');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);
        $staff = $this->authorizeStaff($staffId);
        if ($choice === '') {
            \App\Models\AttendanceRoster::query()->where('attendance_staff_id', $staff->id)->whereDate('date', $date)->delete();

            return;
        }
        $shiftId = $choice === '0' ? null : \App\Models\AttendanceShift::query()->findOrFail((int) $choice)->id;
        \App\Models\AttendanceRoster::query()->updateOrCreate(['attendance_staff_id' => $staff->id, 'date' => $date],
            ['attendance_shift_id' => $shiftId, 'note' => null, 'set_by' => auth()->id()]);
    }

    public function togglePattern(): void
    {
        $this->authorizeView();
        $this->authorizeHospital();
        $this->showPattern = ! $this->showPattern;
        $this->showSwap = false;
        if ($this->showPattern && $this->patternFrom === '') {
            $monday = CarbonImmutable::parse($this->rosterWeek ?: 'today')->startOfWeek(\Carbon\CarbonInterface::MONDAY);
            [$this->patternFrom, $this->patternTo] = [$monday->format('Y-m-d'), $monday->addDays(27)->format('Y-m-d')];
        }
        $this->resetValidation();
    }

    /** Add so many days of one shift, or of days off, to the end of the cycle. */
    public function addPatternStep(): void
    {
        $this->authorizeView();
        $this->validate(['patternAddShift' => ['required', 'regex:/^\d+$/'], 'patternAddCount' => 'required|integer|min:1|max:31'],
            ['patternAddShift.required' => 'Choose a shift or days off.'], ['patternAddShift' => 'shift', 'patternAddCount' => 'days']);
        abort_unless($this->patternAddShift === '0' || \App\Models\AttendanceShift::query()->whereKey((int) $this->patternAddShift)->exists(), 404);
        if (count($this->patternDays) + (int) $this->patternAddCount > 62) {
            $this->addError('patternAddCount', 'A cycle can be up to 62 days long.');

            return;
        }
        array_push($this->patternDays, ...array_fill(0, (int) $this->patternAddCount, $this->patternAddShift));
    }

    /** Tick everyone in the department on the roster for the pattern, or nobody. */
    public function tickAllPattern(bool $all): void
    {
        $this->authorizeView();
        $ids = $this->rosterStaffIds();
        $this->patternPeople = $all ? AttendanceStaff::query()->where('is_tracked', true)->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->pluck('id')->map(fn ($id) => (string) $id)->all() : [];
        $this->resetValidation('patternPeople');
    }

    public function undoPatternStep(): void
    {
        // The last run of the same shift goes together, as it was added.
        $last = end($this->patternDays);
        while ($this->patternDays && end($this->patternDays) === $last) {
            array_pop($this->patternDays);
        }
    }

    /** A saved pattern picked from the list. */
    public function updatedPatternId(): void
    {
        $pattern = \App\Models\AttendancePattern::query()->find((int) $this->patternId);
        $this->patternDays = $pattern ? array_map('strval', $pattern->days ?? []) : [];
        $this->patternName = $pattern->name ?? '';
    }

    public function savePattern(): void
    {
        $this->authorizeView();
        $this->patternName = trim($this->patternName);
        $this->validate(['patternName' => 'required|string|max:60', 'patternDays' => 'required|array|min:1'],
            ['patternDays.required' => 'Build the cycle first.'], ['patternName' => 'name']);
        $pattern = \App\Models\AttendancePattern::query()->updateOrCreate(['name' => $this->patternName], ['days' => array_map('intval', $this->patternDays)]);
        $this->patternId = (string) $pattern->id;
        $this->dispatch('notify', type: 'success', message: "Pattern {$pattern->name} saved.");
    }

    public function deletePattern(): void
    {
        $this->authorizeView();
        \App\Models\AttendancePattern::query()->whereKey((int) $this->patternId)->delete();
        $this->reset('patternId', 'patternName', 'patternDays');
    }

    /**
     * Fill the roster for the people ticked, first day to last, by repeating the cycle. Each next
     * person can start so many days further into it, so they are not all on the same shift.
     */
    public function applyPattern(): void
    {
        $this->authorizeView();
        $this->authorizeHospital();
        $this->validate([
            'patternDays' => 'required|array|min:1',
            'patternFrom' => 'required|date_format:Y-m-d',
            'patternTo' => 'required|date_format:Y-m-d|after_or_equal:patternFrom',
            'patternPeople' => 'required|array|min:1',
            'patternStagger' => 'required|integer|min:0|max:62',
        ], ['patternDays.required' => 'Build the cycle first.', 'patternPeople.required' => 'Tick the people to fill the roster for.'],
            ['patternFrom' => 'first day', 'patternTo' => 'last day', 'patternStagger' => 'days apart']);
        $from = CarbonImmutable::parse($this->patternFrom);
        $span = (int) $from->diffInDays(CarbonImmutable::parse($this->patternTo)) + 1;
        if ($span > 93) {
            $this->addError('patternTo', 'Fill up to three months at a time.');

            return;
        }
        $shiftIds = \App\Models\AttendanceShift::query()->pluck('id')->all();
        $cycle = array_map('intval', $this->patternDays);
        abort_unless(! array_diff(array_filter($cycle), $shiftIds), 404);

        $people = array_values(array_map(fn ($id) => $this->authorizeStaff((int) $id), $this->patternPeople));
        usort($people, fn ($a, $b) => strcmp($a->name, $b->name));
        $context = app(\App\Support\Tenancy\TenantContext::class);
        $rows = [];
        foreach ($people as $position => $staff) {
            for ($day = 0; $day < $span; $day++) {
                $choice = $cycle[($day + $position * (int) $this->patternStagger) % count($cycle)];
                $rows[] = ['clinic_id' => $context->clinic()->id, 'branch_id' => $context->branchId(), 'attendance_staff_id' => $staff->id,
                    'date' => $from->addDays($day)->format('Y-m-d'), 'attendance_shift_id' => $choice ?: null, 'note' => null, 'set_by' => auth()->id(),
                    'created_at' => now(), 'updated_at' => now()];
            }
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($people, $rows) {
            \App\Models\AttendanceRoster::query()->whereIn('attendance_staff_id', array_map(fn ($staff) => $staff->id, $people))
                ->whereBetween('date', [$this->patternFrom, $this->patternTo])->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                \Illuminate\Support\Facades\DB::table('attendance_roster')->insert($chunk);
            }
        });
        \App\Models\AuditTrail::record('attendance.roster_filled', 'Filled the roster from '.$from->format('j M Y').' to '.CarbonImmutable::parse($this->patternTo)->format('j M Y')
            .' for '.count($people).' people from a '.count($cycle).'-day pattern'.($this->patternName ? " ({$this->patternName})" : ''), null, [], ['people' => array_map(fn ($staff) => $staff->name, $people)], null, true);
        $this->showPattern = false;
        $this->patternPeople = [];
        $this->dispatch('notify', type: 'success', message: 'Roster filled for '.count($people).(count($people) === 1 ? ' person.' : ' people.'));
    }

    public function toggleSwap(): void
    {
        $this->authorizeView();
        $this->authorizeHospital();
        $this->showSwap = ! $this->showSwap;
        $this->showPattern = false;
        $this->reset('swapA', 'swapB', 'swapTo');
        $this->swapFrom = $this->rosterWeek;
        $this->resetValidation();
    }

    /** Exchange two people's shifts over a day or a run of days. Done by the head or a manager, never by staff. */
    public function swapRoster(AttendanceAnalyzer $analyzer): void
    {
        $this->authorizeView();
        $this->authorizeHospital();
        $this->validate([
            'swapA' => 'required|integer', 'swapB' => 'required|integer|different:swapA',
            'swapFrom' => 'required|date_format:Y-m-d', 'swapTo' => 'nullable|date_format:Y-m-d|after_or_equal:swapFrom',
        ], ['swapB.different' => 'Choose two different people.'], ['swapA' => 'first person', 'swapB' => 'second person', 'swapFrom' => 'day', 'swapTo' => 'last day']);
        [$a, $b] = [$this->authorizeStaff((int) $this->swapA), $this->authorizeStaff((int) $this->swapB)];
        $from = CarbonImmutable::parse($this->swapFrom);
        $to = CarbonImmutable::parse($this->swapTo ?: $this->swapFrom);
        if ($from->diffInDays($to) > 31) {
            $this->addError('swapTo', 'Swap up to a month at a time.');

            return;
        }
        $changes = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            [$mine, $theirs] = [$analyzer->shiftChoice($a, $day), $analyzer->shiftChoice($b, $day)];
            foreach ([[$a, $mine], [$b, $theirs]] as [$staff, $choice]) {
                if ($choice === null) {
                    $this->addError('swapFrom', "{$staff->name} has no shift on ".$day->format('D j M').' (they follow usual hours), so there is nothing to swap.');

                    return;
                }
            }
            if ($mine !== $theirs) {
                $changes[] = [$day, $mine, $theirs];
            }
        }
        if (! $changes) {
            $this->addError('swapFrom', 'They are on the same shift on '.($from->eq($to) ? 'that day' : 'those days').' already.');

            return;
        }
        \App\Support\AttendanceAudit::quietly(fn () => collect($changes)->each(function ($change) use ($a, $b) {
            [$day, $mine, $theirs] = $change;
            foreach ([[$a, $theirs, $b], [$b, $mine, $a]] as [$staff, $choice, $other]) {
                \App\Models\AttendanceRoster::query()->updateOrCreate(['attendance_staff_id' => $staff->id, 'date' => $day->format('Y-m-d')],
                    ['attendance_shift_id' => $choice ?: null, 'note' => 'Swapped with '.$other->name, 'set_by' => auth()->id()]);
            }
        }));
        \App\Models\AuditTrail::record('attendance.shifts_swapped', "Swapped the shifts of {$a->name} and {$b->name} on ".$from->format('j M Y').($to->gt($from) ? ' to '.$to->format('j M Y') : '')
            .' ('.count($changes).(count($changes) === 1 ? ' day' : ' days').')', null, [], ['staff' => [$a->name, $b->name]], null, true);
        $this->showSwap = false;
        $this->dispatch('notify', type: 'success', message: "Swapped {$a->name} and {$b->name}.");
    }

    /**
     * Staff id => 'Y-m-d' => what the roster breaks for that person that day: too little rest
     * since the shift before, or more nights in a row than the branch allows. Warnings only.
     */
    private function restWarnings(AttendanceAnalyzer $analyzer, array $rows, CarbonImmutable $first, CarbonImmutable $last, AttendanceSetting $settings): array
    {
        [$minRest, $maxNights] = [$settings->min_rest_hours, $settings->max_nights_in_row];
        if ((! $minRest && ! $maxNights) || ! \App\Support\AttendancePlan::hospital()) {
            return [];
        }
        $minutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $warnings = [];
        $schedules = $analyzer->schedules(array_map(fn ($row) => $row['staff'], $rows), $first->subDays(max(1, (int) $maxNights)), $last);
        foreach ($rows as $row) {
            $schedule = $schedules[$row['staff']->id] ?? [];
            $keys = array_keys($schedule);
            $nights = 0;
            foreach ($keys as $i => $key) {
                $hours = $schedule[$key];
                $nights = $hours && $minutes($hours['end']) >= 1440 ? $nights + 1 : 0;
                if (! $hours || $key < $first->format('Y-m-d')) {
                    continue;
                }
                $before = $i > 0 ? $schedule[$keys[$i - 1]] : null;
                if ($minRest && $before && ($rest = $minutes($hours['start']) + 1440 - $minutes($before['end'])) < $minRest * 60) {
                    $warnings[$row['staff']->id][$key][] = 'Only '.AttendanceAnalyzer::duration(max(0, $rest)).' rest after '.($before['shift'] ?? 'the day before');
                }
                if ($maxNights && $nights > $maxNights) {
                    $warnings[$row['staff']->id][$key][] = 'Night '.$nights.' in a row';
                }
            }
        }

        return $warnings;
    }

    /** The roster week: each followed person's hours on each day, and what the roster sets. */
    private function rosterGrid(AttendanceAnalyzer $analyzer): array
    {
        [$first, $last] = $this->rosterRange();
        $report = $analyzer->range($first, $last);
        $set = [];
        $notes = [];
        \App\Models\AttendanceRoster::query()->whereBetween('date', [$first->format('Y-m-d'), $last->format('Y-m-d')])->get()
            ->each(function ($entry) use (&$set, &$notes) {
                $set[$entry->attendance_staff_id][$entry->date->format('Y-m-d')] = (string) ($entry->attendance_shift_id ?? 0);
                if ($entry->note) {
                    $notes[$entry->attendance_staff_id][$entry->date->format('Y-m-d')] = $entry->note;
                }
            });

        $rows = array_values(array_filter($report['rows'], fn ($row) => $row['staff']->is_tracked));
        [$cover, $gaps] = $this->rosterCover($rows, $report['days'], ctype_digit($this->rosterUnit) ? [(int) $this->rosterUnit] : ($this->rosterUnit === 'none' ? [] : null));

        // Cover counts everyone; the grid shows a page of them, and only those are checked for rest.
        [$shown, $pager] = $this->paged(array_values(array_filter($rows, fn ($row) => $this->matches($row['staff']))), $this->rosterPage, 25);

        return ['days' => $report['days'], 'set' => $set, 'notes' => $notes, 'holidays' => $report['holidays'], 'rows' => $rows, 'shown' => $shown, 'pager' => $pager,
            'cover' => $cover, 'gaps' => $gaps,
            'warnings' => $this->restWarnings($analyzer, $shown, $first, $last, $report['settings']),
            'first' => $first, 'last' => $last, 'units' => $this->rosterUnits(),
            'codes' => \App\Support\ShiftCodes::for(\App\Models\AttendanceShift::query()->get()),
            'patterns' => $this->showPattern ? \App\Models\AttendancePattern::query()->orderBy('name')->get() : collect()];
    }
}
