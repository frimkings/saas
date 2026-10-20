<?php

namespace App\Console\Commands;

use App\Models\AttendanceCover;
use App\Models\AttendanceDepartment;
use App\Models\AttendanceDevice;
use App\Models\AttendanceLeave;
use App\Models\AttendanceHoliday;
use App\Models\AttendanceLeaveType;
use App\Models\AttendanceOvertime;
use App\Models\AttendancePattern;
use App\Models\AttendanceSetting;
use App\Models\AttendanceShift;
use App\Models\AttendanceStaff;
use App\Models\Clinic;
use App\Models\User;
use App\Support\Tenancy\ActAsClinic;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills one clinic's default branch with a made-up hospital for trying attendance at size:
 * departments with heads, three shifts, a rotating roster with saved patterns and a few swaps,
 * minimum cover, a month of punches (some from phones, some corrected by hand), public holidays,
 * leave of each kind (some waiting, some turned down, part days), overtime and pay. Everyone it
 * adds has an Employee ID starting "DM", and the sign-in accounts it makes for heads and some
 * staff end in @attendance-demo.test (password "password"), so --clear removes them again.
 * Holidays, leave types and the branch's phone clock-in setting are left in place by --clear.
 * Refuses to run in production.
 *
 * Keep it up to date: a new attendance feature gets demo data here too.
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

    /** Name => [start, end, code, colour]. */
    private const SHIFTS = ['Morning' => ['07:00', '14:00', 'M', '#0d9488'], 'Afternoon' => ['14:00', '21:00', 'A', '#f59e0b'], 'Night' => ['21:00', '07:00', 'N', '#1e3a8a']];

    /** The demo's sign-in accounts end in this. */
    private const EMAIL = '@attendance-demo.test';

    /** Saved rotation patterns (shift names; null is a day off). */
    private const PATTERNS = [
        'Demo · ward 2-2-2-2' => ['Morning', 'Morning', 'Afternoon', 'Afternoon', 'Night', 'Night', null, null],
        'Demo · early and late' => ['Morning', 'Afternoon', 'Morning', 'Afternoon', 'Morning', null, null],
    ];

    /** Ghana's public holidays in 2026. */
    private const HOLIDAYS = [
        '2026-01-01' => "New Year's Day", '2026-01-07' => 'Constitution Day', '2026-03-06' => 'Independence Day', '2026-03-20' => 'Eid al-Fitr',
        '2026-04-03' => 'Good Friday', '2026-04-06' => 'Easter Monday', '2026-05-01' => 'May Day', '2026-05-27' => 'Eid al-Adha',
        '2026-07-01' => 'Republic Day', '2026-08-04' => "Founders' Day", '2026-09-21' => 'Kwame Nkrumah Memorial Day',
        '2026-12-04' => "Farmers' Day", '2026-12-25' => 'Christmas Day', '2026-12-26' => 'Boxing Day',
    ];

    /** The branch the demo marks for phone clock-in (central Accra). */
    private const BRANCH_AT = [5.6037, -0.1870];

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
        AttendancePattern::query()->whereIn('name', array_keys(self::PATTERNS))->delete();
        $accounts = DB::table('users')->where('email', 'like', '%'.self::EMAIL)->pluck('id');
        if ($accounts->isNotEmpty()) {
            foreach (['branch_user_role', 'branch_user', 'clinic_user'] as $table) {
                DB::table($table)->whereIn('user_id', $accounts)->delete();
            }
            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $accounts)->delete();
            DB::table('users')->whereIn('id', $accounts)->delete();
        }
        if ($removed) {
            $this->line("Removed {$removed} made-up people".($accounts->isNotEmpty() ? ' and '.$accounts->count().' demo accounts' : '').'.');
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
        foreach (self::SHIFTS as $name => [$start, $end, $code, $colour]) {
            $shifts[$name] = AttendanceShift::query()->firstOrCreate(['name' => $name], ['starts_at' => $start, 'ends_at' => $end]);
            $shifts[$name]->update(['code' => $code, 'colour' => $colour]);
        }
        $cycle = [$shifts['Morning'], $shifts['Morning'], $shifts['Afternoon'], $shifts['Afternoon'], $shifts['Night'], $shifts['Night'], null, null];
        // What came later uses its own sequence, so the people, their habits and punches stay as before.
        $more = new \Random\Randomizer(new \Random\Engine\Mt19937(1016));
        $holidays = $this->setUp($shifts, $now);
        $device = AttendanceDevice::create(['name' => 'Demo terminal']);
        $device->forceFill(['first_event_at' => $first->format('Y-m-d 06:00:00'), 'last_event_at' => $now->format('Y-m-d H:i:s'), 'last_seen_at' => now()])->save();

        $share = array_sum(array_column(self::DEPARTMENTS, 0));
        $number = 0;
        $punches = [];
        $roster = [];
        $people = [];
        $works = [];
        $phoneUsers = [];
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
                $works[$staff->id] = $onShifts;
                // About one office worker in six clocks in from their phone for the last ten days.
                $byPhone = ! $onShifts && $more->getInt(1, 6) === 1;
                if ($byPhone) $phoneUsers[] = $staff;
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
                    // Office staff mostly stay home on a public holiday; the wards still run.
                    if (! $shift && isset($holidays[$day->format('Y-m-d')]) && $more->getInt(1, 10) > 1) {
                        continue;
                    }
                    $fromPhone = $byPhone && $day->gte($now->startOfDay()->subDays(10));
                    foreach ($times as $time) {
                        if (! $time->lte($now)) continue;
                        $punch = $tenant + ['attendance_staff_id' => $staff->id, 'punched_at' => $time->format('Y-m-d H:i:s'), 'source' => 'terminal', 'attendance_device_id' => $device->id,
                            'latitude' => null, 'longitude' => null, 'accuracy' => null, 'distance' => null];
                        if ($fromPhone) {
                            $metres = $more->getInt(4, 120);
                            $punch = ['source' => 'phone', 'attendance_device_id' => null, 'latitude' => round(self::BRANCH_AT[0] + $metres / 111320 * ($more->getInt(0, 1) ? 1 : -1), 7),
                                'longitude' => round(self::BRANCH_AT[1] + $more->getInt(-60, 60) / 111320, 7), 'accuracy' => $more->getInt(6, 30), 'distance' => $metres] + $punch;
                        }
                        $punches[] = $punch;
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

        $owner = (int) DB::table('clinic_user')->where('clinic_id', $clinicId)->where('clinic_role', 'Super Admin')->where('status', 'active')->value('user_id') ?: null;
        $accounts = $this->accounts($clinicId, $branchId, $people, $phoneUsers, $more);

        // Leave: some taken, some coming, a few still waiting for an answer.
        foreach (array_rand($people, min(count($people), max(3, intdiv($count, 20)))) as $n => $index) {
            $from = $now->startOfDay()->addDays(mt_rand(-$days + 1, 12));
            $user = $people[$index]->user_id;
            AttendanceLeave::create(['attendance_staff_id' => $people[$index]->id, 'starts_on' => $from->format('Y-m-d'), 'ends_on' => $from->addDays(mt_rand(0, 4))->format('Y-m-d'),
                'type' => ['annual', 'annual', 'sick', 'permission'][mt_rand(0, 3)], 'status' => $n % 6 === 0 ? AttendanceLeave::PENDING : AttendanceLeave::APPROVED, 'note' => 'Demo',
                'requested_by' => $user, 'created_by' => $user ?: $owner, 'reviewed_by' => $n % 6 === 0 ? null : $owner, 'reviewed_at' => $n % 6 === 0 ? null : now()]);
        }
        $this->moreLeave($people, $owner, $now, $more);
        $corrected = $this->corrections($people, $works, $owner, $now, $more);
        $swapped = $this->swaps($people, $works, $owner, $now, $more);
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

        $this->info("Made {$number} people in ".count(self::DEPARTMENTS).' departments, '.number_format(count($punches)).' punches from '.$first->format('j M').' to now ('
            .number_format(count(array_filter($punches, fn ($punch) => $punch['source'] === 'phone'))).' from phones), '.number_format(count($roster))." roster days, {$swapped} swaps, "
            ."{$corrected} corrected punches and {$answered} overtime answers.");
        $this->info("{$accounts} sign-in accounts (department heads and some staff), all with the password \"password\", e.g. dm0001".self::EMAIL.' (head of Nursing). Remove it all with --clear.');
    }

    /** Holidays, leave types, rest rules, phone clock-in and saved patterns: what a branch sets once. Returns the holidays by date. */
    private function setUp(array $shifts, CarbonImmutable $now): array
    {
        foreach (self::HOLIDAYS as $date => $name) {
            AttendanceHoliday::query()->firstOrCreate(['date' => $date], ['name' => $name]);
        }
        foreach (AttendanceLeaveType::DEFAULTS + ['maternity' => ['Maternity leave', 60], 'study' => ['Study leave', 10]] as $code => [$name, $allowance]) {
            AttendanceLeaveType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'days_per_year' => $allowance, 'is_paid' => $code !== 'study', 'is_active' => true]);
        }
        // A branch's own choices are kept; only what it has not set is filled in.
        $settings = AttendanceSetting::query()->first() ?? new AttendanceSetting(['hours' => array_fill_keys([1, 2, 3, 4, 5], ['start' => '08:00', 'end' => '17:00']), 'grace_minutes' => 15, 'break_minutes' => 60]);
        $settings->fill(['phone_clock_in' => true, 'clock_in_latitude' => self::BRANCH_AT[0], 'clock_in_longitude' => self::BRANCH_AT[1]]);
        $settings->phone_clock_in_since ??= $now->subDays(10)->format('Y-m-d');
        $settings->min_rest_hours ??= 11;
        $settings->max_nights_in_row ??= 3;
        $settings->save();
        foreach (self::PATTERNS as $name => $days) {
            AttendancePattern::query()->updateOrCreate(['name' => $name], ['days' => array_map(fn ($shift) => $shift ? $shifts[$shift]->id : 0, $days)]);
        }

        return self::HOLIDAYS;
    }

    /**
     * Sign-in accounts: one head for each department (its first person), everyone who clocks in by phone, and a few
     * more, each linked to their attendance record so they see Profile → My attendance. Returns how many.
     */
    private function accounts(int $clinicId, int $branchId, array $people, array $phoneUsers, \Random\Randomizer $more): int
    {
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web']);
        $heads = collect($people)->groupBy('attendance_department_id')->map->first();
        $linked = $heads->values()->merge($phoneUsers)->merge(collect($people)->filter(fn () => $more->getInt(1, 40) === 1))->unique('id');
        $password = \Illuminate\Support\Facades\Hash::make('password');
        $stamp = now();
        foreach ($linked as $staff) {
            $userId = DB::table('users')->insertGetId(['name' => $staff->name, 'email' => strtolower($staff->employee_no).self::EMAIL, 'password' => $password,
                'email_verified_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]);
            DB::table('clinic_user')->insert(['clinic_id' => $clinicId, 'user_id' => $userId, 'status' => 'active', 'is_default' => 1, 'clinic_role' => 'Staff', 'joined_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]);
            DB::table('branch_user')->insert(['branch_id' => $branchId, 'user_id' => $userId, 'status' => 'active', 'is_default' => 1, 'joined_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]);
            DB::table('branch_user_role')->insert(['branch_id' => $branchId, 'user_id' => $userId, 'role_id' => $role->id, 'created_at' => $stamp, 'updated_at' => $stamp]);
            DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_type' => User::class, 'model_id' => $userId]);
            DB::table('attendance_staff')->where('id', $staff->id)->update(['user_id' => $userId]);
            $staff->user_id = $userId;
        }
        foreach ($heads as $departmentId => $head) {
            AttendanceDepartment::query()->whereKey($departmentId)->update(['heads' => json_encode([$head->user_id])]);
        }

        return $linked->count();
    }

    /** Leave of every kind: a long maternity leave, study days, part-day permissions, and some turned down. */
    private function moreLeave(array $people, ?int $owner, CarbonImmutable $now, \Random\Randomizer $more): void
    {
        $today = $now->startOfDay();
        $pick = fn () => $people[$more->getInt(0, count($people) - 1)];
        $leave = fn (AttendanceStaff $staff, array $fields) => AttendanceLeave::create(['attendance_staff_id' => $staff->id, 'note' => 'Demo', 'requested_by' => $staff->user_id,
            'created_by' => $staff->user_id ?: $owner, 'reviewed_by' => ($fields['status'] ?? AttendanceLeave::APPROVED) === AttendanceLeave::PENDING ? null : $owner,
            'reviewed_at' => ($fields['status'] ?? AttendanceLeave::APPROVED) === AttendanceLeave::PENDING ? null : now()] + $fields);
        for ($i = 0; $i < 2; $i++) {
            $from = $today->subDays($more->getInt(5, 20));
            $leave($pick(), ['type' => 'maternity', 'starts_on' => $from->format('Y-m-d'), 'ends_on' => $from->addWeekdays(59)->format('Y-m-d')]);
        }
        for ($i = 0; $i < 4; $i++) {
            $from = $today->addDays($more->getInt(-20, 10));
            $leave($pick(), ['type' => 'study', 'starts_on' => $from->format('Y-m-d'), 'ends_on' => $from->addDays($more->getInt(0, 2))->format('Y-m-d'), 'status' => $i === 3 ? AttendanceLeave::PENDING : AttendanceLeave::APPROVED]);
        }
        for ($i = 0; $i < 8; $i++) {
            $day = $today->addDays($more->getInt(-20, 7))->format('Y-m-d');
            [$from, $to] = [['08:00', '11:00'], ['13:00', '17:00']][$more->getInt(0, 1)];
            $leave($pick(), ['type' => 'permission', 'starts_on' => $day, 'ends_on' => $day, 'from_time' => $from, 'to_time' => $to, 'status' => $i < 2 ? AttendanceLeave::PENDING : AttendanceLeave::APPROVED]);
        }
        for ($i = 0; $i < 4; $i++) {
            $from = $today->addDays($more->getInt(2, 14));
            $leave($pick(), ['type' => 'annual', 'starts_on' => $from->format('Y-m-d'), 'ends_on' => $from->addDays($more->getInt(1, 4))->format('Y-m-d'), 'status' => AttendanceLeave::REJECTED,
                'review_note' => ['Too many of the team away that week.', 'Ward short of cover; please pick other days.'][$i % 2]]);
        }
    }

    /**
     * Punches put right by a manager: a forgotten punch-out added for office staff, and a few punches moved where the
     * terminal's clock was behind (the old one is kept, voided, with the new one replacing it). Returns how many.
     */
    private function corrections(array $people, array $works, ?int $owner, CarbonImmutable $now, \Random\Randomizer $more): int
    {
        $office = array_keys(array_filter($works, fn ($onShifts) => ! $onShifts));
        $stamp = now()->format('Y-m-d H:i:s');
        $before = $now->startOfDay()->format('Y-m-d H:i:s');
        // Days with a single punch: the person came in and never punched out.
        $missing = DB::table('attendance_punches')->whereIn('attendance_staff_id', $office)->where('punched_at', '<', $before)->whereNull('voided_at')
            ->selectRaw('attendance_staff_id, DATE(punched_at) as day, MAX(punched_at) as last, COUNT(*) as n')->groupByRaw('attendance_staff_id, DATE(punched_at)')
            ->havingRaw('COUNT(*) = 1')->orderBy('day')->limit(400)->get()->filter(fn ($row) => substr($row->last, 11, 2) < '12')->values();
        $added = 0;
        foreach ($missing->shuffle(7)->take(18) as $row) {
            $first = DB::table('attendance_punches')->where('attendance_staff_id', $row->attendance_staff_id)->where('punched_at', $row->last)->first();
            DB::table('attendance_punches')->insert(['clinic_id' => $first->clinic_id, 'branch_id' => $first->branch_id, 'attendance_staff_id' => $row->attendance_staff_id,
                'punched_at' => $row->day.' 17:'.sprintf('%02d', $more->getInt(0, 25)).':00', 'source' => 'manual', 'created_by' => $owner,
                'reason' => ['Forgot to punch out; confirmed by the head of department.', 'Terminal did not read the finger at the end of the day.', 'Left with the last patient; seen by security.'][$more->getInt(0, 2)],
                ]);
            $added++;
        }
        // Late arrivals the terminal got wrong: moved earlier.
        $late = DB::table('attendance_punches')->whereIn('attendance_staff_id', $office)->where('punched_at', '<', $before)->where('source', 'terminal')
            ->whereRaw("TIME(punched_at) BETWEEN '08:20:00' AND '08:50:00'")->orderBy('id')->limit(200)->get();
        foreach ($late->shuffle(11)->take(6) as $punch) {
            $reason = 'The terminal\'s clock was 20 minutes behind that morning.';
            DB::table('attendance_punches')->where('id', $punch->id)->update(['voided_at' => $stamp, 'voided_by' => $owner, 'void_reason' => $reason]);
            DB::table('attendance_punches')->insert(['clinic_id' => $punch->clinic_id, 'branch_id' => $punch->branch_id, 'attendance_staff_id' => $punch->attendance_staff_id,
                'punched_at' => CarbonImmutable::parse($punch->punched_at)->subMinutes(20)->format('Y-m-d H:i:s'), 'source' => 'manual', 'created_by' => $owner,
                'reason' => $reason, 'replaces_id' => $punch->id]);
            $added++;
        }

        return $added;
    }

    /** A few shift swaps next week inside a ward, the way a head records them. Returns how many pairs. */
    private function swaps(array $people, array $works, ?int $owner, CarbonImmutable $now, \Random\Randomizer $more): int
    {
        $byDepartment = collect($people)->filter(fn ($staff) => $works[$staff->id])->groupBy('attendance_department_id');
        $swaps = 0;
        foreach ($byDepartment->take(6) as $members) {
            $day = $now->startOfDay()->addDays($more->getInt(3, 9))->format('Y-m-d');
            $rows = DB::table('attendance_roster')->whereIn('attendance_staff_id', $members->pluck('id'))->where('date', $day)->whereNotNull('attendance_shift_id')->get()->keyBy('attendance_staff_id');
            $a = $rows->first();
            $b = $a ? $rows->first(fn ($row) => $row->attendance_shift_id !== $a->attendance_shift_id) : null;
            if (! $a || ! $b) continue;
            $names = $members->keyBy('id');
            DB::table('attendance_roster')->where('id', $a->id)->update(['attendance_shift_id' => $b->attendance_shift_id, 'note' => 'Swapped with '.$names[$b->attendance_staff_id]->name, 'set_by' => $owner]);
            DB::table('attendance_roster')->where('id', $b->id)->update(['attendance_shift_id' => $a->attendance_shift_id, 'note' => 'Swapped with '.$names[$a->attendance_staff_id]->name, 'set_by' => $owner]);
            $swaps++;
        }

        return $swaps;
    }
}
