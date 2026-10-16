<?php

namespace Tests\Feature;

use App\Models\AttendanceDepartment;
use App\Models\AttendanceHoliday;
use App\Models\AttendanceLeave;
use App\Models\AttendanceLeaveType;
use App\Models\AttendancePattern;
use App\Models\AttendancePunch;
use App\Models\AttendanceRoster;
use App\Models\AttendanceSetting;
use App\Models\AttendanceStaff;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** attendance:demo fills a clinic with a made-up hospital that uses every attendance feature, and --clear takes it out again. */
class AttendanceDemoTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    public function test_the_demo_hospital_uses_every_feature_and_clears_away(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-16 13:00:00');
        config()->set('tenancy.enabled', true);
        $clinic = $this->activeClinic(['name' => 'Demo Hospital', 'slug' => 'demo-hospital']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $owner = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $owner->assignRole($role);
        $clinic->users()->attach($owner, ['status' => 'active', 'clinic_role' => 'Super Admin', 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $branch->id, 'user_id' => $owner->id, 'status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $owner->id, 'role_id' => $role->id]);

        $this->artisan('attendance:demo', ['clinic' => (string) $clinic->id, '--staff' => 60, '--days' => 33])->assertSuccessful();

        app(TenantContext::class)->set($owner, $clinic, $branch, [$branch->id]);
        $this->assertSame(60, AttendanceStaff::query()->where('employee_no', 'like', 'DM%')->count());
        // Heads with accounts, linked staff, phone punches, corrections, swaps, patterns, holidays, leave of each kind.
        $nursing = AttendanceDepartment::query()->where('name', 'Nursing')->firstOrFail();
        $head = User::query()->findOrFail($nursing->heads[0]);
        $this->assertStringEndsWith('@attendance-demo.test', $head->email);
        $this->assertSame($nursing->id, AttendanceStaff::query()->where('user_id', $head->id)->value('attendance_department_id'));
        $this->assertGreaterThan(0, AttendancePunch::query()->where('source', 'phone')->whereNotNull('latitude')->count());
        $this->assertGreaterThan(0, AttendancePunch::query()->where('source', 'manual')->whereNotNull('reason')->count());
        $this->assertGreaterThan(0, AttendanceRoster::query()->where('note', 'like', 'Swapped with %')->count());
        $this->assertSame(2, AttendancePattern::query()->where('name', 'like', 'Demo · %')->count());
        $this->assertTrue(AttendanceHoliday::query()->whereDate('date', '2026-09-21')->exists());
        $this->assertSame(60.0, AttendanceLeaveType::query()->where('code', 'maternity')->value('days_per_year'));
        foreach ([AttendanceLeave::PENDING, AttendanceLeave::APPROVED, AttendanceLeave::REJECTED] as $status) {
            $this->assertTrue(AttendanceLeave::query()->where('status', $status)->exists(), "Some leave is {$status}.");
        }
        $this->assertTrue(AttendanceLeave::query()->whereNotNull('from_time')->exists(), 'Part-day leave.');
        $this->assertTrue((bool) AttendanceSetting::current()->phone_clock_in);
        // No punches on the national holiday for most office staff.
        $office = AttendanceStaff::query()->where('department', 'Administration')->pluck('id');
        $this->assertLessThan($office->count(), AttendancePunch::query()->whereIn('attendance_staff_id', $office)->whereDate('punched_at', '2026-09-21')->distinct()->count('attendance_staff_id'));

        $this->artisan('attendance:demo', ['clinic' => (string) $clinic->id, '--clear' => true])->assertSuccessful();
        app(TenantContext::class)->set($owner, $clinic, $branch, [$branch->id]);
        $this->assertSame(0, AttendanceStaff::query()->where('employee_no', 'like', 'DM%')->count());
        $this->assertSame(0, User::query()->where('email', 'like', '%@attendance-demo.test')->count());
        $this->assertSame(0, AttendancePattern::query()->where('name', 'like', 'Demo · %')->count());
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        \Illuminate\Support\Carbon::setTestNow();
    }
}
