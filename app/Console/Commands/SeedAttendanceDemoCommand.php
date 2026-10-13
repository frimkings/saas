<?php

namespace App\Console\Commands;

use App\Models\AttendanceCover;
use App\Models\AttendanceDepartment;
use App\Models\AttendanceDevice;
use App\Models\AttendanceLeave;
use App\Models\AttendanceOvertime;
use App\Models\AttendanceShift;
use App\Models\AttendanceStaff;
use App\Models\Clinic;
use App\Support\Tenancy\ActAsClinic;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills one clinic's default branch with a made-up hospital for trying attendance at size:
 * departments, three shifts, a rotating roster, minimum cover, a month of punches, some leave,
 * overtime and pay. Everyone it adds has an Employee ID starting "DM", so --clear removes them
 * (and their punches) again. Refuses to run in production.
 */
class SeedAttendanceDemoCommand extends Command
{
    protected $signature = 'attendance:demo {clinic : The clinic\'s id or part of its name} {--staff=600 : How many people to make} {--days=30 : How many days of punches, back from today} {--clear : Remove the made-up people instead}';

    protected $description = 'Fill a clinic with a made-up hospital (departments, shifts, roster, punches) to try attendance at size';

    /** Department => [share of staff, works shifts]. */
    private const DEPARTMENTS = [
        'Nursing' => [200, true], 'Maternity' => [50, true], 'Emergency' => [45, true], 'Theatre' => [40, true], 'Laboratory' => [40, true],
        'Housekeeping' => [30, true], 'Security' => [15, true], 'Medical' => [60, false], 'Pharmacy' => [35, false], 'Radiology' => [25, false],
        'Records' => [30, false], 'Administration' => [30, false],
    ];

    private const SHIFTS = ['Morning' => ['07:00', '14:00'], 'Afternoon' => ['14:00', '21:00'], 'Night' => ['21:00', '07:00']];

    private const FIRST = ['Ama', 'Kofi', 'Yaa', 'Kwame', 'Esi', 'Kojo', 'Akosua', 'Kwesi', 'Abena', 'Yaw', 'Adwoa', 'Kwabena', 'Afia', 'Kwaku', 'Efua', 'Fiifi', 'Akua', 'Nana', 'Araba', 'Ebo',
        'Serwaa', 'Nii', 'Naa', 'Selorm', 'Dzifa', 'Edem', 'Mawuli', 'Akwasi', 'Maame', 'Papa'];

    private const LAST = ['Owusu', 'Boateng', 'Mensah', 'Asante', 'Osei', 'Appiah', 'Darko', 'Agyeman', 'Amoah', 'Ofori', 'Addo', 'Quaye', 'Tetteh', 'Lamptey', 'Ansah', 'Frimpong', 'Acheampong',
        'Bonsu', 'Sarpong', 'Nkrumah', 'Adjei', 'Gyamfi', 'Wiredu', 'Danso', 'Kyei', 'Antwi', 'Yeboah', 'Opoku', 'Amankwah', 'Dogbe'];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('This makes up staff and punches; it does not run in production.');

