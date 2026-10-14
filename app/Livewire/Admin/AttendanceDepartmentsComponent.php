<?php

namespace App\Livewire\Admin;

use App\Models\AttendanceCover;
use App\Models\AttendanceDepartment;
use App\Models\AttendanceSetting;
use App\Models\AttendanceShift;
use App\Models\AttendanceStaff;
use App\Models\User;
use Livewire\Component;

/**
 * Staff & Security → Attendance → Departments, a page of its own (it opens in a new tab from
 * Set up): every department with its heads, people, cover and rules; a department opens in a
 * panel that slides in from the right, to change it or delete it. The owner and managers only.
 */
class AttendanceDepartmentsComponent extends Component
{
    use Concerns\AttendanceAccess, Concerns\ManagesDepartments;

    /** Finding a department or a person; the department filter the shared helpers read. */
    public string $search = '';
    public string $department = '';

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->authorizeHospital();
    }

    public function render()
    {
        $departments = AttendanceDepartment::query()->withCount(['staff' => fn ($query) => $query->where('is_tracked', true)])->orderBy('name')->get();
        $term = mb_strtolower(trim($this->search));
        $users = User::query()->inCurrentClinic()->orderBy('name')->get(['id', 'name']);

        return view('livewire.admin.attendance-departments-component', [
            'departments' => $term === '' ? $departments : $departments->filter(fn ($unit) => str_contains(mb_strtolower($unit->name), $term))->values(),
            'total' => $departments->count(),
            'headNames' => $users->pluck('name', 'id'),
            'covers' => AttendanceCover::query()->with('shift:id,name')->get()->groupBy('attendance_department_id'),
            'unplaced' => AttendanceStaff::query()->where('is_tracked', true)->whereNull('attendance_department_id')->count(),
            'terminalLabels' => AttendanceStaff::query()->whereNull('attendance_department_id')->whereNotNull('department')->where('department', '!=', '')->distinct()->count('department'),
            // For the panel.
            'accounts' => $this->showDepartments ? $users : collect(),
            'departmentPeople' => $this->showDepartments ? AttendanceStaff::query()->where('is_tracked', true)->with('unit:id,name')->orderBy('name')->get() : collect(),
            'shifts' => AttendanceShift::query()->orderBy('starts_at')->orderBy('name')->get(),
            'settings' => AttendanceSetting::current(),
        ])->layout('layouts.admin.admin-layout');
    }
}
