<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Models\AttendanceDevice;
use App\Models\AttendanceLeave;
use App\Models\AttendanceLeaveType;
use App\Models\AttendanceOvertime;
use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\AttendanceStaff;
use App\Models\User;
use App\Services\Attendance\AttendancePayroll;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * Pay from attendance. October 2026 has 22 working days (Monday to Friday, 8 to 5), so on a
 * salary of 2,200 a day is worth 100 and a minute 2200 / (22 x 540).
 */
class AttendancePayrollTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private AttendanceStaff $ama;

    private AttendanceStaff $kofi;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Carbon::setTestNow('2026-11-02 12:00:00');
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

        AttendanceDevice::create(['name' => 'Front desk'])->forceFill(['first_event_at' => '2026-10-01 07:00:00', 'last_event_at' => '2026-11-02 11:00:00', 'last_seen_at' => now()])->save();
        $this->ama = AttendanceStaff::create(['employee_no' => '001', 'name' => 'Ama Owusu', 'is_tracked' => true, 'pay_type' => 'monthly', 'pay_rate' => 2200]);
        $this->kofi = AttendanceStaff::create(['employee_no' => '002', 'name' => 'Kofi Boateng', 'is_tracked' => true, 'pay_type' => 'hourly', 'pay_rate' => 20]);
        AttendanceStaff::create(['employee_no' => '003', 'name' => 'Yaa Mensah', 'is_tracked' => true]);

        // Ama: every working day 8 to 5, except the week of the 19th.
        $special = ['2026-10-19' => null, '2026-10-20' => ['08:54', '17:00'], '2026-10-21' => ['08:00', '19:00'], '2026-10-22' => null, '2026-10-23' => null];
        for ($day = CarbonImmutable::parse('2026-10-01'); $day->month === 10; $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            if ($day->isWeekend() || (array_key_exists($key, $special) && ! $special[$key])) {
                continue;
            }
            foreach ($special[$key] ?? ['08:00', '17:00'] as $time) {
                AttendancePunch::create(['attendance_staff_id' => $this->ama->id, 'punched_at' => "{$key} {$time}:00", 'source' => 'terminal']);
            }
        }
        AttendanceLeaveType::ensureSaved();
        AttendanceLeaveType::create(['code' => 'unpaid', 'name' => 'Unpaid leave', 'is_paid' => false]);
        AttendanceLeave::create(['attendance_staff_id' => $this->ama->id, 'starts_on' => '2026-10-22', 'ends_on' => '2026-10-22', 'type' => 'unpaid']);
        AttendanceLeave::create(['attendance_staff_id' => $this->ama->id, 'starts_on' => '2026-10-23', 'ends_on' => '2026-10-23', 'type' => 'annual']);
        AttendanceOvertime::create(['attendance_staff_id' => $this->ama->id, 'date' => '2026-10-21', 'status' => 'approved', 'minutes' => 60]);

        // Kofi, by the hour: nine hours one day, ten the next.
        foreach (['2026-10-05 08:00:00', '2026-10-05 17:00:00', '2026-10-06 08:00:00', '2026-10-06 18:00:00'] as $at) {
            AttendancePunch::create(['attendance_staff_id' => $this->kofi->id, 'punched_at' => $at, 'source' => 'terminal']);
        }
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function line(AttendanceStaff $staff): array
    {
        $payroll = app(AttendancePayroll::class)->for(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        return collect($payroll['rows'])->first(fn ($line) => $line['staff']->id === $staff->id);
    }

    public function test_a_salary_loses_absences_and_unpaid_leave_and_gains_approved_overtime(): void
    {
        $ama = $this->line($this->ama);
        $this->assertSame([19, 1, 1.0, 1.0], [$ama['present'], $ama['absent'], $ama['paid_leave'], $ama['unpaid_leave']]);
        // Basic 2,200; a day absent and a day unpaid at 100 each; an hour of overtime at 1.5 x 11.11.
        $this->assertSame([2200.0, 100.0, 100.0, 0.0, 16.67, 2016.67], [$ama['basic'], $ama['absence'], $ama['unpaid'], $ama['lateness'], $ama['overtime_pay'], $ama['gross']]);

        // The branch's switches: lateness comes off by the minute (54 minutes = 10.00), absences no longer do.
        AttendanceSetting::current()->fill(['deduct_lateness' => true, 'deduct_absence' => false, 'overtime_multiplier' => 2])->save();
        $ama = $this->line($this->ama);
        $this->assertSame([0.0, 10.0, 22.22, 2112.22], [$ama['absence'], $ama['lateness'], $ama['overtime_pay'], $ama['gross']]);

        // Half a month is half the salary.
        $half = collect(app(AttendancePayroll::class)->for(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-15'))['rows'])->first(fn ($line) => $line['staff']->id === $this->ama->id);
        $this->assertSame(1100.0, $half['basic']);
    }

    public function test_hourly_staff_are_paid_hours_to_the_end_of_the_day_and_approved_overtime(): void
    {
        $kofi = $this->line($this->kofi);
        // Two nine-hour days; the tenth hour waits for approval and is not paid yet. Days not worked cost nothing.
        $this->assertSame([1080, 360.0, 0.0, 0.0, 360.0, 60], [$kofi['regular_minutes'], $kofi['basic'], $kofi['overtime_pay'], $kofi['absence'], $kofi['gross'], $kofi['overtime_pending']]);

        AttendanceOvertime::create(['attendance_staff_id' => $this->kofi->id, 'date' => '2026-10-06', 'status' => 'approved', 'minutes' => 60]);
        $kofi = $this->line($this->kofi);
        $this->assertSame([30.0, 390.0], [$kofi['overtime_pay'], $kofi['gross']]);

        $this->assertNull($this->line(AttendanceStaff::where('employee_no', '003')->firstOrFail())['gross']);
    }

    public function test_the_owner_sets_pay_and_downloads_the_file_and_a_manager_cannot(): void
    {
        $yaa = AttendanceStaff::where('employee_no', '003')->firstOrFail();
        Livewire::test(AttendanceComponent::class)->set('from', '2026-10-01')->set('to', '2026-10-31')->assertSee('Payroll')
            ->call('openPayroll')->assertSet('pay.'.$this->ama->id.'.rate', '2200')->assertSee('2,016.67')->assertSee('Set how they are paid')
            ->assertSee('still waiting for an answer')
            ->set('pay.'.$yaa->id.'.type', 'monthly')->call('savePayroll')->assertHasErrors(['pay.'.$yaa->id.'.rate'])
            ->set('pay.'.$yaa->id.'.rate', '1100')->set('deductLateness', true)->set('overtimeMultiplier', '2')->call('savePayroll')->assertHasNoErrors()
            ->assertSee('2,012.22');
        $this->assertSame(['monthly', '1100.00'], [$yaa->fresh()->pay_type, $yaa->fresh()->pay_rate]);
        $this->assertSame([2.0, true], [AttendanceSetting::current()->overtime_multiplier, AttendanceSetting::current()->deduct_lateness]);

        $csv = $this->get(route('admin.attendance.export', ['format' => 'payroll', 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->streamedContent();
        $this->assertStringContainsString('"=""001""","Ama Owusu",,"Monthly salary",2200.00,', $csv);
        $this->assertStringContainsString('2200.00,22.22,100.00,100.00,10.00,2012.22', $csv);
        $this->assertStringContainsString('"Kofi Boateng",,"By the hour",20.00', $csv);
        $this->assertStringContainsString('Not final: 1h of overtime is still waiting', $csv);

        // A manager runs attendance but does not see pay.
        $manager = User::factory()->create();
        $manager->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $context = app(TenantContext::class);
        $context->clinic()->users()->attach($manager, ['status' => 'active', 'clinic_role' => 'Manager', 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $context->branchId(), 'user_id' => $manager->id, 'status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $context->branchId(), 'user_id' => $manager->id, 'role_id' => Role::where('name', 'Manager')->value('id')]);
        $this->flushSession();
        $this->actingAs($manager);
        $context->set($manager, $context->clinic(), $context->branch(), [$context->branchId()]);
        $this->get(route('admin.attendance.export', ['format' => 'payroll', 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertForbidden();
        $this->get(route('admin.attendance.export', ['format' => 'csv', 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk();
        Livewire::test(AttendanceComponent::class)->assertDontSee('Payroll')->call('openPayroll')->assertForbidden();
    }
}
