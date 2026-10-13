<?php

namespace App\Services\Attendance;

use App\Models\AttendanceHoliday;
use App\Models\AttendanceLeave;
use App\Models\AttendanceLeaveType;
use App\Models\AttendanceStaff;
use Carbon\CarbonImmutable;

/**
 * How much leave of each type someone has taken in a calendar year against the branch's yearly
 * allowance. Leave is counted in working days: only days the person was due to work, holidays
 * left out. Leave for part of a day counts by its share of that working day, to the nearest
 * quarter of a day.
 */
class AttendanceLeaveBalances
{
    public function __construct(private AttendanceAnalyzer $analyzer) {}

    /**
     * code => ['type', 'allowance' (null = no limit), 'used', 'pending', 'left' (null = no limit)].
     * 'left' is what remains once leave still waiting for an answer is granted too.
     */
    public function for(AttendanceStaff $staff, int $year): array
    {
        $first = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $last = $first->endOfYear()->startOfDay();
        $working = $this->workingDays($staff, $first, $last);

        $taken = [];
        AttendanceLeave::query()->where('attendance_staff_id', $staff->id)->whereIn('status', [AttendanceLeave::APPROVED, AttendanceLeave::PENDING])
            ->where('starts_on', '<=', $last->format('Y-m-d'))->where('ends_on', '>=', $first->format('Y-m-d'))->get()
            ->each(function (AttendanceLeave $leave) use (&$taken, $working) {
                $taken[$leave->type][$leave->status] = ($taken[$leave->type][$leave->status] ?? 0) + $this->count($leave, $working);
            });

        $balances = [];
        foreach (AttendanceLeaveType::options() as $code => $type) {
            $used = $taken[$code][AttendanceLeave::APPROVED] ?? 0;
            $pending = $taken[$code][AttendanceLeave::PENDING] ?? 0;
            if (! $type->is_active && ! $used && ! $pending) {
                continue;
            }
            $balances[$code] = ['type' => $type, 'allowance' => $type->days_per_year, 'used' => $used, 'pending' => $pending,
                'left' => $type->days_per_year === null ? null : $type->days_per_year - $used - $pending];
        }

        return $balances;
    }

    /** The working days one leave takes, saved or not, within the calendar year it starts in. */
    public function daysOf(AttendanceStaff $staff, AttendanceLeave $leave): float
    {
        $first = CarbonImmutable::parse($leave->starts_on)->startOfDay();
        $last = min(CarbonImmutable::parse($leave->ends_on)->startOfDay(), $first->endOfYear()->startOfDay());

        return $this->count($leave, $this->workingDays($staff, $first, $last));
    }

    /** 3 -> '3 days', 0.5 -> 'half a day', 1.25 -> '1.25 days'. */
    public static function days(float $days): string
    {
        return match (true) {
            $days == 0.5 => 'half a day',
            $days == 1.0 => '1 day',
            default => rtrim(rtrim(number_format($days, 2), '0'), '.').' days',
        };
    }

    /** 'Y-m-d' => the minutes of the working day, for each day the person is due in and that is not a holiday. */
    private function workingDays(AttendanceStaff $staff, CarbonImmutable $first, CarbonImmutable $last): array
    {
        $holidays = AttendanceHoliday::query()->whereBetween('date', [$first->format('Y-m-d'), $last->format('Y-m-d')])->get()
            ->mapWithKeys(fn ($holiday) => [$holiday->date->format('Y-m-d') => true])->all();
        $minutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $working = [];
        foreach ($this->analyzer->schedule($staff, $first, $last) as $key => $hours) {
            if ($hours && ! isset($holidays[$key])) {
                $working[$key] = max(1, $minutes($hours['end']) - $minutes($hours['start']));
            }
        }

        return $working;
    }

    private function count(AttendanceLeave $leave, array $working): float
    {
        $from = CarbonImmutable::parse($leave->starts_on)->format('Y-m-d');
        if ($leave->isPartDay()) {
            if (! isset($working[$from])) {
                return 0;
            }
            $minutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
            $share = ($minutes($leave->to_time) - $minutes($leave->from_time)) / $working[$from];

            return min(1, max(0.25, round($share * 4) / 4));
        }
        $to = CarbonImmutable::parse($leave->ends_on)->format('Y-m-d');

        return (float) count(array_filter(array_keys($working), fn ($key) => $key >= $from && $key <= $to));
    }
}
