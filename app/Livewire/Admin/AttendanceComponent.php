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
    use WithFileUploads;

    // The days on show, 'Y-m-d' both; the page opens on this month to date.
    #[Url]
    public string $from = '';
    #[Url]
    public string $to = '';
    #[Url]
    public string $view = 'summary';
    public string $show = 'all';

    // Day by day: the month on the calendar ('Y-m') and the day whose list is open ('Y-m-d').
    public string $calendarMonth = '';
    public string $dayKey = '';
    public bool $showDay = false;

    public bool $showUpload = false;
    public $file = null;

    public bool $showHours = false;
    public array $hours = [];
    public int|string $graceMinutes = 15;
    public int|string $breakMinutes = 60;

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
    public array $reviewNotes = [];

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
        $this->authorizeAccess();
        // Links made before the page took two dates name a whole month.
        if ($month = AttendancePeriod::month(request()->query('month'))) {
            [$this->from, $this->to] = [$month[0]->format('Y-m-d'), $month[1]->format('Y-m-d')];
        }
        [$first, $last] = $this->period();
        [$this->from, $this->to] = [$first->format('Y-m-d'), $last->format('Y-m-d')];
        if (! in_array($this->view, ['summary', 'grid'], true)) {
            $this->view = 'summary';
        }
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

    /** One person's punches on one day, to add a missed punch, change a time or remove a mistake. */
    public function openPunches(int $staffId, string $date): void
    {
        $this->authorizeAccess();
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && AttendanceStaff::query()->whereKey($staffId)->exists(), 404);
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
        $this->authorizeAccess();
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
        $this->authorizeAccess();
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
        $this->authorizeAccess();
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
            'punches' => \App\Models\AttendancePunch::withVoided()->with(['creator:id,name', 'voider:id,name'])
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

    public function editHours(): void
    {
        $this->authorizeAccess();
        $settings = AttendanceSetting::current();
        $this->hours = $this->hoursForm($settings->hours ?? []);
        $this->graceMinutes = $settings->grace_minutes;
        $this->breakMinutes = $settings->break_minutes ?? 60;
        $this->resetValidation();
        $this->showHours = true;
    }

    public function saveHours(): void
    {
        $this->authorizeAccess();
        $this->validate([
            'graceMinutes' => 'required|integer|min:0|max:240',
            'breakMinutes' => 'required|integer|min:0|max:480',
        ], [], ['graceMinutes' => 'grace period', 'breakMinutes' => 'break allowed']);
        if (! $hours = $this->hoursFrom('hours')) {
            return;
        }

        $settings = AttendanceSetting::current();
        $settings->fill(['hours' => $hours, 'grace_minutes' => (int) $this->graceMinutes, 'break_minutes' => (int) $this->breakMinutes])->save();
        $this->showHours = false;
        $this->dispatch('notify', type: 'success', message: 'Working hours saved.');
    }

    /** A stored hours list as the seven rows of the form. */
    private function hoursForm(array $stored): array
    {
        $form = [];
        foreach (AttendanceSetting::DAYS as $weekday => $name) {
            $day = AttendanceSetting::dayHours($stored, $weekday);
            $form[$weekday] = ['on' => (bool) $day, 'start' => $day['start'] ?? '08:00', 'end' => $day['end'] ?? '17:00'];
        }

        return $form;
    }

    /** The ticked days of an hours form as stored, or null (with the error shown) when it cannot be saved. */
    private function hoursFrom(string $property): ?array
    {
        $this->validate([
            "$property.*.start" => 'required|date_format:H:i',
            "$property.*.end" => 'required|date_format:H:i',
        ], [], ["$property.*.start" => 'start time', "$property.*.end" => 'end time']);

        $hours = [];
        foreach (AttendanceSetting::DAYS as $weekday => $name) {
            $day = $this->{$property}[$weekday] ?? null;
            if (empty($day['on'])) {
                continue;
            }
            if ($day['end'] <= $day['start']) {
                $this->addError("$property.$weekday.end", "$name must end after it starts.");

                return null;
            }
            $hours[$weekday] = ['start' => $day['start'], 'end' => $day['end']];
        }
        if (! $hours) {
            $this->addError($property, 'Keep at least one working day.');

            return null;
        }

        return $hours;
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
        $staff = AttendanceStaff::query()->findOrFail($id);
        $this->staffId = $staff->id;
        $this->staffTracked = $staff->is_tracked;
        $this->staffStartsOn = $staff->starts_on?->format('Y-m-d') ?? '';
        $this->staffUserId = (string) ($staff->user_id ?? '');
        $this->staffOwnHours = (bool) $staff->hours;
        // Their own hours start as a copy of the branch's, to adjust.
        $this->staffHours = $this->hoursForm($staff->hours ?: (AttendanceSetting::current()->hours ?? []));
        $this->reset('leaveFrom', 'leaveTo', 'leaveType', 'leaveNote', 'leaveFromTime', 'leaveToTime');
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

        AttendanceStaff::query()->findOrFail($this->staffId)->update([
            'is_tracked' => $this->staffTracked,
            'starts_on' => $this->staffStartsOn ?: null,
            'hours' => $hours,
            'user_id' => $userId,
        ]);
        $this->dispatch('notify', type: 'success', message: 'Saved.');
    }

    public function addLeave(): void
    {
        $this->authorizeAccess();
        $this->validate([
            'leaveFrom' => 'required|date',
            'leaveTo' => 'nullable|date|after_or_equal:leaveFrom',
            'leaveType' => 'required|in:'.implode(',', array_keys(AttendanceLeave::TYPES)),
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

        AttendanceLeave::create([
            'attendance_staff_id' => AttendanceStaff::query()->findOrFail($this->staffId)->id,
            'starts_on' => $this->leaveFrom,
            'ends_on' => $this->leaveTo ?: $this->leaveFrom,
            'from_time' => $this->leaveFromTime ?: null,
            'to_time' => $this->leaveToTime ?: null,
            'type' => $this->leaveType,
            'note' => trim($this->leaveNote) ?: null,
            'created_by' => auth()->id(),
        ]);
        $this->reset('leaveFrom', 'leaveTo', 'leaveNote', 'leaveFromTime', 'leaveToTime');
    }

    /** Approve or turn down leave a staff member asked for; they are told by email. */
    public function reviewLeave(int $id, bool $approve, OwnerAlerts $alerts): void
    {
        $this->authorizeAccess();
        $leave = AttendanceLeave::query()->where('status', AttendanceLeave::PENDING)->findOrFail($id);
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

    public function removeLeave(int $id): void
    {
        $this->authorizeAccess();
        AttendanceLeave::query()->where('attendance_staff_id', $this->staffId)->whereKey($id)->delete();
    }

    private function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Super Admin', 'Manager']), 403);
        abort_unless(\App\Services\LicenseService::has(\App\Support\Feature::ATTENDANCE), 403, 'Staff attendance is not included in your subscription.');
    }

    /** [first day, last day] on show. */
    private function period(): array
    {
        return AttendancePeriod::resolve($this->from, $this->to, CarbonImmutable::now($this->timezone())->startOfDay());
    }

    private function timezone(): string
    {
        return app(\App\Support\Tenancy\TenantContext::class)->clinic()?->default_timezone ?: config('app.timezone');
    }

    public function render(AttendanceAnalyzer $analyzer)
    {
        [$first, $last] = $this->period();
        $report = $analyzer->range($first, $last);
        $timezone = $this->timezone();

        $rows = array_values(array_filter($report['rows'], fn ($row) => match ($this->show) {
            'late' => $row['staff']->is_tracked && $row['totals']['late'] > 0,
            'absent' => $row['staff']->is_tracked && $row['totals']['absent'] > 0,
            default => true,
        }));

        return view('livewire.admin.attendance-component', [
            'first' => $first,
            'last' => $last,
            'periodLabel' => AttendancePeriod::label($first, $last),
            'calendar' => $this->view === 'grid' ? $this->calendar($report, $first, $last, CarbonImmutable::now($timezone)) : null,
            'dayList' => $this->showDay ? $this->dayList($report) : null,
            'punchDay' => $this->showPunches ? $this->punchDay($analyzer, $report) : null,
            'report' => $report,
            'rows' => $rows,
            'detail' => $this->showStaff ? collect($report['rows'])->first(fn ($row) => $row['staff']->id === $this->staffId) : null,
            'leaves' => $this->showStaff ? AttendanceLeave::query()->where('attendance_staff_id', $this->staffId)->orderByDesc('starts_on')->limit(20)->get() : collect(),
            'accounts' => $this->showStaff ? User::query()->inCurrentClinic()->orderBy('name')->get(['id', 'name']) : collect(),
            'holidayList' => $this->showHolidays ? AttendanceHoliday::query()->whereYear('date', $last->year)->orderBy('date')->get() : collect(),
            'imports' => $this->showUpload ? AttendanceImport::query()->with('uploader:id,name')->latest('id')->limit(5)->get() : collect(),
            'hasData' => AttendanceStaff::query()->exists() || AttendanceDevice::query()->exists(),
            'devices' => $this->showDevices ? AttendanceDevice::query()->orderBy('id')->get() : collect(),
            'silent' => AttendanceDevice::silent(now()->setTimezone($timezone)),
            // The live board needs a terminal that sends punches as they happen.
            'board' => AttendanceDevice::query()->where('is_active', true)->whereNotNull('last_seen_at')->exists()
                ? $analyzer->today(CarbonImmutable::now($timezone)) : [],
            'today' => CarbonImmutable::now($timezone),
            'requests' => AttendanceLeave::query()->where('status', AttendanceLeave::PENDING)->with('staff:id,name')->orderBy('starts_on')->get(),
            'timezone' => $timezone,
        ])->layout('layouts.admin.admin-layout');
    }
}
