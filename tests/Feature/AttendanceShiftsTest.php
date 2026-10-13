<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\AttendanceRoster;
use App\Models\AttendanceShift;
use App\Models\AttendanceStaff;
use App\Models\User;
use App\Services\Attendance\AttendanceAnalyzer;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * People are judged against the shift they are on: the roster for the date, else their weekly
 * pattern, else their own or the branch's hours. A night shift belongs to the day it starts.
 * October 2026: the 19th is a Monday.
 */
class AttendanceShiftsTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private AttendanceStaff $ama;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Carbon::setTestNow('2026-10-31 12:00:00');
        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $clinic = $this->activeClinic(['name' => 'Clinic A', 'slug' => 'clinic-a']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $admin = User::factory()->create();
        $admin->assignRole($role);
        $clinic->users()->attach($admin, ['status' => 'active', 'clinic_role' => 'Super Admin', 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $branch->id, 'user_id' => $admin->id, 'status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $admin->id, 'role_id' => $role->id]);
        $this->actingAs($admin);
        app(TenantContext::class)->set($admin, $clinic, $branch, [$branch->id]);

        AttendanceDevice::create(['name' => 'Front desk'])->forceFill(['first_event_at' => '2026-10-01 07:00:00', 'last_event_at' => '2026-10-31 11:00:00', 'last_seen_at' => now()])->save();
        $this->ama = AttendanceStaff::create(['employee_no' => '001', 'name' => 'Ama Owusu', 'is_tracked' => true]);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function punch(string ...$at): void
    {
        foreach ($at as $time) {
            AttendancePunch::create(['attendance_staff_id' => $this->ama->id, 'punched_at' => $time, 'source' => 'terminal']);
        }
    }

    private function days(string $from, string $to): array
    {
        $report = app(AttendanceAnalyzer::class)->range(CarbonImmutable::parse($from), CarbonImmutable::parse($to));

        return collect($report['rows'])->first(fn ($row) => $row['staff']->id === $this->ama->id)['days'];
    }

    public function test_someone_is_judged_against_their_weekly_shift_and_the_roster_overrides_a_day(): void
    {
        $afternoon = AttendanceShift::create(['name' => 'Afternoon', 'starts_at' => '14:00', 'ends_at' => '20:00', 'break_minutes' => 30]);
        $this->ama->update(['shift_week' => [1 => $afternoon->id, 2 => $afternoon->id, 6 => 0]]);
        $this->punch('2026-10-19 14:05:00', '2026-10-19 20:01:00', '2026-10-21 08:40:00', '2026-10-21 17:00:00');

        $days = $this->days('2026-10-19', '2026-10-24');
        // Monday: on the afternoon shift, in at 14:05 is on time (against 8 AM it would be six hours late).
        $this->assertSame(['on_time', 0, 'Afternoon'], [$days['2026-10-19']['status'], $days['2026-10-19']['late_minutes'], $days['2026-10-19']['hours']['shift']]);
        // Tuesday: on the shift, never came.
        $this->assertSame('absent', $days['2026-10-20']['status']);
        // Wednesday: no shift given, so the branch's 8 to 5 applies, and 8:40 is late.
        $this->assertSame(['late', 40], [$days['2026-10-21']['status'], $days['2026-10-21']['late_minutes']]);
        $this->assertArrayNotHasKey('shift', $days['2026-10-21']['hours']);
        // Saturday is a day off in their week.
        $this->assertSame('off', $days['2026-10-24']['status']);

        // The roster changes single days: Tuesday off, Thursday on the afternoon shift.
        AttendanceRoster::create(['attendance_staff_id' => $this->ama->id, 'date' => '2026-10-20', 'attendance_shift_id' => null]);
        AttendanceRoster::create(['attendance_staff_id' => $this->ama->id, 'date' => '2026-10-22', 'attendance_shift_id' => $afternoon->id]);
        $days = $this->days('2026-10-19', '2026-10-24');
        $this->assertSame('off', $days['2026-10-20']['status']);
        $this->assertSame(['absent', 'Afternoon'], [$days['2026-10-22']['status'], $days['2026-10-22']['hours']['shift']]);
    }

    public function test_a_night_shift_belongs_to_the_day_it_starts_and_keeps_its_morning_punches(): void
    {
        $night = AttendanceShift::create(['name' => 'Night', 'starts_at' => '20:00', 'ends_at' => '06:00']);
        $this->assertTrue($night->overnight());
        $this->assertSame(['start' => '20:00', 'end' => '30:00', 'shift' => 'Night', 'break' => null], $night->hours());
        AttendanceRoster::create(['attendance_staff_id' => $this->ama->id, 'date' => '2026-10-20', 'attendance_shift_id' => $night->id]);
        AttendanceRoster::create(['attendance_staff_id' => $this->ama->id, 'date' => '2026-10-21', 'attendance_shift_id' => null]);
        // In just before 8 PM on Tuesday, out just after 6 AM on Wednesday.
        $this->punch('2026-10-20 19:58:00', '2026-10-21 06:03:00');

        $days = $this->days('2026-10-20', '2026-10-21');
        $tuesday = $days['2026-10-20'];
        $this->assertSame(['on_time', '19:58', '30:03', 605, false, false], [$tuesday['status'], $tuesday['in'], $tuesday['out'], $tuesday['minutes_worked'], $tuesday['no_clock_out'], $tuesday['left_early']]);
        $this->assertSame('6:03 AM +1', AttendanceAnalyzer::time($tuesday['out']));
        // Wednesday's own day has no punches left on it: the 6:03 belonged to Tuesday's shift.
        $this->assertSame(['off', []], [$days['2026-10-21']['status'], $days['2026-10-21']['punches']]);

        // Asked for Wednesday alone, the morning punch still isn't counted as Wednesday's.
        $this->assertSame([], $this->days('2026-10-21', '2026-10-21')['2026-10-21']['punches']);
        // Leaving at 4 AM is leaving two hours early.
        AttendancePunch::query()->where('punched_at', '2026-10-21 06:03:00')->update(['punched_at' => '2026-10-21 04:00:00']);
        $early = $this->days('2026-10-20', '2026-10-20')['2026-10-20'];
        $this->assertSame([true, 120], [$early['left_early'], $early['early_minutes']]);
    }

    public function test_shifts_are_managed_and_people_rostered_from_the_page(): void
    {
        $page = Livewire::test(AttendanceComponent::class)->set('showShifts', true)
            ->set('shiftName', 'Morning')->set('shiftStart', '08:00')->set('shiftEnd', '08:00')->call('addShift')->assertHasErrors(['shiftEnd'])
            ->set('shiftEnd', '14:00')->set('shiftBreak', '30')->call('addShift')->assertHasNoErrors()->assertSee('Morning')
            ->set('shiftName', 'Morning')->set('shiftStart', '09:00')->set('shiftEnd', '15:00')->call('addShift')->assertHasErrors(['shiftName']);
        $morning = AttendanceShift::where('name', 'Morning')->firstOrFail();
        $this->assertSame(30, $morning->break_minutes);

        // A person's week: Monday morning shift, Sunday off; other days stay on the usual hours.
        $page->call('openStaff', $this->ama->id)->assertSee('Weekly shifts')
            ->set('staffShiftWeek.1', (string) $morning->id)->set('staffShiftWeek.7', '0')->set('staffShiftWeek.3', '999')->call('saveStaff')->assertHasNoErrors();
        $this->assertSame([1 => $morning->id, 7 => 0], $this->ama->fresh()->shift_week);

        // The roster for the week of Monday 19 October.
        $page->set('from', '2026-10-19')->set('to', '2026-10-25')->call('openRoster')->assertSet('rosterWeek', '2026-10-19')
            ->assertSee('Week of 19 Oct')->assertSee('Ama Owusu')
            ->call('setRoster', $this->ama->id, '2026-10-21', (string) $morning->id)
            ->call('setRoster', $this->ama->id, '2026-10-22', '0');
        $this->assertSame($morning->id, AttendanceRoster::whereDate('date', '2026-10-21')->value('attendance_shift_id'));
        $this->assertNull(AttendanceRoster::whereDate('date', '2026-10-22')->firstOrFail()->attendance_shift_id);
        $page->call('setRoster', $this->ama->id, '2026-10-22', '');
        $this->assertSame(0, AttendanceRoster::whereDate('date', '2026-10-22')->count());
        $page->call('shiftRosterWeek', 1)->assertSet('rosterWeek', '2026-10-26');

        // A shift in use can't be deleted, only no longer offered; an unused one goes.
        $page->call('removeShift', $morning->id)->assertHasErrors(['shiftName']);
        $this->assertNotNull($morning->fresh());
        $page->call('toggleShift', $morning->id);
        $this->assertFalse($morning->fresh()->is_active);
        $spare = AttendanceShift::create(['name' => 'Spare', 'starts_at' => '10:00', 'ends_at' => '12:00']);
        $page->call('removeShift', $spare->id);
        $this->assertNull($spare->fresh());
    }
}
