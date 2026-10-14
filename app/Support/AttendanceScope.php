<?php

namespace App\Support;

use App\Models\AttendanceDepartment;
use App\Models\AttendanceStaff;
use App\Models\User;

/**
 * Whose attendance someone may see. The owner and managers see the whole branch; the head of a
 * department sees that department's people; everyone else sees only their own, on their profile.
 */
class AttendanceScope
{
    public static function manager(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(['Super Admin', 'Manager']);
    }

    /** Ids of the departments at this branch the person heads. */
    public static function headed(?User $user): array
    {
        if (! $user || ! AttendancePlan::hospital()) {
            return [];
        }

        return AttendanceDepartment::query()->get()->filter(fn (AttendanceDepartment $department) => $department->headedBy($user->id))->pluck('id')->all();
    }

    public static function allowed(?User $user): bool
    {
        return self::manager($user) || self::headed($user) !== [];
    }

    /**
     * The staff ids to show: null for everyone. $department narrows it: an id, or 'none' for
     * people in no department (managers only). A head never gets beyond their own departments.
     */
    public static function staffIds(?User $user, string $department = ''): ?array
    {
        $manager = self::manager($user);
        $headed = $manager ? [] : self::headed($user);
        abort_unless($manager || $headed, 403);

        if ($manager) {
            return match (true) {
                $department === '' => null,
                $department === 'none' => AttendanceStaff::query()->whereNull('attendance_department_id')->pluck('id')->all(),
                default => AttendanceStaff::query()->where('attendance_department_id', (int) $department)->pluck('id')->all(),
            };
        }
        $departments = ctype_digit($department) && in_array((int) $department, $headed, true) ? [(int) $department] : $headed;

        return AttendanceStaff::query()->whereIn('attendance_department_id', $departments)->pluck('id')->all();
    }

    /** May this person act on that member of staff (roster, corrections, leave, overtime)? */
    public static function canSee(?User $user, int $staffId): bool
    {
        if (self::manager($user)) {
            return true;
        }
        $headed = self::headed($user);

        return $headed && AttendanceStaff::query()->whereKey($staffId)->whereIn('attendance_department_id', $headed)->exists();
    }
}
