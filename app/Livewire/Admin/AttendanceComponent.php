<?php

namespace App\Livewire\Admin;

use App\Models\AttendanceDevice;
use App\Models\AttendanceHoliday;
use App\Models\AttendanceImport;
use App\Models\AttendanceLeave;
use App\Models\AttendanceSetting;
use App\Models\AttendanceStaff;
use App\Models\User;
use App\Services\Attendance\AttendanceAnalyzer;
use App\Services\Attendance\AttendanceImportService;
use App\Services\Attendance\AttendancePeriod;
use App\Services\Attendance\AttendanceRecordParser;
use App\Services\OwnerAlerts;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Staff & Security → Attendance: for the days picked, see for the branch who was on time, late or
 * absent, day by day, from a connected terminal or its uploaded "Attendance Record" report. Working hours, holidays, leave
 * and hours of their own for individual staff are set here too.
 */
class AttendanceComponent extends Component
{
    use Concerns\AttendanceAccess, WithFileUploads;

    // The days on show, 'Y-m-d' both; the page opens on this month to date.
    #[Url]
    public string $from = '';
    #[Url]
    public string $to = '';
    #[Url]
    public string $view = 'summary';
    /** Today (the live board), report or requests; empty until the page picks one. */
    #[Url]
    public string $tab = '';
    // On the Today board: the department whose names are open, and which count's names are shown.
    public string $boardUnit = '';
    public string $boardShow = '';
    public bool $showShort = false;
    public string $show = 'all';
    /** The summary's order: '' = names on a small staff, most to look at first on a large one. */
    public string $sort = '';

    /** Above this many people followed, the summary opens on those who need a look, worst first. */
    public const LARGE_STAFF = 50;
    /** Part of a name or Employee ID, to find people in a long list. */
    public string $search = '';
    // Long lists of people come a page at a time: the main list, the roster and payroll.
    public int $page = 1;
    public int $payPage = 1;
    /** The department on show: '' everyone, an id, or 'none' for people in no department. */
    #[Url]
    public string $department = '';


    // Day by day: the month on the calendar ('Y-m') and the day whose list is open ('Y-m-d').
    public string $calendarMonth = '';
    public string $dayKey = '';
    public bool $showDay = false;

    public bool $showUpload = false;
    public $file = null;

    // Bringing in a staff list from Excel or CSV, and what the last one did.
    public bool $showStaffImport = false;
    public $staffFile = null;
    public ?array $staffImported = null;

    public bool $showHours = false;
    public array $hours = [];
    public int|string $graceMinutes = 15;
    public int|string $breakMinutes = 60;
    public int|string $overtimeAfter = 30;
    public bool $overtimeNeedsApproval = true;

    // Clocking in from a phone: on or off, where the branch is and how near a phone must be.
    public bool $showPhone = false;
    public bool $phoneOn = false;
    public string $phoneLatitude = '';
    public string $phoneLongitude = '';
    public string $phoneRadius = '150';
    public string $phoneUserId = '';

    // Payroll for the days on show: how each person is paid and the branch's pay rules. The owner only.
    public bool $showPayroll = false;
    /** Staff id => ['type' => '' | 'monthly' | 'hourly', 'rate' => string]. */
    public array $pay = [];
    public string $overtimeMultiplier = '1.5';
    public bool $deductLateness = false;
    public bool $deductAbsence = true;

    // Overtime in the days on show, to approve or turn down. Keys are 'staff id-Y-m-d'.
    public bool $showOvertime = false;
    public array $overtimeMinutes = [];
    public array $overtimeNotes = [];

    public bool $showDevices = false;
    public string $deviceName = '';

    public bool $showHolidays = false;
    public string $holidayDate = '';
    public string $holidayName = '';

    public ?int $staffId = null;
    public bool $showStaff = false;
    public bool $staffTracked = true;
    public string $staffStartsOn = '';
    public string $staffUserId = '';
    public bool $staffOwnHours = false;
    public array $staffHours = [];
    public string $leaveFrom = '';
    public string $leaveTo = '';
    public string $leaveType = 'annual';
    public string $leaveNote = '';
    public string $leaveFromTime = '';
    public string $leaveToTime = '';

    public bool $showRequests = false;
    /** The department picked in a person's window. */
    public string $staffDepartmentId = '';
    public bool $showMore = false;

    // The branch's kinds of leave and their yearly allowances.
    public bool $showLeaveTypes = false;
    public ?int $leaveTypeEditId = null;
    public string $leaveTypeName = '';
    public string $leaveTypeDays = '';
    public bool $leaveTypePaid = true;
    public array $reviewNotes = [];

    // Shifts, a person's weekly pattern of them, and the dated roster.
    public bool $showShifts = false;
    public string $shiftName = '';
    public string $shiftStart = '';
    public string $shiftEnd = '';
    public string $shiftBreak = '';
    /** ISO weekday => '' (usual hours), '0' (day off) or a shift id. */
    public array $staffShiftWeek = [];

    // Rest rules the roster warns about; empty = not checked.
    public string $minRestHours = '';
    public string $maxNights = '';

    // Correcting one person's punches on one day.
    public bool $showPunches = false;
    public ?int $punchStaffId = null;
    public string $punchDate = '';
    public string $punchTime = '';
    public string $punchReason = '';
    public ?int $punchEditId = null;
    /** The window the corrections were opened from ('day' or 'staff'), to go back to. */
    public string $punchBack = '';

    public function mount(): void
    {
        $this->authorizeView();
        // Links made before the page took two dates name a whole month.
        if ($month = AttendancePeriod::month(request()->query('month'))) {
            [$this->from, $this->to] = [$month[0]->format('Y-m-d'), $month[1]->format('Y-m-d')];
        }
        [$first, $last] = $this->period();
        [$this->from, $this->to] = [$first->format('Y-m-d'), $last->format('Y-m-d')];
        if (! in_array($this->view, ['summary', 'grid', 'trends'], true)) {
            $this->view = 'summary';
        }
        // A large staff opens on those who need a look.
        if (AttendanceStaff::query()->where('is_tracked', true)->count() > self::LARGE_STAFF) {
            $this->show = 'attention';
        }
        // Links to a view of the report open on the report.
        if ($this->tab === '' && request()->query('view')) {
            $this->tab = 'report';
        }
    }

    public function clearBoard(): void
    {
        $this->reset('boardUnit', 'boardShow');
    }

    /** From a department on the Today board to its figures in the report. */
    public function showDepartmentReport(string $unit): void
    {
        $this->department = $unit === 'none' ? 'none' : (string) (int) $unit;
        $this->reset('boardUnit', 'boardShow');
        $this->tab = 'report';
        $this->page = 1;
    }


