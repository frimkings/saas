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
use App\Services\Attendance\AttendanceRecordParser;
use App\Services\OwnerAlerts;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Staff & Security → Attendance: upload the terminal's monthly "Attendance Record" report, then
 * see for the branch who was on time, late or absent, day by day. Working hours, holidays, leave
 * and hours of their own for individual staff are set here too.
 */
class AttendanceComponent extends Component
{
    use WithFileUploads;

    #[Url]
    public string $month = '';
    #[Url]
    public string $view = 'summary';
    public string $show = 'all';

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

    public function mount(): void
    {
        $this->authorizeAccess();
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            // Open on the month last uploaded.
            $latest = AttendanceImport::query()->latest('id')->value('period_start');
            $this->month = ($latest ? CarbonImmutable::parse($latest) : CarbonImmutable::now())->format('Y-m');
        }
        if (! in_array($this->view, ['summary', 'grid'], true)) {
            $this->view = 'summary';
        }
    }

    public function shiftMonth(int $by): void
    {
        $this->month = $this->monthStart()->addMonthsNoOverflow($by <=> 0)->format('Y-m');
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
        $this->month = $import->period_start->format('Y-m');
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
    }

    private function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month) ?: CarbonImmutable::now()->startOfMonth();
    }

    public function render(AttendanceAnalyzer $analyzer)
    {
        $month = $this->monthStart();
        $report = $analyzer->month($month);
        $timezone = app(\App\Support\Tenancy\TenantContext::class)->clinic()?->default_timezone ?: config('app.timezone');

        $rows = array_values(array_filter($report['rows'], fn ($row) => match ($this->show) {
            'late' => $row['staff']->is_tracked && $row['totals']['late'] > 0,
            'absent' => $row['staff']->is_tracked && $row['totals']['absent'] > 0,
            default => true,
        }));

        return view('livewire.admin.attendance-component', [
            'monthStart' => $month,
            'report' => $report,
            'rows' => $rows,
            'detail' => $this->showStaff ? collect($report['rows'])->first(fn ($row) => $row['staff']->id === $this->staffId) : null,
            'leaves' => $this->showStaff ? AttendanceLeave::query()->where('attendance_staff_id', $this->staffId)->orderByDesc('starts_on')->limit(20)->get() : collect(),
            'accounts' => $this->showStaff ? User::query()->inCurrentClinic()->orderBy('name')->get(['id', 'name']) : collect(),
            'holidayList' => $this->showHolidays ? AttendanceHoliday::query()->whereYear('date', $month->year)->orderBy('date')->get() : collect(),
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
