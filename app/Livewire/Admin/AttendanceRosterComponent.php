<?php

namespace App\Livewire\Admin;

use App\Models\AttendanceShift;
use App\Services\Attendance\AttendanceAnalyzer;
use App\Support\AttendancePlan;
use App\Support\AttendanceScope;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Staff & Security → Attendance → Roster, a page of its own (it opens in a new tab): one
 * department's people by day, a week or a month, with cover, rotation patterns, swaps, a
 * roster brought in from Excel and a printout. Heads of department roster their own people.
 */
class AttendanceRosterComponent extends Component
{
    use Concerns\AttendanceAccess, Concerns\BuildsRoster, WithFileUploads;

    /** Finding people in a long list; the department filter the shared helpers read. */
    public string $search = '';
    public string $department = '';

    public function mount(): void
    {
        $this->authorizePart('shifts');
        $this->authorizeView();
        $this->rosterWeek = $this->day(request()->query('date')) ?? CarbonImmutable::now($this->timezone())->format('Y-m-d');
        $this->rosterSpan = request()->query('span') === 'month' ? 'month' : 'week';
        // The department asked for, else the head's own, else the first: one department at a time.
        $units = $this->rosterUnits();
        $asked = (string) request()->query('department', '');
        $this->rosterUnit = $units === [] ? '' : (isset($units[$asked]) ? $asked : (string) array_key_first($units));
    }

    /** 'Y-m-d', or null for anything else. */
    private function day(?string $value): ?string
    {
        return $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : null;
    }

    public function updatedSearch(): void
    {
        $this->rosterPage = 1;
    }

    public function render(AttendanceAnalyzer $analyzer)
    {
        $manager = AttendanceScope::manager(auth()->user());

        return view('livewire.admin.attendance-roster-component', [
            'rosterGrid' => $this->rosterGrid($analyzer->forStaff($this->rosterStaffIds())),
            'shifts' => AttendanceShift::query()->orderBy('starts_at')->orderBy('name')->get(),
            'hospital' => AttendancePlan::hospital(),
            'manager' => $manager,
        ])->layout(...($manager ? ['layouts.admin.admin-layout'] : \App\Support\ClinicNavigation::sharedLayout()));
    }
}
