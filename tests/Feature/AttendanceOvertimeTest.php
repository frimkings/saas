<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Models\AttendanceDevice;
use App\Models\AttendanceOvertime;
use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\AttendanceStaff;
use App\Models\AuditTrail;
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
 * Overtime is time on site past the end of the day once it reaches the branch's threshold, or any
 * time on a day off. It counts once a manager approves it, unless the branch switches that off.
 * October 2026: the 19th is a Monday; the branch works 8 to 5, Monday to Friday.
 */
class AttendanceOvertimeTest extends TestCase
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
        foreach ([
            '2026-10-19 08:00:00', '2026-10-19 18:30:00', // Monday: 1h 30m past the end
            '2026-10-20 08:00:00', '2026-10-20 17:20:00', // Tuesday: 20 minutes past, under the threshold
            '2026-10-24 09:00:00', '2026-10-24 12:00:00', // Saturday, a day off: three hours
        ] as $at) {
            AttendancePunch::create(['attendance_staff_id' => $this->ama->id, 'punched_at' => $at, 'source' => 'terminal']);
        }
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(): array
    {
        $report = app(AttendanceAnalyzer::class)->range(CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-25'));

        return collect($report['rows'])->first(fn ($row) => $row['staff']->id === $this->ama->id);
    }

    public function test_overtime_waits_for_a_manager_and_counts_for_the_minutes_approved(): void
    {
        $row = $this->row();
        $this->assertSame([90, 'pending', 0], [$row['days']['2026-10-19']['overtime_minutes'], $row['days']['2026-10-19']['overtime_status'], $row['days']['2026-10-19']['overtime_approved']]);
        $this->assertSame([0, null], [$row['days']['2026-10-20']['overtime_minutes'], $row['days']['2026-10-20']['overtime_status']]);
        $this->assertSame(['worked_off', 180, 'pending'], [$row['days']['2026-10-24']['status'], $row['days']['2026-10-24']['overtime_minutes'], $row['days']['2026-10-24']['overtime_status']]);
        $this->assertSame([0, 270], [$row['totals']['overtime_minutes'], $row['totals']['overtime_pending']]);

        // The Requests tab lists the same, beside leave requests.
        Livewire::test(AttendanceComponent::class)->set('from', '2026-10-19')->set('to', '2026-10-25')->set('tab', 'requests')
            ->assertSee('Leave requests')->assertSee('1h 30m overtime')->assertSee('No requests are waiting.');

        $page = Livewire::test(AttendanceComponent::class)->set('from', '2026-10-19')->set('to', '2026-10-25')
            ->assertSee('days of overtime are')->set('showOvertime', true)->assertSee('1h 30m overtime')->assertSee('on a day off')
            // More than was worked is refused.
            ->set('overtimeMinutes.'.$this->ama->id.'-2026-10-19', '120')->call('decideOvertime', $this->ama->id, '2026-10-19', true)
            ->assertHasErrors(['overtimeMinutes.'.$this->ama->id.'-2026-10-19']);
        $this->assertSame(0, AttendanceOvertime::count());

        // An hour of Monday's approved; Saturday's turned down.
        $page->set('overtimeMinutes.'.$this->ama->id.'-2026-10-19', '60')->set('overtimeNotes.'.$this->ama->id.'-2026-10-19', 'Stock count')
            ->call('decideOvertime', $this->ama->id, '2026-10-19', true)->assertHasNoErrors()
            ->call('decideOvertime', $this->ama->id, '2026-10-24', false)
            ->assertSee('Approved 1h')->assertSee('Not approved')->assertDontSee('waiting for your answer');
        $row = $this->row();
        $this->assertSame(['approved', 60], [$row['days']['2026-10-19']['overtime_status'], $row['days']['2026-10-19']['overtime_approved']]);
        $this->assertSame(['declined', 0], [$row['days']['2026-10-24']['overtime_status'], $row['days']['2026-10-24']['overtime_approved']]);
        $this->assertSame([60, 0], [$row['totals']['overtime_minutes'], $row['totals']['overtime_pending']]);
        $this->assertSame(1, AuditTrail::where('event', 'attendance.overtime_approved')->where('description', 'like', '%1h of overtime for Ama Owusu on 19 Oct 2026: Stock count%')->count());
        $this->assertSame(1, AuditTrail::where('event', 'attendance.overtime_declined')->count());

        // Nothing to answer on a day without overtime.
        $page->call('decideOvertime', $this->ama->id, '2026-10-20', true)->assertStatus(404);

        // An answer can be taken back, and the day waits again.
        Livewire::test(AttendanceComponent::class)->set('from', '2026-10-19')->set('to', '2026-10-25')->call('reopenOvertime', $this->ama->id, '2026-10-24');
        $this->assertSame('pending', $this->row()['days']['2026-10-24']['overtime_status']);
    }

    public function test_the_branch_sets_the_threshold_and_whether_approval_is_needed(): void
    {
        Livewire::test(AttendanceComponent::class)->call('editHours')->assertSet('overtimeAfter', 30)->assertSet('overtimeNeedsApproval', true)
            ->set('overtimeAfter', '0')->call('saveHours')->assertHasErrors(['overtimeAfter'])
            ->set('overtimeAfter', '15')->set('overtimeNeedsApproval', false)->call('saveHours')->assertHasNoErrors();
        $settings = AttendanceSetting::current();
        $this->assertSame([15, false], [$settings->overtime_after_minutes, $settings->overtime_needs_approval]);

        // Tuesday's 20 minutes now count, and nothing waits: it all counts by itself.
        $row = $this->row();
        $this->assertSame([20, 'approved', 20], [$row['days']['2026-10-20']['overtime_minutes'], $row['days']['2026-10-20']['overtime_status'], $row['days']['2026-10-20']['overtime_approved']]);
        $this->assertSame([290, 0], [$row['totals']['overtime_minutes'], $row['totals']['overtime_pending']]);

        // A manager can still turn a day down.
        AttendanceOvertime::create(['attendance_staff_id' => $this->ama->id, 'date' => '2026-10-24', 'status' => 'declined', 'minutes' => 0]);
        $this->assertSame(110, $this->row()['totals']['overtime_minutes']);
    }
}
