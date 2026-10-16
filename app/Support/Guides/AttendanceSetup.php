<?php

namespace App\Support\Guides;

use App\Models\AttendanceDepartment;
use App\Models\AttendanceDevice;
use App\Models\AttendanceHoliday;
use App\Models\AttendanceImport;
use App\Models\AttendanceLeaveType;
use App\Models\AttendanceSetting;
use App\Models\AttendanceShift;
use App\Models\AttendanceStaff;

/**
 * The first-steps checklist on the Attendance page for the owner and managers. Each step ticks itself once
 * the branch has done it (the first punch arrives, hours are saved...), so there is nothing to mark by hand.
 * "Also worth setting up" lists only parts the plan includes. Once the first five are done, or the person
 * hides it, the checklist is gone.
 */
final class AttendanceSetup
{
    public const GUIDE = 'attendance.setup';

    /** ['steps' => [...], 'extras' => [...], 'done' => int, 'total' => int] or null when there is nothing to show. */
    public static function for(array $parts, bool $canPay): ?array
    {
        if (Guides::seen(self::GUIDE)) return null;
        $settings = AttendanceSetting::query()->first();
        $steps = [
            ['key' => 'data', 'title' => 'Connect the terminal, or upload its report', 'body' => 'Under Set up → Terminals, copy the push link into the terminal\'s HTTP Listening so punches arrive by themselves. No internet at the terminal? Upload its monthly Attendance Record (.xls) instead.',
                'done' => AttendanceDevice::query()->whereNotNull('last_seen_at')->exists() || AttendanceImport::query()->exists(), 'action' => "\$set('showDevices', true)", 'button' => 'Terminals'],
            ['key' => 'staff', 'title' => 'Get your staff on the list', 'body' => 'People are added from the terminal\'s punches or report'.($parts['hospital'] ? ', or bring in your staff list from Excel' : '').'. Check their names and Employee IDs match.',
                'done' => AttendanceStaff::query()->exists(), 'action' => $parts['hospital'] ? 'openStaffImport' : "\$set('showUpload', true)", 'button' => $parts['hospital'] ? 'Bring in a list' : 'Upload report'],
            ['key' => 'hours', 'title' => 'Set working hours', 'body' => 'The days and hours you open, the minutes of grace before someone is late, and the break allowed.',
                'done' => $settings !== null, 'action' => 'editHours', 'button' => 'Working hours'],
            ['key' => 'holidays', 'title' => 'Add this year\'s public holidays', 'body' => 'Nobody is marked absent on a holiday.',
                'done' => AttendanceHoliday::query()->whereYear('date', now()->year)->exists(), 'action' => "\$set('showHolidays', true)", 'button' => 'Holidays'],
            ['key' => 'accounts', 'title' => 'Link staff to their accounts', 'body' => 'Open a person and pick their sign-in account. They then see their own days under Profile → My attendance'.($parts['leave'] ? ' and can ask for leave there' : '').'.',
                'done' => AttendanceStaff::query()->whereNotNull('user_id')->exists(), 'action' => null, 'button' => null],
        ];
        $extras = array_values(array_filter([
            $parts['shifts'] ? ['key' => 'shifts', 'title' => 'Shifts', 'body' => 'For staff who work different hours or nights.', 'done' => AttendanceShift::query()->exists(), 'action' => "\$set('showShifts', true)", 'button' => 'Shifts'] : null,
            $parts['leave'] ? ['key' => 'leave', 'title' => 'Leave types and allowances', 'body' => 'Annual, sick and others, with days allowed each year.', 'done' => AttendanceLeaveType::query()->exists(), 'action' => 'openLeaveTypes', 'button' => 'Leave types'] : null,
            $parts['payroll'] && $canPay ? ['key' => 'pay', 'title' => 'Pay rates', 'body' => 'A salary or hourly rate per person, for the payroll sheet.', 'done' => AttendanceStaff::query()->whereNotNull('pay_type')->exists(), 'action' => 'openPayroll', 'button' => 'Pay'] : null,
            $parts['phone'] ? ['key' => 'phone', 'title' => 'Phone clock-in', 'body' => 'Mark the branch on the map so staff can clock in from their phones when they are there.', 'done' => (bool) ($settings?->phone_clock_in), 'action' => 'openPhone', 'button' => 'Phone clock-in'] : null,
            $parts['hospital'] ? ['key' => 'departments', 'title' => 'Departments and their heads', 'body' => 'Heads see and approve for their own people.', 'done' => AttendanceDepartment::query()->exists(), 'action' => null, 'button' => null, 'url' => route('admin.attendance.departments')] : null,
        ]));
        $done = count(array_filter($steps, fn ($step) => $step['done']));
        if ($done === count($steps)) return null;

        return ['steps' => $steps, 'extras' => $extras, 'done' => $done, 'total' => count($steps)];
    }
}
