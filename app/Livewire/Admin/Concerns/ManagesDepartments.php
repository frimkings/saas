<?php

namespace App\Livewire\Admin\Concerns;

use App\Models\AttendanceSetting;
use App\Models\AttendanceStaff;
use App\Models\User;

/**
 * Departments: adding and changing one (name, heads, people, minimum cover and rules of its
 * own), deleting one, and making them from the department names the terminal already has.
 */
trait ManagesDepartments
{
    // Departments: the list, each one's heads, people and rules of its own.
    public bool $showDepartments = false;
    public ?int $departmentEditId = null;
    public string $departmentName = '';
    public array $departmentHeads = [];
    public array $departmentMembers = [];
    public bool $departmentOwnRules = false;
    public bool $departmentOwnHours = false;
    public array $departmentHours = [];
    public string $departmentGrace = '';
    public string $departmentBreak = '';
    public string $departmentOvertimeAfter = '';
    /** Shift id => ['min' => string, 'weekend' => string]: how many of the department must be on each shift. */
    public array $departmentCover = [];

    public function openDepartments(): void
    {
        $this->authorizeAccess();
        $this->authorizeHospital();
        $this->reset('departmentEditId', 'departmentName', 'departmentHeads', 'departmentMembers', 'departmentOwnRules', 'departmentOwnHours', 'departmentGrace', 'departmentBreak', 'departmentOvertimeAfter', 'departmentCover');
        $this->departmentHours = $this->hoursForm(AttendanceSetting::current()->hours ?? []);
        $this->resetValidation();
        $this->showDepartments = true;
    }

    public function editDepartment(int $id): void
    {
        $this->authorizeAccess();
        $department = \App\Models\AttendanceDepartment::query()->findOrFail($id);
        $this->departmentEditId = $department->id;
        $this->departmentName = $department->name;
        $this->departmentHeads = array_map('strval', $department->heads ?? []);
        $this->departmentMembers = $department->staff()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->departmentOwnRules = $department->hasOwnRules();
        $this->departmentOwnHours = (bool) $department->hours;
        $this->departmentHours = $this->hoursForm($department->hours ?: (AttendanceSetting::current()->hours ?? []));
        $this->departmentGrace = (string) ($department->grace_minutes ?? '');
        $this->departmentBreak = (string) ($department->break_minutes ?? '');
        $this->departmentOvertimeAfter = (string) ($department->overtime_after_minutes ?? '');
        $this->departmentCover = [];
        foreach (\App\Models\AttendanceCover::query()->where('attendance_department_id', $department->id)->get() as $cover) {
            $this->departmentCover[$cover->attendance_shift_id] = ['min' => (string) $cover->minimum, 'weekend' => (string) ($cover->weekend_minimum ?? '')];
        }
        $this->resetValidation();
        $this->showDepartments = true;
    }

    /** Add a department or change one: its name, heads, people, and any rules of its own. */
    public function saveDepartment(): void
    {
        $this->authorizeAccess();
        $this->authorizeHospital();
        $this->departmentName = trim($this->departmentName);
        $this->validate([
            'departmentName' => ['required', 'string', 'max:60', \Illuminate\Validation\Rule::unique('attendance_departments', 'name')
                ->where('branch_id', app(\App\Support\Tenancy\TenantContext::class)->branchId())->ignore($this->departmentEditId)],
            'departmentGrace' => 'nullable|integer|min:0|max:240',
            'departmentBreak' => 'nullable|integer|min:0|max:480',
            'departmentOvertimeAfter' => 'nullable|integer|min:1|max:480',
            'departmentCover.*.min' => 'nullable|integer|min:0|max:500',
            'departmentCover.*.weekend' => 'nullable|integer|min:0|max:500',
        ], ['departmentName.unique' => 'There is already a department with that name.'],
            ['departmentName' => 'name', 'departmentGrace' => 'grace period', 'departmentBreak' => 'break allowed', 'departmentOvertimeAfter' => 'overtime threshold',
                'departmentCover.*.min' => 'minimum cover', 'departmentCover.*.weekend' => 'weekend cover']);
        $hours = null;
        if ($this->departmentOwnRules && $this->departmentOwnHours && ! $hours = $this->hoursFrom('departmentHours')) {
            return;
        }
        $number = fn (string $value) => $this->departmentOwnRules && $value !== '' ? (int) $value : null;
        $values = [
            'name' => $this->departmentName,
            // Only this clinic's users can head a department.
            'heads' => User::query()->inCurrentClinic()->whereKey(array_map('intval', $this->departmentHeads))->pluck('id')->all() ?: null,
            'hours' => $hours,
            'grace_minutes' => $number($this->departmentGrace),
            'break_minutes' => $number($this->departmentBreak),
            'overtime_after_minutes' => $number($this->departmentOvertimeAfter),
        ];
        $department = $this->departmentEditId ? \App\Models\AttendanceDepartment::query()->findOrFail($this->departmentEditId) : new \App\Models\AttendanceDepartment();
        $department->fill($values)->save();

        // Minimum cover: one line per shift that has a number.
        \App\Models\AttendanceCover::query()->where('attendance_department_id', $department->id)->delete();
        foreach (\App\Models\AttendanceShift::query()->pluck('id') as $shiftId) {
            $cover = $this->departmentCover[$shiftId] ?? [];
            if (trim((string) ($cover['min'] ?? '')) !== '' && (int) $cover['min'] > 0) {
                \App\Models\AttendanceCover::create(['attendance_department_id' => $department->id, 'attendance_shift_id' => $shiftId, 'minimum' => (int) $cover['min'],
                    'weekend_minimum' => trim((string) ($cover['weekend'] ?? '')) === '' ? null : (int) $cover['weekend']]);
            }
        }

        // Its people: those ticked join it (leaving any other department); those unticked leave it.
        $members = array_map('intval', $this->departmentMembers);
        AttendanceStaff::query()->where('attendance_department_id', $department->id)->whereNotIn('id', $members ?: [0])->update(['attendance_department_id' => null]);
        AttendanceStaff::query()->whereIn('id', $members ?: [0])->update(['attendance_department_id' => $department->id]);
        $this->showDepartments = false;
        $this->dispatch('notify', type: 'success', message: "{$department->name} saved.");
    }

    /** Its people stay followed, in no department. */
    public function removeDepartment(int $id): void
    {
        $this->authorizeAccess();
        \App\Models\AttendanceDepartment::query()->findOrFail($id)->delete();
        $this->showDepartments = false;
    }

    /** Make a department for each department name the terminal has for people, and put them in it. */
    public function departmentsFromTerminal(): void
    {
        $this->authorizeAccess();
        $this->authorizeHospital();
        foreach (AttendanceStaff::query()->whereNull('attendance_department_id')->whereNotNull('department')->where('department', '!=', '')->get() as $staff) {
            $department = \App\Models\AttendanceDepartment::query()->firstOrCreate(['name' => mb_substr(trim($staff->department), 0, 60)]);
            $staff->update(['attendance_department_id' => $department->id]);
        }
    }
}