            return self::FAILURE;
        }
        $key = (string) $this->argument('clinic');
        $clinic = ctype_digit($key) ? Clinic::find((int) $key) : Clinic::where('name', 'like', "%{$key}%")->orderBy('id')->first();
        if (! $clinic) {
            $this->error("No clinic matches \"{$key}\".");

            return self::FAILURE;
        }

        $ran = ActAsClinic::run($clinic, null, function () use ($clinic) {
            $branch = app(TenantContext::class)->branch();
            $this->line("{$clinic->name} · {$branch->name}");
            $this->clear();
            if (! $this->option('clear')) {
                $this->seed($clinic->id, $branch->id, CarbonImmutable::now($clinic->default_timezone ?: config('app.timezone')));
            }
        });
        if (! $ran) {
            $this->error('That clinic has no active branch or staff to act as.');
        }

        return $ran ? self::SUCCESS : self::FAILURE;
    }

    /** Everything a previous run made. Punches, roster days, leave and overtime go with the people. */
    private function clear(): void
    {
        $removed = AttendanceStaff::query()->where('employee_no', 'like', 'DM%')->delete();
        AttendanceDevice::query()->where('name', 'Demo terminal')->delete();
        foreach (AttendanceDepartment::query()->whereIn('name', array_keys(self::DEPARTMENTS))->withCount('staff')->get() as $department) {
            if (! $department->staff_count) {
                $department->delete();
            }
        }
        if ($removed) {
            $this->line("Removed {$removed} made-up people.");
        }
    }

    private function seed(int $clinicId, int $branchId, CarbonImmutable $now): void
    {
        mt_srand(600);
        $count = max(12, (int) $this->option('staff'));
        $days = max(1, min(120, (int) $this->option('days')));
        $first = $now->startOfDay()->subDays($days);
        $tenant = ['clinic_id' => $clinicId, 'branch_id' => $branchId];

        $shifts = [];
        foreach (self::SHIFTS as $name => [$start, $end]) {
            $shifts[$name] = AttendanceShift::query()->firstOrCreate(['name' => $name], ['starts_at' => $start, 'ends_at' => $end]);
        }
        $cycle = [$shifts['Morning'], $shifts['Morning'], $shifts['Afternoon'], $shifts['Afternoon'], $shifts['Night'], $shifts['Night'], null, null];
        $device = AttendanceDevice::create(['name' => 'Demo terminal']);
        $device->forceFill(['first_event_at' => $first->format('Y-m-d 06:00:00'), 'last_event_at' => $now->format('Y-m-d H:i:s'), 'last_seen_at' => now()])->save();

        $share = array_sum(array_column(self::DEPARTMENTS, 0));
        $number = 0;
        $punches = [];
        $roster = [];
        $people = [];
        foreach (self::DEPARTMENTS as $name => [$size, $onShifts]) {
            $department = AttendanceDepartment::query()->firstOrCreate(['name' => $name]);
            $members = max(1, (int) round($count * $size / $share));
            if ($onShifts) {
                // A quarter of the department is on each shift on any day; ask for most of them.
                foreach ($shifts as $shiftName => $shift) {
                    AttendanceCover::query()->updateOrCreate(['attendance_department_id' => $department->id, 'attendance_shift_id' => $shift->id],
                        ['minimum' => max(1, (int) floor($members / 4 * ($shiftName === 'Night' ? 0.8 : 0.9))), 'weekend_minimum' => max(1, (int) floor($members / 4 * 0.75))]);
                }
            }
            for ($i = 0; $i < $members && $number < $count; $i++) {
                $number++;
                $monthly = mt_rand(1, 10) <= 7;
                $staff = AttendanceStaff::create(['employee_no' => sprintf('DM%04d', $number), 'name' => self::FIRST[mt_rand(0, 29)].' '.self::LAST[mt_rand(0, 29)],
                    'department' => $name, 'attendance_department_id' => $department->id, 'is_tracked' => true, 'starts_on' => $first->format('Y-m-d'),
                    'pay_type' => $monthly ? 'monthly' : 'hourly', 'pay_rate' => $monthly ? mt_rand(18, 65) * 100 : mt_rand(12, 40)]);
                $people[] = $staff;
                // Their own habits: most people are reliable, a few are often late or away.
                $lateness = [3, 3, 5, 5, 8, 8, 12, 15, 25, 40][mt_rand(0, 9)];
                $absence = [1, 1, 2, 2, 3, 3, 4, 5, 8, 12][mt_rand(0, 9)];

                for ($day = $first; $day->lte($now->addDays(14)); $day = $day->addDay()) {
                    $shift = null;
                    if ($onShifts) {
                        $shift = $cycle[($i + (int) $first->diffInDays($day)) % 8];
                        $roster[] = $tenant + ['attendance_staff_id' => $staff->id, 'date' => $day->format('Y-m-d'), 'attendance_shift_id' => $shift?->id, 'created_at' => now(), 'updated_at' => now()];
                        if (! $shift) {
                            continue;
                        }
                    } elseif ($day->isWeekend()) {
                        continue;
                    }
                    if ($day->gt($now) || mt_rand(1, 100) <= $absence) {
                        continue;
                    }
                    [$start, $end] = $shift ? [$shift->starts_at, $shift->ends_at] : ['08:00', '17:00'];
                    $in = $day->setTimeFromTimeString($start)->addMinutes(mt_rand(1, 100) <= $lateness ? mt_rand(16, 55) : mt_rand(-20, 10));
                    $out = $day->setTimeFromTimeString($end)->addDays($end <= $start ? 1 : 0)
                        ->addMinutes(match (true) { mt_rand(1, 100) <= 5 => mt_rand(40, 120), mt_rand(1, 100) <= 4 => mt_rand(-90, -20), default => mt_rand(0, 20) });
                    $times = [$in];
                    // Office staff often step out for lunch; a few people forget to punch out.
                    if (! $shift && mt_rand(1, 100) <= 25) {
                        array_push($times, $lunch = $day->setTime(12, mt_rand(20, 50)), $lunch->addMinutes(mt_rand(25, 80)));
                    }
                    if (mt_rand(1, 100) > 2) {
                        $times[] = $out;
                    }
                    foreach ($times as $time) {
                        if ($time->lte($now)) {
                            $punches[] = $tenant + ['attendance_staff_id' => $staff->id, 'punched_at' => $time->format('Y-m-d H:i:s'), 'source' => 'terminal', 'attendance_device_id' => $device->id];
                        }
                    }
                }
            }
        }

        foreach (array_chunk($roster, 1000) as $chunk) {
            DB::table('attendance_roster')->insertOrIgnore($chunk);
        }
        foreach (array_chunk($punches, 1000) as $chunk) {
            DB::table('attendance_punches')->insertOrIgnore($chunk);
        }

        // Leave: some taken, some coming, a few still waiting for an answer.
        foreach (array_rand($people, min(count($people), max(3, intdiv($count, 20)))) as $n => $index) {
            $from = $now->startOfDay()->addDays(mt_rand(-$days + 1, 12));
            AttendanceLeave::create(['attendance_staff_id' => $people[$index]->id, 'starts_on' => $from->format('Y-m-d'), 'ends_on' => $from->addDays(mt_rand(0, 4))->format('Y-m-d'),
                'type' => ['annual', 'annual', 'sick', 'permission'][mt_rand(0, 3)], 'status' => $n % 6 === 0 ? AttendanceLeave::PENDING : AttendanceLeave::APPROVED, 'note' => 'Demo']);
        }
        // Half of last week's overtime already answered.
        $answered = 0;
        foreach (DB::table('attendance_punches')->where('branch_id', $branchId)->where('attendance_device_id', $device->id)
            ->whereRaw("TIME(punched_at) BETWEEN '14:45:00' AND '16:10:00'")->where('punched_at', '<', $now->subDays(7)->format('Y-m-d'))->limit(40)->get() as $n => $punch) {
            if ($n % 2 === 0) {
                AttendanceOvertime::query()->updateOrCreate(['attendance_staff_id' => $punch->attendance_staff_id, 'date' => substr($punch->punched_at, 0, 10)],
                    ['status' => AttendanceOvertime::APPROVED, 'minutes' => 45]);
                $answered++;
            }
        }

        $this->info("Made {$number} people in ".count(self::DEPARTMENTS).' departments, '.number_format(count($punches)).' punches from '.$first->format('j M').' to now, '
            .number_format(count($roster))." roster days and {$answered} overtime answers. Remove them with --clear.");
    }
}
