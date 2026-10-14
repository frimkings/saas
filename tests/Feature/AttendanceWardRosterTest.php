<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Models\AttendanceCover;
use App\Models\AttendanceDepartment;
use App\Models\AttendanceDevice;
use App\Models\AttendanceLeave;
use App\Models\AttendancePunch;
use App\Models\AttendanceRoster;
use App\Models\AttendanceShift;
use App\Models\AttendanceStaff;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * Rostering a ward: minimum cover per shift, filling the roster from a rotation pattern, a head
 * swapping two people's shifts, and warnings about too little rest.
 * October 2026: the 19th is a Monday. It is 9 AM that Monday.
 */
class AttendanceWardRosterTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private AttendanceDepartment $nursing;

    private AttendanceShift $morning;

    private AttendanceShift $night;

    private AttendanceStaff $ama;

    private AttendanceStaff $kofi;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Carbon::setTestNow('2026-10-19 09:00:00');
        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $clinic = $this->activeClinic(['name' => 'Hospital A', 'slug' => 'hospital-a']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $admin = User::factory()->create();
        $admin->assignRole($role);
        $clinic->users()->attach($admin, ['status' => 'active', 'clinic_role' => 'Super Admin', 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $branch->id, 'user_id' => $admin->id, 'status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $admin->id, 'role_id' => $role->id]);
        $this->actingAs($admin);
        app(TenantContext::class)->set($admin, $clinic, $branch, [$branch->id]);

        AttendanceDevice::create(['name' => 'Ward door'])->forceFill(['first_event_at' => '2026-10-01 07:00:00', 'last_event_at' => '2026-10-19 08:00:00', 'last_seen_at' => now()])->save();
        $this->nursing = AttendanceDepartment::create(['name' => 'Nursing']);
        $this->morning = AttendanceShift::create(['name' => 'Morning', 'starts_at' => '07:00', 'ends_at' => '14:00']);
        $this->night = AttendanceShift::create(['name' => 'Night', 'starts_at' => '20:00', 'ends_at' => '06:00']);
        $this->ama = AttendanceStaff::create(['employee_no' => '001', 'name' => 'Ama Owusu', 'is_tracked' => true, 'attendance_department_id' => $this->nursing->id,
            'shift_week' => array_fill_keys([1, 2, 3, 4, 5], $this->morning->id) + [6 => 0, 7 => 0]]);
        $this->kofi = AttendanceStaff::create(['employee_no' => '002', 'name' => 'Kofi Boateng', 'is_tracked' => true, 'attendance_department_id' => $this->nursing->id,
            'shift_week' => array_fill_keys([1, 2, 3, 4, 5, 6, 7], 0)]);
        AttendanceRoster::create(['attendance_staff_id' => $this->kofi->id, 'date' => '2026-10-19', 'attendance_shift_id' => $this->morning->id]);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_roster_is_filled_from_a_pattern_then_swapped_and_checked_for_rest(): void
    {
        [$m, $n] = [(string) $this->morning->id, (string) $this->night->id];
        $page = Livewire::test(\App\Livewire\Admin\AttendanceRosterComponent::class)->call('togglePattern')->assertSet('patternFrom', '2026-10-19')->assertSee('Fill from a rotation pattern')
            // Two mornings, two nights, two off.
            ->set('patternAddShift', $m)->set('patternAddCount', '2')->call('addPatternStep')
            ->set('patternAddShift', $n)->call('addPatternStep')
            ->set('patternAddShift', '0')->set('patternAddCount', '3')->call('addPatternStep')->assertCount('patternDays', 7)
            ->call('undoPatternStep')->assertSet('patternDays', [$m, $m, $n, $n])
            ->set('patternAddCount', '2')->call('addPatternStep')->assertSet('patternDays', [$m, $m, $n, $n, '0', '0'])
            ->set('patternTo', '2026-10-30')->call('applyPattern')->assertHasErrors(['patternPeople'])
            // Tick all and untick all.
            ->call('tickAllPattern', true)->assertCount('patternPeople', 2)->assertSee('2 of 2 ticked')->call('tickAllPattern', false)->assertSet('patternPeople', [])
            // Kofi starts two days further into the cycle than Ama.
            ->set('patternPeople', [(string) $this->kofi->id, (string) $this->ama->id])->set('patternStagger', '2')
            ->set('patternName', 'Ward 2-2-2')->call('savePattern')->assertHasNoErrors()
            ->call('applyPattern')->assertHasNoErrors()->assertSet('showPattern', false);
        $this->assertSame([(int) $m, (int) $m, (int) $n, (int) $n, 0, 0], \App\Models\AttendancePattern::where('name', 'Ward 2-2-2')->firstOrFail()->days);
        $this->assertSame(24, AttendanceRoster::count());
        $on = fn (AttendanceStaff $staff, string $date) => AttendanceRoster::where('attendance_staff_id', $staff->id)->whereDate('date', $date)->firstOrFail()->attendance_shift_id;
        $this->assertSame([(int) $m, (int) $n, null, (int) $m], [$on($this->ama, '2026-10-19'), $on($this->ama, '2026-10-21'), $on($this->ama, '2026-10-23'), $on($this->ama, '2026-10-25')]);
        $this->assertSame([(int) $n, null, (int) $m], [$on($this->kofi, '2026-10-19'), $on($this->kofi, '2026-10-21'), $on($this->kofi, '2026-10-23')]);

        // The head swaps the two on Monday: Ama takes Kofi's night, Kofi her morning.
        $page->call('toggleSwap')->set('swapA', (string) $this->ama->id)->set('swapB', (string) $this->ama->id)->call('swapRoster')->assertHasErrors(['swapB'])
            ->set('swapB', (string) $this->kofi->id)->set('swapFrom', '2026-10-19')->call('swapRoster')->assertHasNoErrors()->assertSee('Swapped with Kofi Boateng');
        $this->assertSame([(int) $n, (int) $m], [$on($this->ama, '2026-10-19'), $on($this->kofi, '2026-10-19')]);
        $this->assertSame(1, \App\Models\AuditTrail::where('event', 'attendance.shifts_swapped')->count());
        // Someone on usual hours has no shift to swap.
        $yaa = AttendanceStaff::create(['employee_no' => '003', 'name' => 'Yaa Mensah', 'is_tracked' => true, 'attendance_department_id' => $this->nursing->id]);
        $page->call('toggleSwap')->set('swapA', (string) $this->ama->id)->set('swapB', (string) $yaa->id)->set('swapFrom', '2026-10-20')->call('swapRoster')->assertHasErrors(['swapFrom']);

        // Rest rules: 11 hours between shifts, no more than one night in a row.
        Livewire::test(AttendanceComponent::class)->call('editHours')->set('minRestHours', '11')->set('maxNights', '1')->call('saveHours')->assertHasNoErrors();
        // Ama now works Monday night (ends 6 AM Tuesday) then Tuesday morning at 7: an hour's rest. Her Wednesday and Thursday are both nights.
        Livewire::test(\App\Livewire\Admin\AttendanceRosterComponent::class)->assertSee('Only 1h rest after Night')->assertSee('Night 2 in a row');
    }

    public function test_the_roster_and_the_board_show_where_a_department_is_short(): void
    {
        // Two on the morning shift each day, one at weekends.
        $page = Livewire::test(AttendanceComponent::class)->call('editDepartment', $this->nursing->id)
            ->set('departmentMembers', [(string) $this->ama->id, (string) $this->kofi->id])
            ->set('departmentCover.'.$this->morning->id.'.min', '2')->set('departmentCover.'.$this->morning->id.'.weekend', '1')
            ->set('departmentCover.'.$this->night->id.'.min', '')->call('saveDepartment')->assertHasNoErrors();
        $cover = AttendanceCover::firstOrFail();
        $this->assertSame([1, $this->morning->id, 2, 1], [AttendanceCover::count(), $cover->attendance_shift_id, $cover->minimum, $cover->weekend_minimum]);

        // Monday has both; Tuesday to Friday only Ama; nobody at the weekend: six shifts short.
        Livewire::test(\App\Livewire\Admin\AttendanceRosterComponent::class)->assertSee('Nursing · Morning')->assertSee('6 shifts are short this week')
            ->assertSeeHtml('title="2 rostered, 2 needed"')->assertSeeHtml('title="1 rostered, 2 needed"')->assertSeeHtml('title="0 rostered, 1 needed"');
        // Someone on leave is not counted as cover.
        AttendanceLeave::create(['attendance_staff_id' => $this->ama->id, 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-19', 'type' => 'sick']);
        Livewire::test(\App\Livewire\Admin\AttendanceRosterComponent::class)->assertSee('7 shifts are short this week');
        AttendanceLeave::query()->delete();

        // Right now, Monday 9 AM: only Ama has punched in, so the ward is one short.
        AttendancePunch::create(['attendance_staff_id' => $this->ama->id, 'punched_at' => '2026-10-19 06:55:00', 'source' => 'terminal']);
        Livewire::test(AttendanceComponent::class)->assertSet('tab', 'today')->assertSeeHtml('wire:key="board-unit-'.$this->nursing->id.'"')->assertDontSee('Ama Owusu')->assertSee('1 shift is short now')->assertSee('Morning: 1 of 2')
            ->set('showShort', true)->assertSee('is short on the Morning shift: 1 in, 2 needed')
            // Opening the department shows its names.
            ->set('boardUnit', (string) $this->nursing->id)->assertSeeHtml('wire:key="board-drawer-'.$this->nursing->id.'"')->assertSee('Ama Owusu')->assertSee('In since 6:55 AM')
            // From the panel to the department's figures in the report.
            ->call('showDepartmentReport', (string) $this->nursing->id)->assertSet('tab', 'report')->assertSet('department', (string) $this->nursing->id)->assertSet('boardUnit', '');
        AttendancePunch::create(['attendance_staff_id' => $this->kofi->id, 'punched_at' => '2026-10-19 07:05:00', 'source' => 'terminal']);
        Livewire::test(AttendanceComponent::class)->assertDontSee('short now');
    }
}
