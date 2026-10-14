<?php

namespace App\Livewire\Admin\Concerns;

use App\Models\AttendanceSetting;
use App\Models\AttendanceStaff;
use App\Services\Attendance\AttendanceAnalyzer;
use Carbon\CarbonImmutable;

/**
 * What the attendance pages share: who may see and change what (the owner and managers, or a
 * department's head for their own people), the parts of attendance the plan includes, finding
 * people in a long list, and a list a page at a time. Uses $search and $department.
 */
trait AttendanceAccess
{
    private function matches(AttendanceStaff $staff): bool
    {
        $search = mb_strtolower(trim($this->search));

        return $search === '' || str_contains(mb_strtolower($staff->name), $search) || str_contains(mb_strtolower((string) $staff->employee_no), $search);
    }

    /** One page of a list, and where it is: [items, [page, pages, total, from, to]]. */
    private function paged(array $items, int &$page, int $perPage = 50): array
    {
        $total = count($items);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        return [array_slice($items, ($page - 1) * $perPage, $perPage),
            ['page' => $page, 'pages' => $pages, 'total' => $total, 'from' => $total ? ($page - 1) * $perPage + 1 : 0, 'to' => min($total, $page * $perPage)]];
    }

    /** The departments on show: all for a manager with no filter, one when filtered, a head's own. */
    private function departmentsOnShow(): array
    {
        $user = auth()->user();
        if ($this->department === 'none' || ! \App\Support\AttendancePlan::hospital()) {
            return [];
        }
        $all = \App\Support\AttendanceScope::manager($user) ? \App\Models\AttendanceDepartment::query()->pluck('id')->all() : \App\Support\AttendanceScope::headed($user);

        return ctype_digit($this->department) ? array_values(array_intersect($all, [(int) $this->department])) : $all;
    }

    /**
     * For each department and shift with a minimum: how many are rostered on it each day against
     * how many are needed. People on leave that day are not counted. Returns [lines, days short].
     */
    private function rosterCover(array $rows, array $days, ?array $departments = null): array
    {
        $lines = [];
        $gaps = 0;
        $covers = \App\Models\AttendanceCover::query()->whereIn('attendance_department_id', ($departments ?? $this->departmentsOnShow()) ?: [0])->with(['department:id,name', 'shift'])->get()
            ->filter(fn ($cover) => $cover->department && $cover->shift)->sortBy(fn ($cover) => [$cover->department->name, $cover->shift->starts_at]);
        foreach ($covers as $cover) {
            $line = ['department' => $cover->department->name, 'shift' => $cover->shift->name, 'days' => []];
            foreach ($days as $key => $day) {
                $have = count(array_filter($rows, fn ($row) => $row['staff']->attendance_department_id === $cover->attendance_department_id
                    && ($row['days'][$key]['hours']['shift'] ?? null) === $cover->shift->name && ! $row['days'][$key]['leave_type']));
                $line['days'][$key] = ['have' => $have, 'need' => $need = $cover->neededOn($day)];
                $gaps += (int) ($have < $need);
            }
            $lines[] = $line;
        }

        return [$lines, $gaps];
    }

    /** Departments, cover, patterns, swaps and staff lists: the hospital tools, a part of attendance sold on its own. */
    private function authorizeHospital(): void
    {
        $this->authorizePart('hospital');
    }

    /** A part of attendance the clinic's plan or add-ons must include. */
    private function authorizePart(string $part): void
    {
        abort_unless(\App\Support\AttendancePlan::parts()[$part], 403, 'This is not part of your plan. See "More attendance features" under Set up.');
    }

    /** The owner, a manager, or the head of a department here. */
    private function authorizeView(): void
    {
        abort_unless(\App\Support\AttendanceScope::allowed(auth()->user()), 403);
        abort_unless(\App\Services\LicenseService::has(\App\Support\Feature::ATTENDANCE), 403, 'Staff attendance is not included in your subscription.');
    }

    /** As authorizeView, and the person must be one they may act on: a head only on their own department's people. */
    private function authorizeStaff(int $staffId): AttendanceStaff
    {
        $this->authorizeView();
        $staff = AttendanceStaff::query()->findOrFail($staffId);
        abort_unless(\App\Support\AttendanceScope::canSee(auth()->user(), $staff->id), 403, 'That person is not in your department.');

        return $staff;
    }

    private function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Super Admin', 'Manager']), 403);
        abort_unless(\App\Services\LicenseService::has(\App\Support\Feature::ATTENDANCE), 403, 'Staff attendance is not included in your subscription.');
    }

    private function timezone(): string
    {
        return app(\App\Support\Tenancy\TenantContext::class)->clinic()?->default_timezone ?: config('app.timezone');
    }
}