    /** A change to what is listed starts again from the first page. */
    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'department', 'show', 'view', 'from', 'to', 'sort'], true)) {
            $this->page = $this->rosterPage = $this->payPage = 1;
        }
    }

    /** How much in someone's days needs a look: late arrivals, absences (twice), leaving early, long breaks, missing punches, overtime waiting. */
    private static function issues(array $t): int
    {
        return $t['late'] + 2 * $t['absent'] + $t['left_early'] + $t['long_breaks'] + $t['no_clock_out'] + (int) ($t['overtime_pending'] > 0);
    }

    /** The summary in an order: by name, or worst first by a column. People not followed always come last. */
    private function sorted(array $rows, string $sort): array
    {
        $key = match ($sort) {
            'issues' => fn ($t) => [-self::issues($t), -$t['late_minutes']],
            'late' => fn ($t) => [-$t['late'], -$t['late_minutes']],
            'absent' => fn ($t) => [-$t['absent'], -$t['late']],
            'present' => fn ($t) => [$t['expected'] ? $t['present'] / $t['expected'] : 2, -$t['absent']],
            'arrival' => fn ($t) => [$t['average_arrival'] === null ? 1 : 0, $t['average_arrival'] === null ? '' : -AttendanceAnalyzer::minutesOf($t['average_arrival'])],
            default => null,
        };
        if (! $key) {
            return $rows;
        }
        usort($rows, fn ($a, $b) => [! $a['staff']->is_tracked, ...$key($a['totals']), $a['staff']->name] <=> [! $b['staff']->is_tracked, ...$key($b['totals']), $b['staff']->name]);

        return $rows;
    }

    /** Day by day: the month before or after, within the days picked. */
    public function shiftCalendar(int $by): void
    {
        [$first, $last] = $this->period();
        $month = $this->calendarStart($first, $last)->addMonthsNoOverflow($by <=> 0);
        if ($month->lte($last) && $month->endOfMonth()->gte($first)) {
            $this->calendarMonth = $month->format('Y-m');
        }
    }

    /** Day by day: everyone's punches on one day. */
    public function openDay(string $date): void
    {
        [$first, $last] = $this->period();
        $day = AttendancePeriod::resolve($date, $date, $first)[0];
        if ($day->format('Y-m-d') !== $date || $day->lt($first) || $day->gt($last)) {
            return;
        }
        $this->dayKey = $date;
        $this->showDay = true;
    }

    public function addShift(): void
    {
        $this->authorizePart('shifts');
        $this->authorizeAccess();
        $this->shiftName = trim($this->shiftName);
        $this->validate([
            'shiftName' => ['required', 'string', 'max:60', \Illuminate\Validation\Rule::unique('attendance_shifts', 'name')->where('branch_id', app(\App\Support\Tenancy\TenantContext::class)->branchId())],
            'shiftStart' => 'required|date_format:H:i', 'shiftEnd' => 'required|date_format:H:i|different:shiftStart',
            'shiftBreak' => 'nullable|integer|min:0|max:600',
        ], ['shiftEnd.different' => 'A shift cannot start and end at the same time.', 'shiftName.unique' => 'There is already a shift with that name.'],
            ['shiftName' => 'name', 'shiftStart' => 'start', 'shiftEnd' => 'end', 'shiftBreak' => 'break']);
        \App\Models\AttendanceShift::create(['name' => $this->shiftName, 'starts_at' => $this->shiftStart, 'ends_at' => $this->shiftEnd,
            'break_minutes' => $this->shiftBreak === '' ? null : (int) $this->shiftBreak, 'is_active' => true]);
        $this->reset('shiftName', 'shiftStart', 'shiftEnd', 'shiftBreak');
    }

    /** A shift no longer offered stays on the days and people already given it. */
    public function toggleShift(int $id): void
    {
        $this->authorizePart('shifts');
        $this->authorizeAccess();
        $shift = \App\Models\AttendanceShift::query()->findOrFail($id);
        $shift->update(['is_active' => ! $shift->is_active]);
    }

    public function removeShift(int $id): void
    {
        $this->authorizePart('shifts');
        $this->authorizeAccess();
        $shift = \App\Models\AttendanceShift::query()->findOrFail($id);
        $inUse = \App\Models\AttendanceRoster::query()->where('attendance_shift_id', $shift->id)->exists()
            || AttendanceStaff::query()->whereNotNull('shift_week')->get()->contains(fn ($staff) => in_array($shift->id, array_map('intval', $staff->shift_week ?? []), true));
        if ($inUse) {
            $this->addError('shiftName', "{$shift->name} is on the roster or in someone's week, so it can't be deleted. Stop offering it instead.");

            return;
        }
        $shift->delete();
    }

    /** The Today board by department: each one the person may see, and people in none, with what is short now. */
    private function boardRows(array $board, $departments, array $shortNow): array
    {
        if ($departments->isEmpty()) {
            return [];
        }
        $rows = $departments->map(fn ($unit) => ['key' => (string) $unit->id, 'name' => $unit->name,
            'short' => array_values(array_filter($shortNow, fn ($gap) => $gap['department'] === $unit->name))])->values()->all();
        if (collect($board)->contains(fn ($person) => ! $person['staff']->attendance_department_id)) {
            $rows[] = ['key' => 'none', 'name' => 'No department', 'short' => []];
        }

        return $rows;
    }

    /** Departments short on a shift that is running right now: fewer people in than its minimum. */
    private function shortNow(array $board, CarbonImmutable $now): array
    {
        $short = [];
        $clock = (int) $now->format('H') * 60 + (int) $now->format('i');
        $minutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        foreach (\App\Models\AttendanceCover::query()->whereIn('attendance_department_id', $this->departmentsOnShow() ?: [0])->with(['department:id,name', 'shift'])->get() as $cover) {
            if (! $cover->department || ! $cover->shift) {
                continue;
            }
            [$start, $end] = [$minutes($cover->shift->starts_at), $minutes($cover->shift->ends_at)];
            // A night shift is running late in the evening and again before it ends in the morning.
            $running = $cover->shift->overnight() ? ($clock >= $start || $clock < $end) : ($clock >= $start && $clock < $end);
            if (! $running) {
                continue;
            }
            $day = $cover->shift->overnight() && $clock < $end ? $now->subDay() : $now;
            $in = count(array_filter($board, fn ($person) => $person['state'] === 'in' && $person['shift'] === $cover->shift->name
                && $person['staff']->attendance_department_id === $cover->attendance_department_id));
            if ($in < $need = $cover->neededOn($day)) {
                $short[] = ['department' => $cover->department->name, 'shift' => $cover->shift->name, 'in' => $in, 'need' => $need];
            }
        }

        return $short;
    }

    /** One person's punches on one day, to add a missed punch, change a time or remove a mistake. */
    public function openPunches(int $staffId, string $date): void
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);
        $this->authorizeStaff($staffId);
        $this->punchBack = $this->showDay ? 'day' : ($this->showStaff ? 'staff' : '');
        $this->showDay = $this->showStaff = false;
        $this->punchStaffId = $staffId;
        $this->punchDate = $date;
        $this->reset('punchTime', 'punchReason', 'punchEditId');
        $this->resetValidation();
        $this->showPunches = true;
    }

    /** Back to the day or the person the corrections were opened from. */
    public function closePunches(): void
    {
        $this->showPunches = false;
        if ($this->punchBack === 'day') $this->showDay = true;
        if ($this->punchBack === 'staff') $this->showStaff = true;
    }

    public function editPunch(int $id): void
    {
        $this->authorizeStaff((int) $this->punchStaffId);
        $punch = \App\Models\AttendancePunch::query()->where('attendance_staff_id', $this->punchStaffId)->findOrFail($id);
        $this->punchEditId = $punch->id;
        $this->punchTime = $punch->punched_at->format('H:i');
        $this->resetValidation();
    }

    public function cancelPunchEdit(): void
    {
        $this->reset('punchEditId', 'punchTime');
        $this->resetValidation();
    }

    /** Add a punch, or save the new time of the one being changed. */
    public function savePunch(\App\Services\Attendance\AttendanceCorrections $corrections): void
    {
        $this->authorizeStaff((int) $this->punchStaffId);
        $this->validate(['punchTime' => 'required|date_format:H:i', 'punchReason' => 'required|string|min:5|max:255'],
            ['punchTime.required' => 'Enter the time.', 'punchTime.date_format' => 'Enter the time as hours and minutes.', 'punchReason.required' => 'Say why.', 'punchReason.min' => 'Say why, in at least 5 characters.']);
        $staff = AttendanceStaff::query()->findOrFail($this->punchStaffId);
        $at = CarbonImmutable::parse($this->punchDate.' '.$this->punchTime.':00');
        $now = CarbonImmutable::parse(CarbonImmutable::now($this->timezone())->format('Y-m-d H:i:s'));
        if ($this->punchEditId) {
            $corrections->move(\App\Models\AttendancePunch::query()->where('attendance_staff_id', $staff->id)->findOrFail($this->punchEditId), $at, $this->punchReason, $now);
        } else {
            $corrections->add($staff, $at, $this->punchReason, $now);
        }
        $this->reset('punchTime', 'punchReason', 'punchEditId');
        $this->dispatch('notify', type: 'success', message: 'Punch saved.');
    }

    public function removePunch(int $id, \App\Services\Attendance\AttendanceCorrections $corrections): void
    {
        $this->authorizeStaff((int) $this->punchStaffId);
        $this->validate(['punchReason' => 'required|string|min:5|max:255'], ['punchReason.required' => 'Say why the punch is being removed.', 'punchReason.min' => 'Say why, in at least 5 characters.']);
        $corrections->void(\App\Models\AttendancePunch::query()->where('attendance_staff_id', $this->punchStaffId)->findOrFail($id), $this->punchReason);
        $this->reset('punchTime', 'punchReason', 'punchEditId');
        $this->dispatch('notify', type: 'success', message: 'Punch removed. It stays in the history.');
    }

    /** The day being corrected: how it now counts, and every punch on it, removed ones included. */
    private function punchDay(AttendanceAnalyzer $analyzer, array $report): ?array
    {
        $staff = AttendanceStaff::query()->find($this->punchStaffId);
        if (! $staff) {
            return null;
        }
        $date = CarbonImmutable::parse($this->punchDate);
        $row = collect(isset($report['days'][$this->punchDate]) ? $report['rows'] : $analyzer->range($date, $date)['rows'])
            ->first(fn ($row) => $row['staff']->id === $staff->id);

        return ['staff' => $staff, 'date' => $date, 'day' => $row['days'][$this->punchDate] ?? null,
            'punches' => \App\Models\AttendancePunch::withVoided()->with(['creator:id,name', 'voider:id,name', 'device:id,name'])
                ->where('attendance_staff_id', $staff->id)
                ->whereBetween('punched_at', [$this->punchDate.' 00:00:00', $this->punchDate.' 23:59:59'])->orderBy('punched_at')->orderBy('id')->get()];
    }

    /** The first day of the month the calendar is on: the one chosen, or the latest month of the days picked. */
    private function calendarStart(CarbonImmutable $first, CarbonImmutable $last): CarbonImmutable
    {
        $month = AttendancePeriod::month($this->calendarMonth)[0] ?? null;

        return $month && $month->lte($last) && $month->endOfMonth()->gte($first) ? $month : $last->startOfMonth();
    }

    /**
     * The calendar for one month: weeks of seven cells, Monday first, null outside the month. Each
     * cell counts the followed staff: who came ('in', late or not), late, absent, on leave, and
     * 'flags' for days to look at (left early, a long break, a missing punch).
     */
    private function calendar(array $report, CarbonImmutable $first, CarbonImmutable $last, CarbonImmutable $today): array
    {
        $month = $this->calendarStart($first, $last);
        $tracked = array_filter($report['rows'], fn ($row) => $row['staff']->is_tracked);
        $weeks = [];
        $week = array_fill(0, $month->dayOfWeekIso - 1, null);
        for ($date = $month; $date->month === $month->month; $date = $date->addDay()) {
            $key = $date->format('Y-m-d');
            $counts = ['in' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'flags' => 0];
            $states = [];
            foreach ($tracked as $row) {
                $day = $row['days'][$key] ?? null;
                if (! $day) {
                    continue;
                }
                $states[$day['status']] = true;
                $counts['in'] += (int) in_array($day['status'], ['on_time', 'late', 'worked_off'], true);
                $counts['late'] += (int) ($day['status'] === 'late');
                $counts['absent'] += (int) ($day['status'] === 'absent');
                $counts['leave'] += (int) ($day['status'] === 'leave');
                $counts['flags'] += (int) ($day['left_early'] || $day['no_clock_out'] || $day['long_break']);
            }
            $summary = implode(', ', array_filter([
                $counts['in'] ? $counts['in'].' in' : null, $counts['late'] ? $counts['late'].' late' : null,
                $counts['absent'] ? $counts['absent'].' absent' : null, $counts['leave'] ? $counts['leave'].' on leave' : null,
            ]));
            $week[] = [
                'key' => $key, 'date' => $date, 'counts' => $counts, 'today' => $date->isSameDay($today),
                // Days of the month outside the days picked are drawn but empty.
                'shown' => isset($report['days'][$key]),
                'holiday' => $report['holidays'][$key] ?? null,
                // 'none' when nothing was received for anyone, 'off' when nobody was expected.
                'state' => ! array_diff_key($states, ['none' => 1]) ? 'none' : (! array_diff_key($states, ['off' => 1, 'none' => 1]) ? 'off' : 'day'),
                'summary' => $summary ?: 'nothing recorded',
            ];
            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }
        if ($week) {
            $weeks[] = array_pad($week, 7, null);
        }

        return ['month' => $month, 'weeks' => $weeks,
            'previous' => $month->gt($first->startOfMonth()), 'next' => $month->lt($last->startOfMonth())];
    }

    /** Everyone followed on the open day, those to look at first. */
    private function dayList(array $report): ?array
    {
        if (! isset($report['days'][$this->dayKey])) {
            return null;
        }
        $order = ['absent' => 0, 'late' => 1, 'none' => 2, 'on_time' => 3, 'worked_off' => 4, 'leave' => 5, 'holiday' => 6, 'off' => 7];
        $people = [];
        foreach ($report['rows'] as $row) {
            if ($row['staff']->is_tracked) {
                $people[] = ['staff' => $row['staff'], 'day' => $row['days'][$this->dayKey]];
            }
        }
        usort($people, fn ($a, $b) => [$order[$a['day']['status']] ?? 9, $a['staff']->name] <=> [$order[$b['day']['status']] ?? 9, $b['staff']->name]);

        return ['date' => $report['days'][$this->dayKey], 'people' => $people, 'holiday' => $report['holidays'][$this->dayKey] ?? null,
            'inProgress' => ($report['coverage'][$this->dayKey] ?? null) === 'partial'];
    }

    // Not "upload": that name is Livewire's own browser-side file upload function.
    public function importReport(AttendanceRecordParser $parser, AttendanceImportService $imports): void
    {
        $this->authorizeAccess();
        $this->validate(['file' => 'required|file|max:5120'], ['file.required' => 'Choose the report file first.'], ['file' => 'report']);
        if (strtolower($this->file->getClientOriginalExtension()) !== 'xls') {
            $this->addError('file', 'Upload the .xls file exported from the terminal.');

            return;
        }

        $import = $imports->import($parser->parseUpload($this->file), $this->file->getClientOriginalName());

        $this->reset('file', 'showUpload');
        // Show what was just read.
        [$this->from, $this->to] = [$import->period_start->format('Y-m-d'), $import->period_end->format('Y-m-d')];
        $this->dispatch('notify', type: 'success', message: sprintf(
            '%s read: %d staff, %d new %s.',
            $import->period_start->format('F Y'), $import->staff_count, $import->new_punches, $import->new_punches === 1 ? 'punch' : 'punches',
        ));
    }

    public function openStaffImport(): void
    {
        $this->authorizeAccess();
        $this->authorizeHospital();
        $this->reset('staffFile', 'staffImported');
        $this->resetValidation();
        $this->showStaffImport = true;
    }

    /** Add or update people, and their departments, from a staff list. Pay columns are read for the owner only. */
    public function importStaff(\App\Services\Attendance\AttendanceStaffImport $import): void
    {
        $this->authorizeAccess();
        $this->authorizeHospital();
        $this->validate(['staffFile' => 'required|file|max:5120'], ['staffFile.required' => 'Choose the file first.'], ['staffFile' => 'file']);
        $this->staffImported = $import->import($import->rows($this->staffFile), (bool) auth()->user()->hasRole('Super Admin'));
        \App\Models\AuditTrail::record('attendance.staff_imported', 'Brought in a staff list: '.$this->staffImported['added'].' added, '.$this->staffImported['updated'].' updated, '
            .$this->staffImported['departments'].' departments made', null, [], ['file' => $this->staffFile->getClientOriginalName()], null, true);
        $this->reset('staffFile');
    }

    public function editHours(): void
    {
        $this->authorizeAccess();
        $settings = AttendanceSetting::current();
        $this->hours = $this->hoursForm($settings->hours ?? []);
        $this->graceMinutes = $settings->grace_minutes;
        $this->breakMinutes = $settings->break_minutes ?? 60;
        $this->overtimeAfter = $settings->overtime_after_minutes ?? 30;
        $this->overtimeNeedsApproval = (bool) ($settings->overtime_needs_approval ?? true);
        $this->minRestHours = (string) ($settings->min_rest_hours ?? '');
        $this->maxNights = (string) ($settings->max_nights_in_row ?? '');
        $this->resetValidation();
        $this->showHours = true;
    }

    public function saveHours(): void
    {
        $this->authorizeAccess();
        $this->validate([
            'graceMinutes' => 'required|integer|min:0|max:240',
            'breakMinutes' => 'required|integer|min:0|max:480',
            'overtimeAfter' => 'required|integer|min:1|max:480',
            'minRestHours' => 'nullable|integer|min:1|max:48',
            'maxNights' => 'nullable|integer|min:1|max:31',
        ], [], ['graceMinutes' => 'grace period', 'breakMinutes' => 'break allowed', 'overtimeAfter' => 'overtime threshold', 'minRestHours' => 'rest between shifts', 'maxNights' => 'nights in a row']);
        if (! $hours = $this->hoursFrom('hours')) {
            return;
        }

        $settings = AttendanceSetting::current();
        $settings->fill(['hours' => $hours, 'grace_minutes' => (int) $this->graceMinutes, 'break_minutes' => (int) $this->breakMinutes,
            'overtime_after_minutes' => (int) $this->overtimeAfter, 'overtime_needs_approval' => $this->overtimeNeedsApproval,
            'min_rest_hours' => $this->minRestHours === '' ? null : (int) $this->minRestHours, 'max_nights_in_row' => $this->maxNights === '' ? null : (int) $this->maxNights])->save();
        $this->showHours = false;
        $this->dispatch('notify', type: 'success', message: 'Working hours saved.');
    }

    public function addDevice(): void
    {
        $this->authorizeAccess();
        $this->validate(['deviceName' => 'required|string|max:100'], [], ['deviceName' => 'name']);
        AttendanceDevice::create(['name' => trim($this->deviceName)]);
        $this->reset('deviceName');
    }

    /** The terminal stops being accepted at once; punches it already sent are kept. */
    public function removeDevice(int $id): void
    {
        $this->authorizeAccess();
        AttendanceDevice::query()->whereKey($id)->delete();
    }

    public function addHoliday(): void
    {
        $this->authorizeAccess();
        $this->validate([
            'holidayDate' => 'required|date',
            'holidayName' => 'required|string|max:100',
        ], [], ['holidayDate' => 'date', 'holidayName' => 'name']);

        AttendanceHoliday::updateOrCreate(['date' => $this->holidayDate], ['name' => trim($this->holidayName)]);
        $this->reset('holidayDate', 'holidayName');
    }

    public function removeHoliday(int $id): void
    {
        $this->authorizeAccess();
        AttendanceHoliday::query()->whereKey($id)->delete();
    }

    public function openStaff(int $id): void
    {
        $staff = $this->authorizeStaff($id);
        $this->staffId = $staff->id;
        $this->staffDepartmentId = (string) ($staff->attendance_department_id ?? '');
        $this->staffTracked = $staff->is_tracked;
        $this->staffStartsOn = $staff->starts_on?->format('Y-m-d') ?? '';
        $this->staffUserId = (string) ($staff->user_id ?? '');
        $this->staffOwnHours = (bool) $staff->hours;
        // Their own hours start as a copy of the branch's, to adjust.
        $this->staffHours = $this->hoursForm($staff->hours ?: (AttendanceSetting::current()->hours ?? []));
        $this->staffShiftWeek = [];
        foreach (array_keys(AttendanceSetting::DAYS) as $weekday) {
            $choice = $staff->shift_week[$weekday] ?? $staff->shift_week[(string) $weekday] ?? '';
            $this->staffShiftWeek[$weekday] = (string) $choice;
        }
        $this->reset('leaveFrom', 'leaveTo', 'leaveType', 'leaveNote', 'leaveFromTime', 'leaveToTime');
        $offered = \App\Models\AttendanceLeaveType::offered();
        $this->leaveType = $offered->has($this->leaveType) ? $this->leaveType : (string) $offered->keys()->first();
        $this->resetValidation();
        $this->showStaff = true;
    }

    public function saveStaff(): void
    {
        $this->authorizeAccess();
        $this->validate(['staffStartsOn' => 'nullable|date'], [], ['staffStartsOn' => 'start date']);
        $userId = (int) $this->staffUserId ?: null;
        if ($userId && ! User::query()->inCurrentClinic()->whereKey($userId)->exists()) {
            $this->addError('staffUserId', "Choose one of this clinic's users.");

            return;
        }
        if ($userId && AttendanceStaff::query()->where('user_id', $userId)->whereKeyNot($this->staffId)->exists()) {
            $this->addError('staffUserId', 'That user is already linked to someone else on the terminal.');

            return;
        }
        $hours = null;
        if ($this->staffOwnHours && ! $hours = $this->hoursFrom('staffHours')) {
            return;
        }
        if ($this->staffTracked && ! AttendanceStaff::query()->findOrFail($this->staffId)->is_tracked && ! \App\Support\AttendancePlan::canFollow()) {
            $this->addError('staffTracked', 'Your plan follows up to '.\App\Support\AttendancePlan::staffLimit().' staff, and that many are followed. Stop following someone else, or ask us to move you to a larger plan.');

            return;
        }

        // Weekly shifts: only the weekdays given a shift or a day off are kept.
        $shiftIds = \App\Models\AttendanceShift::query()->pluck('id')->all();
        $week = [];
        foreach (array_keys(AttendanceSetting::DAYS) as $weekday) {
            $choice = (string) ($this->staffShiftWeek[$weekday] ?? '');
            if ($choice === '0' || (ctype_digit($choice) && in_array((int) $choice, $shiftIds, true))) {
                $week[$weekday] = (int) $choice;
            }
        }

        AttendanceStaff::query()->findOrFail($this->staffId)->update([
            'is_tracked' => $this->staffTracked,
            'starts_on' => $this->staffStartsOn ?: null,
            'hours' => $hours,
            'shift_week' => $week ?: null,
            'attendance_department_id' => \App\Models\AttendanceDepartment::query()->whereKey((int) $this->staffDepartmentId)->value('id'),
            'user_id' => $userId,
        ]);
        $this->dispatch('notify', type: 'success', message: 'Saved.');
    }

    public function addLeave(): void
    {
        $this->authorizeStaff((int) $this->staffId);
        $this->validate([
            'leaveFrom' => 'required|date',
            'leaveTo' => 'nullable|date|after_or_equal:leaveFrom',
            'leaveType' => 'required|in:'.\App\Models\AttendanceLeaveType::offered()->keys()->implode(','),
            'leaveNote' => 'nullable|string|max:255',
            // Both times for part of one day; neither for whole days.
            'leaveFromTime' => 'nullable|date_format:H:i|required_with:leaveToTime',
            'leaveToTime' => 'nullable|date_format:H:i|required_with:leaveFromTime|after:leaveFromTime',
        ], ['leaveToTime.after' => 'The end time must be after the start time.'],
            ['leaveFrom' => 'first day', 'leaveTo' => 'last day', 'leaveType' => 'type', 'leaveNote' => 'note', 'leaveFromTime' => 'start time', 'leaveToTime' => 'end time']);
        if ($this->leaveFromTime && $this->leaveTo && $this->leaveTo !== $this->leaveFrom) {
            $this->addError('leaveToTime', 'Leave for part of a day covers one day only. Clear the last day or the times.');

            return;
        }

        $staff = AttendanceStaff::query()->findOrFail($this->staffId);
        if ($clash = AttendanceLeave::clash($staff->id, $this->leaveFrom, $this->leaveTo ?: $this->leaveFrom, $this->leaveFromTime ?: null, $this->leaveToTime ?: null)) {
            $this->addError('leaveFrom', 'They already have leave then: '.$clash->when().' ('.$clash->typeName().', '.strtolower(AttendanceLeave::STATUSES[$clash->status]).'). Remove that one first, or pick other days.');

            return;
        }

        $leave = new AttendanceLeave([
            'attendance_staff_id' => $staff->id,
            'starts_on' => $this->leaveFrom,
            'ends_on' => $this->leaveTo ?: $this->leaveFrom,
            'from_time' => $this->leaveFromTime ?: null,
            'to_time' => $this->leaveToTime ?: null,
            'type' => $this->leaveType,
            'note' => trim($this->leaveNote) ?: null,
            'created_by' => auth()->id(),
        ]);
        // A manager may go past the yearly allowance, but is told.
        $balances = app(\App\Services\Attendance\AttendanceLeaveBalances::class);
        $left = $balances->for($staff, (int) substr($this->leaveFrom, 0, 4))[$this->leaveType]['left'] ?? null;
        $days = $balances->daysOf($staff, $leave);
        $leave->save();
        if ($left !== null && $days > $left) {
            $this->dispatch('notify', type: 'warning', message: 'Leave added. It takes '.$balances::days($days).', more than the '.$balances::days(max(0, $left)).' of '.strtolower($leave->typeName()).' they had left this year.');
        }
        $this->reset('leaveFrom', 'leaveTo', 'leaveNote', 'leaveFromTime', 'leaveToTime');
    }

    public function openLeaveTypes(): void
    {
        $this->authorizePart('leave');
        $this->authorizeAccess();
        \App\Models\AttendanceLeaveType::ensureSaved();
        $this->reset('leaveTypeEditId', 'leaveTypeName', 'leaveTypeDays', 'leaveTypePaid');
        $this->resetValidation();
        $this->showLeaveTypes = true;
    }

    public function editLeaveType(int $id): void
    {
        $this->authorizePart('leave');
        $this->authorizeAccess();
        $type = \App\Models\AttendanceLeaveType::query()->findOrFail($id);
        $this->leaveTypeEditId = $type->id;
        $this->leaveTypeName = $type->name;
        $this->leaveTypeDays = $type->days_per_year === null ? '' : (string) (float) $type->days_per_year;
        $this->leaveTypePaid = $type->is_paid;
        $this->resetValidation();
    }

    /** Add a kind of leave, or change one's name, yearly allowance or whether it is paid. */
    public function saveLeaveType(): void
    {
        $this->authorizePart('leave');
        $this->authorizeAccess();
        $this->leaveTypeName = trim($this->leaveTypeName);
        $this->validate([
            'leaveTypeName' => ['required', 'string', 'max:60', \Illuminate\Validation\Rule::unique('attendance_leave_types', 'name')
                ->where('branch_id', app(\App\Support\Tenancy\TenantContext::class)->branchId())->ignore($this->leaveTypeEditId)],
            'leaveTypeDays' => 'nullable|numeric|min:0|max:366',
        ], ['leaveTypeName.unique' => 'There is already a leave type with that name.'], ['leaveTypeName' => 'name', 'leaveTypeDays' => 'days a year']);
        $values = ['name' => $this->leaveTypeName, 'days_per_year' => $this->leaveTypeDays === '' ? null : round((float) $this->leaveTypeDays * 2) / 2, 'is_paid' => $this->leaveTypePaid];
        if ($this->leaveTypeEditId) {
            \App\Models\AttendanceLeaveType::query()->findOrFail($this->leaveTypeEditId)->update($values);
        } else {
            \App\Models\AttendanceLeaveType::create($values + ['code' => \App\Models\AttendanceLeaveType::codeFor($this->leaveTypeName)]);
        }
        $this->reset('leaveTypeEditId', 'leaveTypeName', 'leaveTypeDays', 'leaveTypePaid');
    }

    /** A type no longer offered stays on the leave already given under it. */
    public function toggleLeaveType(int $id): void
    {
        $this->authorizePart('leave');
        $this->authorizeAccess();
        $type = \App\Models\AttendanceLeaveType::query()->findOrFail($id);
        if ($type->is_active && \App\Models\AttendanceLeaveType::query()->where('is_active', true)->count() === 1) {
            $this->addError('leaveTypeName', 'Keep at least one leave type on offer.');

            return;
        }
        $type->update(['is_active' => ! $type->is_active]);
    }

    public function removeLeaveType(int $id): void
    {
        $this->authorizePart('leave');
        $this->authorizeAccess();
        $type = \App\Models\AttendanceLeaveType::query()->findOrFail($id);
        if (AttendanceLeave::query()->where('type', $type->code)->exists()) {
            $this->addError('leaveTypeName', "{$type->name} has been given to someone, so it can't be deleted. Stop offering it instead.");

            return;
        }
        if (\App\Models\AttendanceLeaveType::query()->count() === 1) {
            $this->addError('leaveTypeName', 'Keep at least one leave type.');

            return;
        }
        $type->delete();
    }

    /** Approve or turn down leave a staff member asked for; they are told by email. */
    public function reviewLeave(int $id, bool $approve, OwnerAlerts $alerts): void
    {
        $this->authorizeView();
        $leave = AttendanceLeave::query()->where('status', AttendanceLeave::PENDING)->findOrFail($id);
        $this->authorizeStaff((int) $leave->attendance_staff_id);
        $leave->update([
            'status' => $approve ? AttendanceLeave::APPROVED : AttendanceLeave::REJECTED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_note' => mb_substr(trim((string) ($this->reviewNotes[$id] ?? '')), 0, 255) ?: null,
        ]);
        unset($this->reviewNotes[$id]);
        $alerts->leaveReviewed($leave);
        $this->dispatch('notify', type: 'success', message: $approve ? 'Leave approved.' : 'Leave not approved.');
    }

    /**
     * Approve one person's overtime on one day, for all of it or fewer minutes, or turn it down.
     * It counts on their hours only once approved.
     */
    public function decideOvertime(int $staffId, string $date, bool $approve, AttendanceAnalyzer $analyzer): void
    {
        $this->authorizePart('overtime');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);
        $staff = $this->authorizeStaff($staffId);
        $day = CarbonImmutable::parse($date);
        $worked = collect($analyzer->range($day, $day)['rows'])->first(fn ($row) => $row['staff']->id === $staff->id)['days'][$date]['overtime_minutes'] ?? 0;
        abort_unless($worked > 0, 404);

        $key = $staffId.'-'.$date;
        $minutes = trim((string) ($this->overtimeMinutes[$key] ?? ''));
        $minutes = $minutes === '' ? $worked : $minutes;
        if ($approve && (! ctype_digit((string) $minutes) || (int) $minutes < 1 || (int) $minutes > $worked)) {
            $this->addError('overtimeMinutes.'.$key, 'Approve between 1 and '.$worked.' minutes, the overtime worked that day.');

            return;
        }
        $note = mb_substr(trim((string) ($this->overtimeNotes[$key] ?? '')), 0, 255) ?: null;
        $decision = \App\Models\AttendanceOvertime::query()->updateOrCreate(['attendance_staff_id' => $staff->id, 'date' => $date], [
            'status' => $approve ? \App\Models\AttendanceOvertime::APPROVED : \App\Models\AttendanceOvertime::DECLINED,
            'minutes' => $approve ? (int) $minutes : 0, 'note' => $note, 'decided_by' => auth()->id(),
        ]);
        \App\Models\AuditTrail::record($approve ? 'attendance.overtime_approved' : 'attendance.overtime_declined',
            ($approve ? 'Approved '.AttendanceAnalyzer::duration((int) $minutes).' of overtime' : 'Did not approve overtime')." for {$staff->name} on ".$day->format('j M Y').($note ? ": {$note}" : ''),
            $decision, [], ['worked_minutes' => $worked, 'approved_minutes' => $decision->minutes], null, true);
        unset($this->overtimeMinutes[$key], $this->overtimeNotes[$key]);
        $this->resetValidation('overtimeMinutes.'.$key);
    }

    /** Take back an answer on overtime, so it waits for one again. */
    public function reopenOvertime(int $staffId, string $date): void
    {
        $this->authorizePart('overtime');
        $this->authorizeStaff($staffId);
        $decision = \App\Models\AttendanceOvertime::query()->where('attendance_staff_id', $staffId)->whereDate('date', $date)->with('staff:id,name')->firstOrFail();
        \App\Models\AuditTrail::record('attendance.overtime_reopened', 'Took back the answer on overtime for '.($decision->staff->name ?? 'someone').' on '.$decision->date->format('j M Y'),
            $decision, ['status' => $decision->status, 'approved_minutes' => $decision->minutes], [], null, true);
        $decision->delete();
    }

    /** Every day in the report with overtime, newest first, with the manager's answer if there is one. */
    private function overtimeList(array $report): array
    {
        $notes = \App\Models\AttendanceOvertime::query()->whereBetween('date', [array_key_first($report['days']), array_key_last($report['days'])])
            ->with('decider:id,name')->get()->keyBy(fn ($entry) => $entry->attendance_staff_id.'-'.$entry->date->format('Y-m-d'));
        $list = [];
        foreach ($report['rows'] as $row) {
            if (! $row['staff']->is_tracked) {
                continue;
            }
            foreach ($row['days'] as $key => $day) {
                if ($day['overtime_minutes'] > 0) {
                    $list[] = ['key' => $row['staff']->id.'-'.$key, 'staff' => $row['staff'], 'date' => $report['days'][$key], 'day' => $day,
                        'decision' => $notes[$row['staff']->id.'-'.$key] ?? null];
                }
            }
        }
        usort($list, fn ($a, $b) => [$a['day']['overtime_status'] !== 'pending', $b['date'], $a['staff']->name] <=> [$b['day']['overtime_status'] !== 'pending', $a['date'], $b['staff']->name]);

        return $list;
    }

    public function removeLeave(int $id): void
    {
        $this->authorizeStaff((int) $this->staffId);
        AttendanceLeave::query()->where('attendance_staff_id', $this->staffId)->whereKey($id)->delete();
    }

    /** For each request waiting: the working days it takes and what the person has left of that type. */
    private function requestDays(): array
    {
        $balances = app(\App\Services\Attendance\AttendanceLeaveBalances::class);
        $days = [];
        $only = \App\Support\AttendanceScope::staffIds(auth()->user(), $this->department);
        foreach (AttendanceLeave::query()->where('status', AttendanceLeave::PENDING)->when($only !== null, fn ($query) => $query->whereIn('attendance_staff_id', $only))->with('staff')->get() as $request) {
            if (! $request->staff) {
                continue;
            }
            $balance = $balances->for($request->staff, $request->starts_on->year)[$request->type] ?? null;
            // 'left' already has this request taken off; before is what they have without it.
            $days[$request->id] = ['days' => $take = $balances->daysOf($request->staff, $request),
                'before' => $balance && $balance['left'] !== null ? $balance['left'] + $take : null];
        }

        return $days;
    }

    public function openPhone(): void
    {
        $this->authorizePart('phone');
        $this->authorizeAccess();
        $settings = AttendanceSetting::current();
        $this->phoneOn = (bool) $settings->phone_clock_in;
        $this->phoneLatitude = $settings->clock_in_latitude === null ? '' : (string) $settings->clock_in_latitude;
        $this->phoneLongitude = $settings->clock_in_longitude === null ? '' : (string) $settings->clock_in_longitude;
        $this->phoneRadius = (string) ($settings->clock_in_radius ?: 150);
        $this->phoneUserId = '';
        $this->resetValidation();
        $this->showPhone = true;
    }

    public function savePhone(): void
    {
        $this->authorizePart('phone');
        $this->authorizeAccess();
        $this->validate([
            'phoneLatitude' => [$this->phoneOn ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'phoneLongitude' => [$this->phoneOn ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
            'phoneRadius' => 'required|integer|min:30|max:5000',
        ], ['phoneLatitude.required' => 'Say where the branch is first.', 'phoneLongitude.required' => 'Say where the branch is first.'],
            ['phoneLatitude' => 'latitude', 'phoneLongitude' => 'longitude', 'phoneRadius' => 'distance']);
        $settings = AttendanceSetting::current();
        $settings->fill([
            'phone_clock_in' => $this->phoneOn,
            // Days count from the day it is switched on.
            'phone_clock_in_since' => $this->phoneOn ? ($settings->phone_clock_in ? $settings->phone_clock_in_since : CarbonImmutable::now($this->timezone())->format('Y-m-d')) : null,
            'clock_in_latitude' => $this->phoneLatitude === '' ? null : round((float) $this->phoneLatitude, 7),
            'clock_in_longitude' => $this->phoneLongitude === '' ? null : round((float) $this->phoneLongitude, 7),
            'clock_in_radius' => (int) $this->phoneRadius,
        ])->save();
        $this->dispatch('notify', type: 'success', message: $this->phoneOn ? 'Staff can now clock in from their phones at the branch.' : 'Phone clock-in is off.');
    }

    /** Someone with a user account but not on a terminal: follow them so they can clock in by phone. */
    public function addPhonePerson(): void
    {
        $this->authorizePart('phone');
        $this->authorizeAccess();
        $user = User::query()->inCurrentClinic()->whereKey((int) $this->phoneUserId)->first();
        if (! $user) {
            $this->addError('phoneUserId', "Choose one of this clinic's users.");

            return;
        }
        if (AttendanceStaff::query()->where('user_id', $user->id)->exists()) {
            $this->addError('phoneUserId', "{$user->name} is already followed here.");

            return;
        }
        if (! \App\Support\AttendancePlan::canFollow()) {
            $this->addError('phoneUserId', 'Your plan follows up to '.\App\Support\AttendancePlan::staffLimit().' staff, and that many are followed.');

            return;
        }
        AttendanceStaff::create(['employee_no' => 'APP-'.$user->id, 'name' => $user->name, 'user_id' => $user->id, 'is_tracked' => true,
            'starts_on' => CarbonImmutable::now($this->timezone())->format('Y-m-d')]);
        $this->phoneUserId = '';
    }

    public function openPayroll(): void
    {
        $this->authorizePart('payroll');
        $this->authorizePay();
        $settings = AttendanceSetting::current();
        $this->overtimeMultiplier = rtrim(rtrim(number_format((float) ($settings->overtime_multiplier ?? 1.5), 2, '.', ''), '0'), '.');
        $this->deductLateness = (bool) ($settings->deduct_lateness ?? false);
        $this->deductAbsence = (bool) ($settings->deduct_absence ?? true);
        $this->pay = [];
        foreach (AttendanceStaff::query()->where('is_tracked', true)->get() as $staff) {
            $this->pay[$staff->id] = ['type' => (string) $staff->pay_type, 'rate' => $staff->pay_rate === null ? '' : rtrim(rtrim(number_format((float) $staff->pay_rate, 2, '.', ''), '0'), '.')];
        }
        $this->resetValidation();
        $this->showPayroll = true;
    }

    /** Save the pay rules and how each person is paid; the figures below are worked out again. */
    public function savePayroll(): void
    {
        $this->authorizePart('payroll');
        $this->authorizePay();
        $this->validate([
            'overtimeMultiplier' => 'required|numeric|min:1|max:5',
            'pay.*.type' => 'nullable|in:monthly,hourly',
            'pay.*.rate' => 'nullable|numeric|min:0|max:99999999',
        ], [], ['overtimeMultiplier' => 'overtime rate', 'pay.*.type' => 'pay type', 'pay.*.rate' => 'rate']);
        foreach ($this->pay as $id => $pay) {
            if (($pay['type'] ?? '') !== '' && trim((string) ($pay['rate'] ?? '')) === '') {
                $this->addError("pay.{$id}.rate", 'Enter the '.($pay['type'] === 'hourly' ? 'pay for an hour' : 'monthly salary').'.');
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $settings = AttendanceSetting::current();
        $settings->fill(['overtime_multiplier' => round((float) $this->overtimeMultiplier, 2), 'deduct_lateness' => $this->deductLateness, 'deduct_absence' => $this->deductAbsence])->save();
        foreach (AttendanceStaff::query()->whereKey(array_keys($this->pay))->get() as $staff) {
            $pay = $this->pay[$staff->id];
            $values = ['pay_type' => ($pay['type'] ?? '') ?: null, 'pay_rate' => ($pay['type'] ?? '') ? round((float) $pay['rate'], 2) : null];
            if ($staff->pay_type !== $values['pay_type'] || (float) $staff->pay_rate !== (float) $values['pay_rate']) {
                \App\Models\AuditTrail::record('attendance.pay_changed', "Changed how {$staff->name} is paid", $staff,
                    ['pay_type' => $staff->pay_type, 'pay_rate' => $staff->pay_rate], $values, null, true);
                $staff->update($values);
            }
        }
        $this->dispatch('notify', type: 'success', message: 'Pay saved.');
    }

    /** Pay is the owner's to see and set: managers run attendance, not salaries. */
    private function authorizePay(): void
    {
        $this->authorizeAccess();
        abort_unless(auth()->user()->hasRole('Super Admin'), 403, 'Only the owner can open payroll.');
    }

    /** How each department did in the days on show, with people in none last. */
    private function departmentSummary(array $report, $departments): array
    {
        $blank = ['staff' => 0, 'expected' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'late_minutes' => 0, 'overtime_minutes' => 0];
        $summary = [];
        foreach ($departments as $department) {
            $summary[$department->id] = ['name' => $department->name, 'key' => (string) $department->id] + $blank;
        }
        $summary['none'] = ['name' => 'No department', 'key' => 'none'] + $blank;
        foreach ($report['rows'] as $row) {
            if (! $row['staff']->is_tracked) {
                continue;
            }
            $key = isset($summary[$row['staff']->attendance_department_id]) ? $row['staff']->attendance_department_id : 'none';
            $summary[$key]['staff']++;
            foreach (['expected', 'present', 'late', 'absent', 'late_minutes', 'overtime_minutes'] as $sum) {
                $summary[$key][$sum] += $row['totals'][$sum];
            }
        }
        if (! $summary['none']['staff']) {
            unset($summary['none']);
        }

        return array_values($summary);
    }

    /** Setting up the branch's attendance: the owner and managers only. */
    /** Parts of attendance the clinic hasn't got, with what each does and costs as an add-on. */
    private function lockedParts(array $extras): array
    {
        $keys = ['shifts' => \App\Support\Feature::ATTENDANCE_SHIFTS, 'overtime' => \App\Support\Feature::ATTENDANCE_OVERTIME, 'leave' => \App\Support\Feature::ATTENDANCE_LEAVE,
            'payroll' => \App\Support\Feature::ATTENDANCE_PAYROLL, 'phone' => \App\Support\Feature::ATTENDANCE_PHONE, 'trends' => \App\Support\Feature::ATTENDANCE_TRENDS,
            'hospital' => \App\Support\Feature::ATTENDANCE_HOSPITAL];
        $clinic = app(\App\Support\Tenancy\TenantContext::class)->clinic();
        $catalogue = $clinic && app(\App\Services\ClinicAccessService::class)->hosted($clinic) ? app(\App\Services\ClinicAddonService::class)->catalogue($clinic) : collect();
        $asked = $clinic ? \App\Models\ClinicAddonRequest::query()->where('clinic_id', $clinic->id)->where('status', 'pending')->pluck('feature')->all() : [];
        $locked = [];
        foreach ($keys as $part => $feature) {
            if (! $extras[$part]) {
                $offer = $catalogue->get($feature);
                $locked[] = ['feature' => $feature, 'name' => ucfirst(\Illuminate\Support\Str::after(\App\Support\PlanProduct::EXTRAS[$feature][0], 'Attendance: ')),
                    'about' => \App\Support\PlanProduct::ATTENDANCE_PART_DESCRIPTIONS[$feature], 'price' => $offer && $offer['offered'] ? $offer['price'] : null,
                    'currency' => $offer['currency'] ?? null, 'asked' => in_array($feature, $asked, true)];
            }
        }

        return $locked;
    }

    /** A pack of extra staff, if the platform offers them to this clinic: [size, price, currency, asked]. */
    private function staffPackOffer(): ?array
    {
        $clinic = app(\App\Support\Tenancy\TenantContext::class)->clinic();
        if (! $clinic || ! app(\App\Services\ClinicAccessService::class)->hosted($clinic)) {
            return null;
        }
        $offer = app(\App\Services\ClinicAddonService::class)->catalogue($clinic)->get(\App\Support\Feature::ATTENDANCE_STAFF_PACK);

        return $offer && $offer['offered'] ? ['size' => $offer['pack_size'], 'price' => $offer['price'], 'currency' => $offer['currency'],
            'asked' => \App\Models\ClinicAddonRequest::query()->where('clinic_id', $clinic->id)->where('feature', \App\Support\Feature::ATTENDANCE_STAFF_PACK)->where('status', 'pending')->exists()] : null;
    }

    /** Ask the platform for a part of attendance as an add-on (the owner only). */
    public function requestPart(string $feature, \App\Services\ClinicAddonService $addons): void
    {
        $this->authorizeAccess();
        abort_unless(auth()->user()->hasRole('Super Admin'), 403, 'Only the owner can ask for add-ons.');
        abort_unless(in_array($feature, [...\App\Support\PlanProduct::ATTENDANCE_PARTS, \App\Support\Feature::ATTENDANCE_STAFF_PACK], true), 404);
        $addons->request(app(\App\Support\Tenancy\TenantContext::class)->clinic(), $feature, 'Asked for from the Attendance page', auth()->user());
        $this->dispatch('notify', type: 'success', message: 'Request sent. You will hear once it is switched on.');
    }

    /** [first day, last day] on show. */
    private function period(): array
    {
        return AttendancePeriod::resolve($this->from, $this->to, CarbonImmutable::now($this->timezone())->startOfDay());
    }

    public function render(AttendanceAnalyzer $analyzer)
    {
        [$first, $last] = $this->period();
        $timezone = $this->timezone();
        // Whose attendance this person sees: everyone, one department, or a head's own people.
        $user = auth()->user();
        $manager = \App\Support\AttendanceScope::manager($user);
        $headed = $manager ? [] : \App\Support\AttendanceScope::headed($user);
        // What the plan includes; a part not included is not shown (see "More attendance features").
        $extras = \App\Support\AttendancePlan::parts();
        if ($this->view === 'trends' && ! $extras['trends']) {
            $this->view = 'summary';
        }
        // Departments are a hospital tool: without it none are shown, even if some were made before.
        $hospital = $extras['hospital'];
        $departments = $hospital ? \App\Models\AttendanceDepartment::query()->withCount('staff')->orderBy('name')->get() : collect();
        $visible = $manager ? $departments : $departments->whereIn('id', $headed)->values();
        if ($this->department !== '' && ! ($manager && $this->department === 'none') && ! $visible->contains('id', (int) $this->department)) {
            $this->department = '';
        }
        $only = \App\Support\AttendanceScope::staffIds($user, $this->department);
        $analyzer = $analyzer->forStaff($only);
        // A head can be shown nobody's window but their own people's.
        if ($only !== null) {
            $this->showStaff = $this->showStaff && in_array($this->staffId, $only, true);
            $this->showPunches = $this->showPunches && in_array($this->punchStaffId, $only, true);
        }
        $report = $analyzer->range($first, $last);

        $rows = array_values(array_filter($report['rows'], fn ($row) => $this->matches($row['staff']) && match ($this->show) {
            'late' => $row['staff']->is_tracked && $row['totals']['late'] > 0,
            'absent' => $row['staff']->is_tracked && $row['totals']['absent'] > 0,
            'attention' => $row['staff']->is_tracked && self::issues($row['totals']) > 0,
            default => true,
        }));
        $sort = $this->sort ?: ($report['totals']['staff'] > self::LARGE_STAFF ? 'issues' : 'name');
        $rows = $this->sorted($rows, $sort);
        [$rows, $pager] = $this->paged($rows, $this->page);

        $trends = $this->view === 'trends' ? app(\App\Services\Attendance\AttendanceTrends::class)->only($only)->months(min($last, CarbonImmutable::now($timezone)->startOfDay())) : null;
        if ($trends) {
            [$trends['people'], $pager] = $this->paged(array_values(array_filter($trends['people'], fn ($person) => $this->matches($person['staff']))), $this->page);
        }
        $payroll = $this->showPayroll && $user?->hasRole('Super Admin') ? app(\App\Services\Attendance\AttendancePayroll::class)->only($only)->for($first, $last) : null;
        $overtime = $this->overtimeList($report);

        // The live board needs a terminal that sends punches as they happen, or phones.
        $board = $report['settings']->phone_clock_in || AttendanceDevice::query()->where('is_active', true)->whereNotNull('last_seen_at')->exists()
            ? $analyzer->today(CarbonImmutable::now($timezone)) : [];
        $shortNow = $board ? $this->shortNow($board, CarbonImmutable::now($timezone)) : [];
        // The page opens on Today where there is a live board, else on the report.
        if (! in_array($this->tab, ['today', 'report', 'requests'], true) || ($this->tab === 'today' && ! $board)) {
            $this->tab = $board ? 'today' : 'report';
        }

        return view('livewire.admin.attendance-component', [
            'first' => $first,
            'last' => $last,
            'periodLabel' => AttendancePeriod::label($first, $last),
            // Six months up to the last day on show.
            'manager' => $manager,
            'hospital' => $hospital,
            'extras' => $extras,
            'locked' => $manager ? $this->lockedParts($extras) : [],
            'staffLimit' => $manager ? \App\Support\AttendancePlan::staffLimit() : null,
            'staffFollowed' => $manager ? \App\Support\AttendancePlan::followed() : 0,
            'notFollowed' => $manager ? AttendanceStaff::query()->where('is_tracked', false)->count() : 0,
            'staffPack' => $manager ? $this->staffPackOffer() : null,
            'departments' => $visible,
            'departmentSummary' => $manager && $departments->isNotEmpty() && $this->department === '' && $this->view === 'summary' ? $this->departmentSummary($report, $departments) : [],
            'trends' => $trends,
            'pager' => $pager,
            'sort' => $sort,
            'oneDay' => $first->eq($last),
            'calendar' => $this->view === 'grid' ? $this->calendar($report, $first, $last, CarbonImmutable::now($timezone)) : null,
            'dayList' => $this->showDay ? $this->dayList($report) : null,
            'punchDay' => $this->showPunches ? $this->punchDay($analyzer, $report) : null,
            'shifts' => \App\Models\AttendanceShift::query()->orderBy('starts_at')->orderBy('name')->get(),
            'report' => $report,
            'canPay' => (bool) auth()->user()?->hasRole('Super Admin'),
            // Payroll gets its own button once anyone has been given a salary or an hourly rate.
            'paysStaff' => AttendanceStaff::query()->whereNotNull('pay_type')->exists(),
            'payroll' => $payroll,
            'payrollTotal' => $payroll ? array_sum(array_map(fn ($line) => $line['gross'] ?? 0, $payroll['rows'])) : 0,
            'payrollPage' => $payroll ? $this->paged(array_values(array_filter($payroll['rows'], fn ($line) => $this->matches($line['staff']))), $this->payPage) : [[], null],
            'currency' => \App\Models\Setting::currency(),
            // Those waiting come first; a long list shows its first fifty.
            'overtime' => array_slice($overtime, 0, 50),
            'overtimeCount' => count($overtime),
            'overtimeWaiting' => count(array_filter($overtime, fn ($item) => $item['day']['overtime_status'] === 'pending')),
            'rows' => $rows,
            'detail' => $this->showStaff ? collect($report['rows'])->first(fn ($row) => $row['staff']->id === $this->staffId) : null,
            'leaveTypes' => \App\Models\AttendanceLeaveType::options(),
            'balances' => $this->showStaff && $extras['leave'] && ($person = AttendanceStaff::query()->find($this->staffId))
                ? app(\App\Services\Attendance\AttendanceLeaveBalances::class)->for($person, $last->year) : [],
            'balanceYear' => $last->year,
            'requestDays' => $this->showRequests ? $this->requestDays() : [],
            'leaves' => $this->showStaff ? AttendanceLeave::query()->where('attendance_staff_id', $this->staffId)->orderByDesc('starts_on')->limit(20)->get() : collect(),
            'accounts' => $this->showStaff ? User::query()->inCurrentClinic()->orderBy('name')->get(['id', 'name']) : collect(),
            'holidayList' => $this->showHolidays ? AttendanceHoliday::query()->whereYear('date', $last->year)->orderBy('date')->get() : collect(),
            'imports' => $this->showUpload ? AttendanceImport::query()->with('uploader:id,name')->latest('id')->limit(5)->get() : collect(),
            'hasData' => AttendanceStaff::query()->exists() || AttendanceDevice::query()->exists(),
            'phoneAccounts' => $this->showPhone ? User::query()->inCurrentClinic()->whereNotIn('id', AttendanceStaff::query()->whereNotNull('user_id')->pluck('user_id'))->orderBy('name')->get(['id', 'name']) : collect(),
            'phonePeople' => $this->showPhone ? AttendanceStaff::query()->where('is_tracked', true)->orderBy('name')->get(['id', 'name', 'user_id']) : collect(),
            'devices' => $this->showDevices ? AttendanceDevice::query()->orderBy('id')->get() : collect(),
            'silent' => $manager ? AttendanceDevice::silent(now()->setTimezone($timezone)) : collect(),
            'board' => $board,
            'shortNow' => $shortNow,
            'boardRows' => $board ? $this->boardRows($board, $departments, $shortNow) : [],
            'today' => CarbonImmutable::now($timezone),
            'requests' => AttendanceLeave::query()->where('status', AttendanceLeave::PENDING)->when($only !== null, fn ($query) => $query->whereIn('attendance_staff_id', $only))
                ->with('staff:id,name')->orderBy('starts_on')->get(),
            'timezone' => $timezone,
        ])->layout(...($manager ? ['layouts.admin.admin-layout'] : \App\Support\ClinicNavigation::sharedLayout()));
    }
}
