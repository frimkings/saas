<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Models\AttendanceDepartment;
use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\AttendanceRoster;
use App\Models\AttendanceShift;
use App\Models\AttendanceStaff;
use App\Models\User;
use App\Services\Attendance\AttendanceAnalyzer;
use App\Support\ClinicNavigation;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * Departments, for hospitals: a branch groups its staff, a head sees and runs only their own
 * department, and a department may have rules of its own. A branch without departments sees none
 * of it. October 2026: the 19th is a Monday; the branch works 8 to 5 with 15 minutes' grace.
 */
class AttendanceDepartmentsTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private User $admin;

    private User $esi;

    private AttendanceStaff $ama;

    private AttendanceStaff $kofi;

    private AttendanceStaff $yaa;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Carbon::setTestNow('2026-10-31 12:00:00');
        config()->set('tenancy.enabled', true);
        $clinic = $this->activeClinic(['name' => 'Hospital A', 'slug' => 'hospital-a']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $this->admin = $this->member($clinic, $branch, 'Super Admin', 'Owner');
        $this->esi = $this->member($clinic, $branch, 'Secretary', 'Esi Head');
        $this->actingAs($this->admin);
        app(TenantContext::class)->set($this->admin, $clinic, $branch, [$branch->id]);

        AttendanceDevice::create(['name' => 'Front desk'])->forceFill(['first_event_at' => '2026-10-01 07:00:00', 'last_event_at' => '2026-10-31 11:00:00', 'last_seen_at' => now()])->save();
        $this->ama = AttendanceStaff::create(['employee_no' => '001', 'name' => 'Ama Owusu', 'is_tracked' => true]);
        $this->kofi = AttendanceStaff::create(['employee_no' => '002', 'name' => 'Kofi Boateng', 'is_tracked' => true, 'department' => 'Laboratory']);
        $this->yaa = AttendanceStaff::create(['employee_no' => '003', 'name' => 'Yaa Mensah', 'is_tracked' => true]);
        foreach ([[$this->ama, '07:10', '15:15'], [$this->kofi, '08:10', '17:00'], [$this->yaa, '08:00', '17:00']] as [$staff, $in, $out]) {
            AttendancePunch::create(['attendance_staff_id' => $staff->id, 'punched_at' => "2026-10-19 {$in}:00", 'source' => 'terminal']);
            AttendancePunch::create(['attendance_staff_id' => $staff->id, 'punched_at' => "2026-10-19 {$out}:00", 'source' => 'terminal']);
        }
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function member($clinic, $branch, string $roleName, string $name): User
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role);
        $clinic->users()->attach($user, ['status' => 'active', 'clinic_role' => $roleName, 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function actAs(User $user): void
    {
        $context = app(TenantContext::class);
        $this->flushSession();
        $this->actingAs($user);
        $context->set($user, $context->clinic(), $context->branch(), [$context->branchId()]);
    }

    private function day(AttendanceStaff $staff): array
    {
        $report = app(AttendanceAnalyzer::class)->range(CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-19'));

        return collect($report['rows'])->first(fn ($row) => $row['staff']->id === $staff->id)['days']['2026-10-19'];
    }

    public function test_a_branch_without_departments_sees_none_of_it_and_a_manager_sets_them_up(): void
    {
        $page = Livewire::test(AttendanceComponent::class)->set('from', '2026-10-19')->set('to', '2026-10-23')
            ->assertDontSee('All departments')->assertDontSeeHtml('wire:key="unit-')->assertSeeHtml('wire:click="openDepartments"');

        $page->call('openDepartments')->assertSee('The terminal already has 1 department name')
            ->set('departmentName', 'Nursing')->set('departmentHeads', [(string) $this->esi->id])->set('departmentMembers', [(string) $this->ama->id])
            ->call('saveDepartment')->assertHasNoErrors()->assertSee('Esi Head')
            ->set('departmentName', 'Nursing')->call('saveDepartment')->assertHasErrors(['departmentName'])
            ->call('departmentsFromTerminal');
        $nursing = AttendanceDepartment::where('name', 'Nursing')->firstOrFail();
        $laboratory = AttendanceDepartment::where('name', 'Laboratory')->firstOrFail();
        $this->assertSame([$this->esi->id], $nursing->heads);
        $this->assertSame([$nursing->id, $laboratory->id, null], [$this->ama->fresh()->attendance_department_id, $this->kofi->fresh()->attendance_department_id, $this->yaa->fresh()->attendance_department_id]);

        // Now the page compares departments and can show one at a time.
        $page->set('showDepartments', false)->set('tab', 'report')->assertSee('All departments')->assertSeeHtml('wire:key="unit-'.$nursing->id.'"')->assertSeeHtml('wire:key="unit-none"')
            ->set('department', (string) $nursing->id)->assertSeeHtml('wire:key="summary-'.$this->ama->id.'"')->assertDontSeeHtml('wire:key="summary-'.$this->kofi->id.'"')
            ->set('department', 'none')->assertSeeHtml('wire:key="summary-'.$this->yaa->id.'"')->assertDontSeeHtml('wire:key="summary-'.$this->ama->id.'"')
            ->set('department', '');

        // A person is moved from their own window; the download follows the department.
        $page->call('openStaff', $this->yaa->id)->set('staffDepartmentId', (string) $laboratory->id)->call('saveStaff')->assertHasNoErrors();
        $this->assertSame($laboratory->id, $this->yaa->fresh()->attendance_department_id);
        $csv = $this->get(route('admin.attendance.export', ['format' => 'csv', 'from' => '2026-10-19', 'to' => '2026-10-23', 'department' => $laboratory->id]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Kofi Boateng', $csv);
        $this->assertStringContainsString('"Yaa Mensah",Laboratory', $csv);
        $this->assertStringNotContainsString('Ama Owusu', $csv);

        // Deleting a department leaves its people followed, in none.
        $page->call('removeDepartment', $laboratory->id);
        $this->assertNull($this->kofi->fresh()->attendance_department_id);
        $this->assertSame(1, AttendanceDepartment::count());
    }

    public function test_a_department_can_have_rules_of_its_own(): void
    {
        // On the branch's rules Ama, in at 7:10 and out at 15:15, is on time but left early.
        $this->assertSame(['on_time', true, 0], [$this->day($this->ama)['status'], $this->day($this->ama)['left_early'], $this->day($this->ama)['overtime_minutes']]);

        Livewire::test(AttendanceComponent::class)->call('openDepartments')->set('departmentName', 'Nursing')->set('departmentMembers', [(string) $this->ama->id])
            ->set('departmentOwnRules', true)->set('departmentGrace', '5')->set('departmentOvertimeAfter', '10')->set('departmentOwnHours', true)
            ->set('departmentHours.1.start', '07:00')->set('departmentHours.1.end', '15:00')->call('saveDepartment')->assertHasNoErrors();
        $nursing = AttendanceDepartment::firstOrFail();
        $this->assertSame([5, null, 10, '07:00'], [$nursing->grace_minutes, $nursing->break_minutes, $nursing->overtime_after_minutes, $nursing->hours[1]['start']]);

        // Nursing works 7 to 3 with 5 minutes' grace: 7:10 is late, and 15 minutes past the end is overtime.
        $ama = $this->day($this->ama);
        $this->assertSame(['late', 10, false, 15], [$ama['status'], $ama['late_minutes'], $ama['left_early'], $ama['overtime_minutes']]);
        // Kofi is in no department: 8:10 is within the branch's 15 minutes.
        $this->assertSame('on_time', $this->day($this->kofi)['status']);
    }

    public function test_each_head_gets_their_own_morning_email_and_the_owner_a_comparison(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $nursing = AttendanceDepartment::create(['name' => 'Nursing', 'heads' => [$this->esi->id]]);
        $laboratory = AttendanceDepartment::create(['name' => 'Laboratory']);
        $this->ama->update(['attendance_department_id' => $nursing->id]);
        $this->kofi->update(['attendance_department_id' => $laboratory->id]);
        $clinic = app(TenantContext::class)->clinic();
        $summaries = app(\App\Services\OwnerSummaryService::class);

        // Before 10 AM nothing goes; after it, Esi hears about Monday the 19th in Nursing only.
        $this->assertSame(0, $summaries->sendToDepartmentHeads($clinic, \Illuminate\Support\Carbon::parse('2026-10-20 08:00:00')));
        $this->assertSame(1, $summaries->sendToDepartmentHeads($clinic, \Illuminate\Support\Carbon::parse('2026-10-20 10:30:00')));
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OwnerSummaryMail::class, function ($mail) {
            $html = $mail->render();

            return $mail->hasTo($this->esi->email) && str_contains($html, 'Department attendance') && str_contains($html, 'Nursing') && str_contains($html, 'Ama Owusu')
                && str_contains($html, 'head of department') && ! str_contains($html, 'Kofi Boateng') && ! str_contains($html, 'Sales');
        });
        // Asked again the same day, it is not sent twice.
        $summaries->sendToDepartmentHeads($clinic, \Illuminate\Support\Carbon::parse('2026-10-20 11:30:00'));
        \Illuminate\Support\Facades\Mail::assertSentCount(1);
        $this->assertSame(1, \App\Models\OwnerEmail::where('kind', 'department_summary')->count());

        // The owner's own summary compares the departments.
        $day = \Illuminate\Support\Carbon::parse('2026-10-19');
        $html = (new \App\Mail\OwnerSummaryMail($summaries->build($clinic, 'daily', $day, $day->copy())))->render();
        foreach (['Department', 'Nursing', 'Laboratory', 'No department'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_a_head_sees_and_runs_only_their_own_department(): void
    {
        $nursing = AttendanceDepartment::create(['name' => 'Nursing', 'heads' => [$this->esi->id]]);
        AttendanceDepartment::create(['name' => 'Laboratory']);
        $this->ama->update(['attendance_department_id' => $nursing->id, 'pay_type' => 'hourly', 'pay_rate' => 10]);
        $morning = AttendanceShift::create(['name' => 'Morning', 'starts_at' => '07:00', 'ends_at' => '14:00']);

        $this->actAs($this->esi);
        $this->assertContains('Department attendance', array_column(ClinicNavigation::menu('reception'), 'label'));
        $this->assertContains('Roster', array_column(ClinicNavigation::menu('reception'), 'label'));
        $this->get(route('admin.attendance.roster-page'))->assertOk()->assertSee('Nursing');
        $this->get(route('admin.attendance'))->assertOk()->assertSee('Your department: Nursing');

        $page = Livewire::test(AttendanceComponent::class)->set('from', '2026-10-19')->set('to', '2026-10-23')->set('tab', 'report')
            ->assertSeeHtml('wire:key="summary-'.$this->ama->id.'"')->assertDontSee('Kofi Boateng')->assertDontSee('Yaa Mensah')
            // No setting up, no payroll, no comparison of departments.
            ->assertDontSee('Set up')->assertDontSeeHtml('wire:click="openPayroll"')->assertDontSeeHtml('wire:key="unit-');

        // Their own person: roster, corrections and overtime.
        Livewire::test(\App\Livewire\Admin\AttendanceRosterComponent::class)->call('setRoster', $this->ama->id, '2026-10-20', (string) $morning->id);
        $page->call('openPunches', $this->ama->id, '2026-10-19')->assertSet('showPunches', true)->call('closePunches')
            ->call('openStaff', $this->ama->id)->assertSet('showStaff', true)->assertDontSee("Follow this person's attendance")->assertSee('Leave')
            ->set('leaveFrom', '2026-11-02')->call('addLeave')->assertHasNoErrors()
            ->call('decideOvertime', $this->ama->id, '2026-10-20', true)->assertStatus(404);
        $this->assertSame($morning->id, AttendanceRoster::firstOrFail()->attendance_shift_id);

        // Anyone else's, and anything that sets up the branch, is refused.
        foreach ([['openStaff', $this->kofi->id], ['openPunches', $this->kofi->id, '2026-10-19'],
            ['decideOvertime', $this->kofi->id, '2026-10-19', true], ['editHours'], ['openDepartments'], ['saveStaff'], ['openPayroll'], ['openLeaveTypes'], ['openPhone']] as $call) {
            Livewire::test(AttendanceComponent::class)->call(...$call)->assertForbidden();
        }
        Livewire::test(\App\Livewire\Admin\AttendanceRosterComponent::class)->call('setRoster', $this->kofi->id, '2026-10-20', '0')->assertForbidden();
        // Being pointed at someone else's window shows nothing.
        Livewire::test(AttendanceComponent::class)->set('staffId', $this->kofi->id)->set('showStaff', true)->assertSet('showStaff', false);

        // The download is their department's; payroll is not theirs to have.
        $csv = $this->get(route('admin.attendance.export', ['format' => 'csv', 'from' => '2026-10-19', 'to' => '2026-10-23']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Ama Owusu', $csv);
        $this->assertStringNotContainsString('Kofi Boateng', $csv);
        $this->get(route('admin.attendance.export', ['format' => 'payroll', 'from' => '2026-10-19', 'to' => '2026-10-23']))->assertForbidden();

        // Someone who heads nothing has no way in.
        $nursing->update(['heads' => null]);
        $this->assertNotContains('Department attendance', array_column(ClinicNavigation::menu('reception'), 'label'));
        $this->get(route('admin.attendance'))->assertForbidden();
        $this->get(route('admin.attendance.export', ['format' => 'csv', 'from' => '2026-10-19', 'to' => '2026-10-23']))->assertForbidden();
    }
}
