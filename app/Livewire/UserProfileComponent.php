<?php

namespace App\Livewire;

use App\Models\LoginLog;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Auth;

class UserProfileComponent extends Component
{
    use WithFileUploads;

    // Basic
    public string $name                  = '';
    public string $email                 = '';
    public string $password              = '';
    public string $password_confirmation = '';

    // Extended profile (editable by user)
    public string $phone         = '';
    public string $gender        = '';
    public string $date_of_birth = '';

    // Admin-managed (view-only for user)
    public string $staff_id   = '';
    public string $department = '';
    public string $hire_date  = '';

    // Avatar
    public $avatar = null; // temporary upload

    public string $activeTab = 'account';

    // My attendance → ask for time off.
    public string $leaveKind = 'days';
    public string $leaveFrom = '';
    public string $leaveTo = '';
    public string $leaveFromTime = '';
    public string $leaveToTime = '';
    public string $leaveType = 'annual';
    public string $leaveNote = '';

    public function mount(): void
    {
        $user                 = Auth::user();
        $this->name           = $user->name;
        $this->email          = $user->email;
        $this->phone          = $user->phone          ?? '';
        $this->gender         = $user->gender         ?? '';
        $this->date_of_birth  = $user->date_of_birth  ? $user->date_of_birth->format('Y-m-d') : '';
        // Staff IDs belong to a clinic membership; show the one for the current clinic.
        $clinicId = app(\App\Support\Tenancy\TenantContext::class)->clinicId();
        $this->staff_id       = (string) ($clinicId ? \Illuminate\Support\Facades\DB::table('clinic_user')->where('user_id', $user->id)
            ->where('clinic_id', $clinicId)->value('staff_identifier') : '');
        $this->department     = $user->department     ?? '';
        $this->hire_date      = $user->hire_date      ? $user->hire_date->format('Y-m-d') : '';
    }

    protected function rules(): array
    {
        return [
            'name'          => 'required|string|max:255',
            'phone'         => 'nullable|string|max:25',
            'gender'        => 'nullable|in:Male,Female,Other',
            'date_of_birth' => 'nullable|date|before:today',
            'password'      => ['nullable', 'confirmed', Password::min(6)],
            'avatar'        => 'nullable|image|max:2048',
        ];
    }

    public function updateProfile(): void
    {
        $this->validate();

        $user         = Auth::user();
        $user->name   = $this->name;
        $user->phone  = $this->phone  ?: null;
        $user->gender = $this->gender ?: null;
        $user->date_of_birth = $this->date_of_birth ?: null;

        if ($this->password) {
            $user->password                   = Hash::make($this->password);
            $user->last_password_changed_at   = now();
        }

        if ($this->avatar) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $user->avatar = $this->avatar->store('avatars', 'public');
            $this->avatar = null;
        }

