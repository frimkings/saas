<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Livewire\UserProfileComponent;
use App\Models\AttendanceDevice;
use App\Models\AttendanceHoliday;
use App\Models\AttendanceLeave;
use App\Models\AttendanceLeaveType;
use App\Models\AttendanceStaff;
use App\Models\User;
use App\Services\Attendance\AttendanceLeaveBalances;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * Each branch has its own kinds of leave with a yearly allowance in working days. Balances count
 * only days the person was due to work; new leave may not run into leave they already have.
 * October 2026: the 19th is a Monday; the branch works 8 to 5, Monday to Friday.
 */
class AttendanceLeaveTypesTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private AttendanceStaff $ama;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        \Illuminate\Support\Carbon::setTestNow('2026-10-12 12:00:00');
        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $clinic = $this->activeClinic(['name' => 'Clinic A', 'slug' => 'clinic-a']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole($role);
        $clinic->users()->attach($this->admin, ['status' => 'active', 'clinic_role' => 'Super Admin', 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $branch->id, 'user_id' => $this->admin->id, 'status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $this->admin->id, 'role_id' => $role->id]);
        $this->actingAs($this->admin);
        app(TenantContext::class)->set($this->admin, $clinic, $branch, [$branch->id]);

        AttendanceDevice::create(['name' => 'Front desk'])->forceFill(['first_event_at' => '2026-10-01 07:00:00', 'last_event_at' => '2026-10-12 11:00:00', 'last_seen_at' => now()])->save();
        $this->ama = AttendanceStaff::create(['employee_no' => '001', 'name' => 'Ama Owusu', 'is_tracked' => true, 'user_id' => $this->admin->id]);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function leave(array $values): AttendanceLeave
    {
        return AttendanceLeave::create($values + ['attendance_staff_id' => $this->ama->id, 'type' => 'annual']);
    }

    public function test_balances_count_working_days_against_the_yearly_allowance(): void
    {
        AttendanceHoliday::create(['date' => '2026-10-21', 'name' => 'Founders']);
        // Monday to the next Monday: six working days, less the holiday on Wednesday = 5... plus Monday 26th = 6 - 1.
        $this->leave(['starts_on' => '2026-10-19', 'ends_on' => '2026-10-26']);
        // An afternoon (half the 9-hour day) and a request still waiting.
        $this->leave(['starts_on' => '2026-10-28', 'ends_on' => '2026-10-28', 'from_time' => '12:30', 'to_time' => '17:00']);
        $this->leave(['starts_on' => '2026-11-02', 'ends_on' => '2026-11-03', 'status' => 'pending']);
        // Turned down, and last year's: neither counts.
        $this->leave(['starts_on' => '2026-11-09', 'ends_on' => '2026-11-13', 'status' => 'rejected']);
        $this->leave(['starts_on' => '2025-12-01', 'ends_on' => '2025-12-05']);
        $this->leave(['starts_on' => '2026-10-29', 'ends_on' => '2026-10-29', 'type' => 'sick']);

        $balances = app(AttendanceLeaveBalances::class)->for($this->ama, 2026);
        $this->assertSame([15.0, 5.5, 2.0, 7.5], [$balances['annual']['allowance'], $balances['annual']['used'], $balances['annual']['pending'], $balances['annual']['left']]);
        $this->assertSame([null, 1.0, null], [$balances['sick']['allowance'], $balances['sick']['used'], $balances['sick']['left']]);
        $this->assertSame('half a day', AttendanceLeaveBalances::days(0.5));

        Livewire::test(AttendanceComponent::class)->set('from', '2026-10-01')->set('to', '2026-10-31')->call('openStaff', $this->ama->id)
            ->assertSee('5.5 of 15 days taken, 7.5 left')->assertSee('2 waiting')
            ->set('showRequests', true)->assertSee('9.5 left');
    }

    public function test_a_branch_edits_its_leave_types_and_allowances(): void
    {
        $page = Livewire::test(AttendanceComponent::class)->call('openLeaveTypes')->assertSee('Annual leave')->assertSee('No limit');
        $this->assertSame(['annual', 'sick', 'permission', 'other'], AttendanceLeaveType::orderBy('id')->pluck('code')->all());

        $page->set('leaveTypeName', 'Annual leave')->call('saveLeaveType')->assertHasErrors(['leaveTypeName'])
            ->set('leaveTypeName', 'Maternity leave')->set('leaveTypeDays', '60')->call('saveLeaveType')->assertHasNoErrors()->assertSee('Maternity leave');
        $maternity = AttendanceLeaveType::where('code', 'maternity_leave')->firstOrFail();
        $this->assertSame([60.0, true], [$maternity->days_per_year, $maternity->is_paid]);

        // Annual goes to 21 days; a new unpaid type.
        $annual = AttendanceLeaveType::where('code', 'annual')->firstOrFail();
        $page->call('editLeaveType', $annual->id)->assertSet('leaveTypeDays', '15')->set('leaveTypeDays', '21')->call('saveLeaveType')->assertHasNoErrors()
            ->set('leaveTypeName', 'Unpaid leave')->set('leaveTypePaid', false)->call('saveLeaveType');
        $this->assertSame(21.0, $annual->fresh()->days_per_year);
        $this->assertFalse(AttendanceLeaveType::where('name', 'Unpaid leave')->firstOrFail()->is_paid);
        $this->assertSame(21.0, app(AttendanceLeaveBalances::class)->for($this->ama, 2026)['annual']['allowance']);

        // Leave is given under the new type and carries its name.
        $page->call('openStaff', $this->ama->id)->set('leaveFrom', '2026-10-19')->set('leaveType', 'maternity_leave')->call('addLeave')->assertHasNoErrors();
        $this->assertSame('Maternity leave', AttendanceLeave::firstOrFail()->typeName());

        // A type in use can only stop being offered; an unused one is deleted.
        $page->call('removeLeaveType', $maternity->id)->assertHasErrors(['leaveTypeName']);
        $page->call('toggleLeaveType', $maternity->id);
        $this->assertFalse($maternity->fresh()->is_active);
        $page->call('openStaff', $this->ama->id)->set('leaveFrom', '2026-11-02')->set('leaveType', 'maternity_leave')->call('addLeave')->assertHasErrors(['leaveType']);
        $page->call('removeLeaveType', AttendanceLeaveType::where('code', 'other')->value('id'));
        $this->assertSame(0, AttendanceLeaveType::where('code', 'other')->count());
    }

    public function test_leave_may_not_overlap_and_staff_may_not_ask_past_their_allowance(): void
    {
        $this->leave(['starts_on' => '2026-10-19', 'ends_on' => '2026-10-21']);
        $this->leave(['starts_on' => '2026-10-26', 'ends_on' => '2026-10-26', 'from_time' => '08:00', 'to_time' => '12:00', 'status' => 'pending']);

        $page = Livewire::test(AttendanceComponent::class)->call('openStaff', $this->ama->id)
            // Runs into the three days already approved.
            ->set('leaveFrom', '2026-10-21')->set('leaveTo', '2026-10-23')->set('leaveType', 'sick')->call('addLeave')->assertHasErrors(['leaveFrom'])
            // The same morning again clashes; that afternoon does not.
            ->set('leaveFrom', '2026-10-26')->set('leaveTo', '')->set('leaveFromTime', '11:00')->set('leaveToTime', '13:00')->call('addLeave')->assertHasErrors(['leaveFrom'])
            ->set('leaveFromTime', '13:00')->set('leaveToTime', '17:00')->call('addLeave')->assertHasNoErrors();
        $this->assertSame(3, AttendanceLeave::count());

        // A manager may go past the allowance (annual: 15, three taken) and is told.
        $page->set('leaveFrom', '2026-11-02')->set('leaveTo', '2026-11-20')->set('leaveType', 'annual')->call('addLeave')->assertHasNoErrors()
            ->assertDispatched('notify', fn ($name, $params) => ($params['type'] ?? null) === 'warning');
        AttendanceLeave::query()->where('starts_on', '2026-11-02')->delete();

        // Staff asking from their profile: 3.5 used or waiting, so 11.5 left; 12 days is refused, 5 accepted.
        $mine = Livewire::test(UserProfileComponent::class)->set('activeTab', 'attendance')->assertSee('11.5 of 15 days left')
            ->set('leaveFrom', '2026-11-02')->set('leaveTo', '2026-11-17')->set('leaveType', 'annual')->call('requestLeave')->assertHasErrors(['leaveType'])
            ->set('leaveTo', '2026-11-06')->call('requestLeave')->assertHasNoErrors()
            // And not twice for the same days.
            ->set('leaveFrom', '2026-11-05')->set('leaveTo', '2026-11-05')->call('requestLeave')->assertHasErrors(['leaveFrom']);
        $this->assertSame(1, AttendanceLeave::where('status', 'pending')->where('starts_on', '2026-11-02')->count());
        $mine->assertSee('6.5 of 15 days left');
    }
}
