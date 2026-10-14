<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Livewire\Admin\AuditTrailViewerComponent;
use App\Models\AttendanceDepartment;
use App\Models\AttendanceLeave;
use App\Models\AttendanceRoster;
use App\Models\AttendanceSetting;
use App\Models\AttendanceShift;
use App\Models\AttendanceStaff;
use App\Models\AuditTrail;
use App\Models\User;
use App\Support\AttendanceAudit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** Changes to attendance by people are noted in the audit trail, which has an Attendance area. */
class AttendanceAuditTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    private function events(): array
    {
        return AuditTrail::where('event', 'like', 'attendance.%')->orderBy('id')->pluck('description', 'event')->all();
    }

    public function test_changes_to_attendance_are_noted(): void
    {
        $ama = AttendanceStaff::create(['employee_no' => '001', 'name' => 'Ama Owusu', 'is_tracked' => true]);
        $shift = AttendanceShift::create(['name' => 'Morning', 'starts_at' => '07:00', 'ends_at' => '14:00']);
        $shift->update(['ends_at' => '15:00']);
        AttendanceSetting::current()->fill(['grace_minutes' => 5])->save();
        AttendanceSetting::current()->fill(['grace_minutes' => 10, 'hours' => [1 => ['start' => '08:00', 'end' => '16:00']]])->save();
        $nursing = AttendanceDepartment::create(['name' => 'Nursing']);
        $ama->update(['attendance_department_id' => $nursing->id, 'name' => 'Ama O.']);
        AttendanceRoster::create(['attendance_staff_id' => $ama->id, 'date' => '2026-10-21', 'attendance_shift_id' => $shift->id]);
        $leave = AttendanceLeave::create(['attendance_staff_id' => $ama->id, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-03', 'type' => 'annual', 'status' => 'pending']);
        $leave->update(['status' => 'approved']);
        $shift->delete();

        $events = $this->events();
        $this->assertSame('Added Ama Owusu to attendance', $events['attendance.staff_added']);
        $this->assertSame('Added the shift Morning', $events['attendance.shift_added']);
        $this->assertSame('Changed the shift Morning (ends)', $events['attendance.shift_changed']);
        $this->assertSame('Removed the shift Morning', $events['attendance.shift_removed']);
        $this->assertStringStartsWith('Changed the attendance settings: ', $events['attendance.settings_changed']);
        $this->assertStringContainsString('grace period', $events['attendance.settings_changed']);
        $this->assertStringContainsString('working hours', $events['attendance.settings_changed']);
        $this->assertSame('Saved the attendance settings for the first time', AuditTrail::where('event', 'attendance.settings_changed')->orderBy('id')->value('description'));
        $this->assertSame('Added the department Nursing', $events['attendance.department_added']);
        // The name follows the terminal: only the department is someone's change.
        $this->assertSame('Changed attendance for Ama O. (department)', $events['attendance.staff_changed']);
        $this->assertSame('Rostered Ama O. on Wed 21 Oct 2026: Morning', $events['attendance.roster_changed']);
        $this->assertSame('Asked for leave for Ama O. (2 Nov 2026 to 3 Nov 2026, Annual leave)', $events['attendance.leave_requested']);
        $this->assertSame('Approved leave for Ama O. (2 Nov 2026 to 3 Nov 2026, Annual leave)', $events['attendance.leave_approved']);
        $change = AuditTrail::where('event', 'attendance.shift_changed')->sole();
        $this->assertSame([['ends_at' => '14:00'], ['ends_at' => '15:00']], [$change->old_values, $change->new_values]);

        // Bulk work and the terminal's own changes are not noted row by row.
        $before = AuditTrail::count();
        AttendanceAudit::quietly(fn () => AttendanceStaff::create(['employee_no' => '002', 'name' => 'Kofi']));
        auth()->logout();
        AttendanceStaff::create(['employee_no' => '003', 'name' => 'Yaa']);
        $this->assertSame($before, AuditTrail::count());
    }

    public function test_the_audit_trail_has_an_attendance_area(): void
    {
        AttendanceShift::create(['name' => 'Morning', 'starts_at' => '07:00', 'ends_at' => '14:00']);
        AuditTrail::record('optical.order_created', 'An optical order', null, [], [], null, true);
        Livewire::test(AttendanceComponent::class)->assertSee('/admin/audit-trail?area=attendance');
        Livewire::withQueryParams(['area' => 'attendance'])->test(AuditTrailViewerComponent::class)->assertSet('area', 'attendance')
            ->assertSee('Added the shift Morning')->assertDontSee('An optical order')
            ->set('area', 'clinic')->assertDontSee('Added the shift Morning');
    }
}