        $user->save();
        $this->reset(['password', 'password_confirmation']);

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => 'Profile updated successfully!',
        ]);
    }

    public function removeAvatar(): void
    {
        $user = Auth::user();
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->avatar = null;
            $user->save();
        }
        $this->avatar = null;
        $this->dispatch('notify', ...['type' => 'info', 'message' => 'Avatar removed.']);
    }

    /** Ask for time off: whole days, a morning, an afternoon or a few hours. A manager answers it. */
    public function requestLeave(\App\Services\OwnerAlerts $alerts): void
    {
        abort_unless(\App\Services\LicenseService::has(\App\Support\Feature::ATTENDANCE), 403);
        $staff = \App\Models\AttendanceStaff::query()->where('user_id', Auth::id())->where('is_tracked', true)->firstOrFail();
        $part = $this->leaveKind !== 'days';
        $this->validate([
            'leaveKind' => 'required|in:days,morning,afternoon,hours',
            'leaveFrom' => 'required|date|after_or_equal:today',
            'leaveTo' => $part ? 'nullable' : 'nullable|date|after_or_equal:leaveFrom',
            'leaveFromTime' => $this->leaveKind === 'hours' ? 'required|date_format:H:i' : 'nullable',
            'leaveToTime' => $this->leaveKind === 'hours' ? 'required|date_format:H:i|after:leaveFromTime' : 'nullable',
            'leaveType' => 'required|in:'.implode(',', array_keys(\App\Models\AttendanceLeave::TYPES)),
            'leaveNote' => 'nullable|string|max:255',
        ], ['leaveToTime.after' => 'The end time must be after the start time.', 'leaveFrom.after_or_equal' => 'Choose today or a later day.'],
            ['leaveFrom' => $part ? 'day' : 'first day', 'leaveTo' => 'last day', 'leaveFromTime' => 'start time', 'leaveToTime' => 'end time', 'leaveType' => 'type', 'leaveNote' => 'reason']);

        [$from, $to] = [$this->leaveFromTime ?: null, $this->leaveToTime ?: null];
        if (in_array($this->leaveKind, ['morning', 'afternoon'], true)) {
            // Half of their working day on that date.
            $weekday = \Carbon\CarbonImmutable::parse($this->leaveFrom)->dayOfWeekIso;
            $hours = $staff->hours ? \App\Models\AttendanceSetting::dayHours($staff->hours, $weekday) : \App\Models\AttendanceSetting::current()->hoursOn($weekday);
            if (! $hours) {
                $this->addError('leaveFrom', 'That is not one of your working days.');

                return;
            }
            $minutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
            $middle = \App\Services\Attendance\AttendanceAnalyzer::clock(intdiv($minutes($hours['start']) + $minutes($hours['end']), 2));
            [$from, $to] = $this->leaveKind === 'morning' ? [$hours['start'], $middle] : [$middle, $hours['end']];
        }

        $leave = \App\Models\AttendanceLeave::create([
            'attendance_staff_id' => $staff->id,
            'starts_on' => $this->leaveFrom,
            'ends_on' => $part ? $this->leaveFrom : ($this->leaveTo ?: $this->leaveFrom),
            'from_time' => $part ? $from : null,
            'to_time' => $part ? $to : null,
            'type' => $this->leaveType,
            'note' => trim($this->leaveNote) ?: null,
            'status' => \App\Models\AttendanceLeave::PENDING,
            'created_by' => Auth::id(),
            'requested_by' => Auth::id(),
        ]);
        $alerts->leaveRequested($leave);
        $this->reset('leaveKind', 'leaveFrom', 'leaveTo', 'leaveFromTime', 'leaveToTime', 'leaveType', 'leaveNote');
        $this->dispatch('notify', type: 'success', message: 'Request sent. You will be told when it is answered.');
    }

    /** Take back a request that has not been answered yet. */
    public function withdrawLeave(int $id): void
    {
        \App\Models\AttendanceLeave::query()->where('requested_by', Auth::id())->where('status', \App\Models\AttendanceLeave::PENDING)->whereKey($id)->delete();
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function render()
    {
        $user        = Auth::user()->fresh();
        $allLoginLogs = LoginLog::where('user_id', $user->id)->orderByDesc('login_at')->get();
        $loginLogs    = $allLoginLogs->take(8);
        $totalLogins  = $allLoginLogs->count();
        $lastLogin    = $allLoginLogs->skip(1)->first();
        $accountAge  = (int) $user->created_at->diffInDays(now()); // Carbon 3 returns a fraction
        // Linked to someone on this branch's attendance terminal: they can see their own month.
        $onTerminal = \App\Services\LicenseService::has(\App\Support\Feature::ATTENDANCE)
            ? \App\Models\AttendanceStaff::query()->where('user_id', $user->id)->where('is_tracked', true)->first() : null;
        $mine = null;
        if ($onTerminal && $this->activeTab === 'attendance') {
            $latest = \App\Models\AttendanceImport::query()->latest('id')->value('period_start');
            $month = ($latest ? \Carbon\CarbonImmutable::parse($latest) : \Carbon\CarbonImmutable::now())->startOfMonth();
            $report = app(\App\Services\Attendance\AttendanceAnalyzer::class)->month($month);
            $mine = ['month' => $month, 'report' => $report, 'row' => collect($report['rows'])->first(fn ($row) => $row['staff']->id === $onTerminal->id),
                'requests' => \App\Models\AttendanceLeave::query()->where('attendance_staff_id', $onTerminal->id)->where('requested_by', $user->id)->latest('id')->limit(10)->get()];
        }

        return view('livewire.user-profile-component', [
            'user'        => $user,
            'roles'       => $user->getRoleNames(),
            'loginLogs'   => $loginLogs,
            'totalLogins' => $totalLogins,
            'lastLogin'   => $lastLogin,
            'accountAge'  => $accountAge,
            'onTerminal'  => (bool) $onTerminal,
            'mine'        => $mine,
        ])->layout(...\App\Support\ClinicNavigation::sharedLayout());
    }
}
