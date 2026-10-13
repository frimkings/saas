<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLeaveType;
use App\Models\AttendanceStaff;
use App\Models\Setting;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pay for a run of days worked out from attendance, before tax and pension. Meant for whole
 * months, but any days work.
 *
 * Monthly salary: the salary for the working days in the period (a day is worth the month's
 * salary over that month's working days), less absences and unpaid leave, less lateness where
 * the branch takes it off, plus approved overtime at the branch's rate.
 * Hourly: the hours on site up to the end of the working day, plus approved overtime at the
 * branch's rate. Leave and holidays are not paid by themselves.
 */
class AttendancePayroll
{
    public function __construct(private AttendanceAnalyzer $analyzer) {}

    /** The same, for these people only (a department's, or a head's own): null = everyone. */
    public function only(?array $staffIds): static
    {
        $limited = clone $this;
        $limited->analyzer = $this->analyzer->forStaff($staffIds);

        return $limited;
    }

    /** ['rows' => one per followed person, 'settings', 'pending' (overtime minutes still unanswered), 'unrecorded' (working days with nothing received)]. */
    public function for(CarbonImmutable $first, CarbonImmutable $last): array
    {
        $report = $this->analyzer->range($first, $last);
        $settings = $report['settings'];
        $multiplier = (float) ($settings->overtime_multiplier ?? 1.5);
        $types = AttendanceLeaveType::options();

        $rows = [];
        foreach ($report['rows'] as $row) {
            $staff = $row['staff'];
            if (! $staff->is_tracked) {
                continue;
            }
            $rates = $staff->pay_type === 'monthly' ? $this->monthRates($staff, $first, $last) : [];
            $hourly = $staff->pay_type === 'hourly' ? (float) $staff->pay_rate / 60 : null;
            // What a minute, and a working day, are worth on a given date.
            $minute = fn (string $key) => $hourly ?? ($rates[substr($key, 0, 7)]['minute'] ?? 0);
            $dayRate = fn (string $key) => $rates[substr($key, 0, 7)]['day'] ?? 0;

            $line = ['staff' => $staff, 'pay_type' => $staff->pay_type, 'rate' => $staff->pay_rate !== null ? (float) $staff->pay_rate : null,
                'expected' => $row['totals']['expected'], 'present' => $row['totals']['present'], 'absent' => 0, 'paid_leave' => 0.0, 'unpaid_leave' => 0.0,
                'holidays' => $row['totals']['holiday'], 'minutes_worked' => $row['totals']['minutes_worked'], 'regular_minutes' => 0,
                'late_minutes' => $row['totals']['late_minutes'], 'overtime_minutes' => $row['totals']['overtime_minutes'], 'overtime_pending' => $row['totals']['overtime_pending'],
                'basic' => $staff->pay_type === 'monthly' ? array_sum(array_column($rates, 'basic')) : 0.0,
                'overtime_pay' => 0.0, 'absence' => 0.0, 'unpaid' => 0.0, 'lateness' => 0.0, 'unrecorded' => 0];

            foreach ($row['days'] as $key => $day) {
                $line['overtime_pay'] += $day['overtime_approved'] * $minute($key) * $multiplier;
                // Hours up to the end of the day; anything past it is overtime or not paid.
                $line['regular_minutes'] += max(0, (int) $day['minutes_worked'] - $day['overtime_minutes']);
                $line['unrecorded'] += (int) ($day['status'] === 'none' && $day['hours'] && ! $day['in_progress']);
                if ($day['status'] === 'absent') {
                    $line['absent']++;
                    $line['absence'] += ($settings->deduct_absence ?? true) ? $dayRate($key) : 0;
                }
                if ($day['status'] === 'leave' && $day['leave_type']) {
                    $paid = $types[$day['leave_type']]->is_paid ?? true;
                    $line[$paid ? 'paid_leave' : 'unpaid_leave']++;
                    $line['unpaid'] += $paid ? 0 : $dayRate($key);
                }
                if ($day['unpaid_minutes']) {
                    $line['unpaid_leave'] += round($day['unpaid_minutes'] / max(1, $day['scheduled_minutes']), 2);
                    $line['unpaid'] += $staff->pay_type === 'monthly' ? $day['unpaid_minutes'] * $minute($key) : 0;
                }
                if ($day['late_minutes'] && ($settings->deduct_lateness ?? false) && $staff->pay_type === 'monthly') {
                    $line['lateness'] += $day['late_minutes'] * $minute($key);
                }
            }
            if ($staff->pay_type === 'hourly') {
                $line['basic'] = $line['regular_minutes'] * $hourly;
            }
            foreach (['basic', 'overtime_pay', 'absence', 'unpaid', 'lateness'] as $money) {
                $line[$money] = round($line[$money], 2);
            }
            $line['gross'] = $staff->pay_type ? round(max(0, $line['basic'] + $line['overtime_pay'] - $line['absence'] - $line['unpaid'] - $line['lateness']), 2) : null;
            $rows[] = $line;
        }

        return ['rows' => $rows, 'settings' => $settings, 'pending' => array_sum(array_column($rows, 'overtime_pending')),
            'unrecorded' => array_sum(array_column($rows, 'unrecorded')), 'unpaid_staff' => count(array_filter($rows, fn ($line) => ! $line['pay_type']))];
    }

    /**
     * For someone on a salary: 'Y-m' => what a working day and a minute of one are worth that
     * month, and the salary due for the month's working days inside the period (from their
     * start date). The month's working days are the days they are due in, holidays included.
     */
    private function monthRates(AttendanceStaff $staff, CarbonImmutable $first, CarbonImmutable $last): array
    {
        $salary = (float) $staff->pay_rate;
        $from = max($first->format('Y-m-d'), $staff->starts_on?->format('Y-m-d') ?? '');
        $minutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $rates = [];
        for ($month = $first->startOfMonth(); $month->lte($last); $month = $month->addMonthNoOverflow()) {
            $working = array_filter($this->analyzer->schedule($staff, $month, $month->endOfMonth()->startOfDay()));
            $total = array_sum(array_map(fn ($hours) => max(1, $minutes($hours['end']) - $minutes($hours['start'])), $working));
            $inPeriod = count(array_filter(array_keys($working), fn ($key) => $key >= $from && $key <= $last->format('Y-m-d')));
            $rates[$month->format('Y-m')] = ['day' => $working ? $salary / count($working) : 0, 'minute' => $total ? $salary / $total : 0,
                'basic' => $working ? $salary * $inPeriod / count($working) : 0];
        }

        return $rates;
    }

    public function csv(CarbonImmutable $first, CarbonImmutable $last): StreamedResponse
    {
        $payroll = $this->for($first, $last);
        $settings = $payroll['settings'];
        $context = app(TenantContext::class);
        $place = trim(($context->clinic()?->name ?? '').' · '.($context->branch()?->name ?? ''), ' ·');
        $wholeMonth = $first->day === 1 && $last->isSameDay($first->endOfMonth());
        $name = 'payroll-'.($wholeMonth ? $first->format('Y-m') : $first->format('Y-m-d').'-to-'.$last->format('Y-m-d'));

        return response()->streamDownload(function () use ($payroll, $settings, $place, $first, $last) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads names with accents correctly
            fputcsv($out, ['Payroll from attendance', AttendancePeriod::label($first, $last), $place]);
            fputcsv($out, ['Amounts in '.Setting::currency().', before tax and pension. Overtime is paid at '.rtrim(rtrim(number_format((float) ($settings->overtime_multiplier ?? 1.5), 2), '0'), '.').' times the normal rate, once approved.']);
            fputcsv($out, ['On a salary: absences are '.(($settings->deduct_absence ?? true) ? 'taken off' : 'not taken off').'; lateness is '.(($settings->deduct_lateness ?? false) ? 'taken off by the minute' : 'not taken off').'; unpaid leave is taken off. By the hour: paid for hours on site up to the end of the day, plus approved overtime.']);
            if ($payroll['pending']) {
                fputcsv($out, ['Not final: '.AttendanceAnalyzer::duration($payroll['pending']).' of overtime is still waiting for an answer and is not paid here.']);
            }
            if ($payroll['unrecorded']) {
                fputcsv($out, ['Not final: '.$payroll['unrecorded'].' working day(s) in these dates have nothing recorded yet.']);
            }
            fputcsv($out, []);
            fputcsv($out, ['Employee ID', 'Name', 'Department', 'Paid', 'Rate', 'Days expected', 'Days present', 'Days absent', 'Paid leave (days)', 'Unpaid leave (days)', 'Holidays',
                'Hours on site', 'Minutes late', 'Overtime hours (approved)', 'Overtime hours (waiting)', 'Basic pay', 'Overtime pay', 'Absence deduction', 'Unpaid leave deduction',
                'Lateness deduction', 'Gross pay', 'Note']);
            foreach ($payroll['rows'] as $line) {
                $staff = $line['staff'];
                $money = fn ($amount) => $line['pay_type'] ? number_format($amount, 2, '.', '') : null;
                fputcsv($out, [ctype_digit($staff->employee_no) && str_starts_with($staff->employee_no, '0') ? '="'.$staff->employee_no.'"' : $staff->employee_no,
                    $staff->name, $staff->unit?->name ?? $staff->department, ['monthly' => 'Monthly salary', 'hourly' => 'By the hour'][$line['pay_type']] ?? 'Not set', $money($line['rate'] ?? 0),
                    $line['expected'], $line['present'], $line['absent'], $line['paid_leave'], $line['unpaid_leave'], $line['holidays'], round($line['minutes_worked'] / 60, 2),
                    $line['late_minutes'], round($line['overtime_minutes'] / 60, 2), round($line['overtime_pending'] / 60, 2), $money($line['basic']), $money($line['overtime_pay']),
                    $money($line['absence']), $money($line['unpaid']), $money($line['lateness']), $money($line['gross'] ?? 0),
                    $line['pay_type'] ? null : 'No pay set for this person']);
            }
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
