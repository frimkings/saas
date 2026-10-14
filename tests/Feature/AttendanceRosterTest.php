<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Livewire\UserProfileComponent;
use App\Models\AttendanceDepartment;
use App\Models\AttendanceDevice;
use App\Models\AttendanceRoster;
use App\Models\AttendanceShift;
use App\Models\AttendanceStaff;
use App\Models\User;
use App\Support\ShiftCodes;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * The roster, one department at a time: shift codes in a compact grid, a week or a month, filled
 * in Excel and brought back (today onward only), printed, and each person's own shifts.
 * October 2026: the 19th is a Monday, and it is that Monday morning.
 */
class AttendanceRosterTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private User $admin;

    private User $head;

    private AttendanceDepartment $nursing;

    private AttendanceDepartment $lab;

    private AttendanceShift $morning;

    private AttendanceShift $night;

    private AttendanceStaff $ama;

    private AttendanceStaff $kofi;

    private AttendanceStaff $yaa;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Carbon::setTestNow('2026-10-19 09:00:00');
        config()->set('tenancy.enabled', true);
        // The clock is set days ahead: uploads would look old and be cleared away before they are read.
        config()->set('livewire.temporary_file_upload.cleanup', false);
        $clinic = $this->activeClinic(['name' => 'Hospital A', 'slug' => 'hospital-a']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        foreach (['admin' => 'Super Admin', 'head' => 'Secretary'] as $property => $roleName) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $user = User::factory()->create(['name' => ucfirst($property)]);
            $user->assignRole($role);
            $clinic->users()->attach($user, ['status' => 'active', 'clinic_role' => $roleName, 'is_default' => true]);
            DB::table('branch_user')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'status' => 'active', 'is_default' => true]);
            DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $role->id]);
            $this->{$property} = $user;
        }
        $this->actingAs($this->admin);
        app(TenantContext::class)->set($this->admin, $clinic, $branch, [$branch->id]);

        AttendanceDevice::create(['name' => 'Ward door'])->forceFill(['first_event_at' => '2026-10-01 07:00:00', 'last_event_at' => '2026-10-19 08:00:00', 'last_seen_at' => now()])->save();
        $this->nursing = AttendanceDepartment::create(['name' => 'Nursing', 'heads' => [$this->head->id]]);
        $this->lab = AttendanceDepartment::create(['name' => 'Laboratory']);
        $this->morning = AttendanceShift::create(['name' => 'Morning', 'starts_at' => '07:00', 'ends_at' => '14:00']);
        $this->night = AttendanceShift::create(['name' => 'Night', 'starts_at' => '20:00', 'ends_at' => '06:00']);
        $this->ama = AttendanceStaff::create(['employee_no' => '001', 'name' => 'Ama Owusu', 'is_tracked' => true, 'attendance_department_id' => $this->nursing->id,
            'shift_week' => array_fill_keys([1, 2, 3, 4, 5], $this->morning->id) + [6 => 0, 7 => 0], 'user_id' => $this->admin->id]);
        $this->kofi = AttendanceStaff::create(['employee_no' => '002', 'name' => 'Kofi Boateng', 'is_tracked' => true, 'attendance_department_id' => $this->nursing->id]);
        $this->yaa = AttendanceStaff::create(['employee_no' => '003', 'name' => 'Yaa Mensah', 'is_tracked' => true, 'attendance_department_id' => $this->lab->id]);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_shift_codes(): void
    {
        $codes = ShiftCodes::for(AttendanceShift::query()->get());
        $this->assertSame(['M', 'N'], array_column($codes, 'code'));
        $this->assertSame([$this->morning->id, 0, 'usual', null, false], [ShiftCodes::read('m', $codes), ShiftCodes::read('off', $codes), ShiftCodes::read('Usual', $codes),
            ShiftCodes::read(' ', $codes), ShiftCodes::read('Q', $codes)]);
        $this->assertSame($this->night->id, ShiftCodes::read('night', $codes));
    }

    public function test_the_roster_is_one_department_at_a_time_in_codes_by_week_or_month(): void
    {
        $page = Livewire::test(AttendanceComponent::class)->set('from', '2026-10-19')->set('to', '2026-10-25')->call('openRoster')
            // No department filter: the first department, in name order.
            ->assertSet('rosterUnit', (string) $this->lab->id)->assertSee('Yaa Mensah')->assertDontSee('Ama Owusu')
            ->set('rosterUnit', (string) $this->nursing->id)->assertSee('Ama Owusu')->assertDontSee('Yaa Mensah')
            ->assertSee('Week of 19 Oct to 25 Oct 2026')->assertSeeHtml('Ama Owusu, Mon 19 Oct: Morning 7:00 AM to 2:00 PM');
        // Changing a box from its menu: Kofi on nights on Tuesday.
        $page->call('setRoster', $this->kofi->id, '2026-10-20', (string) $this->night->id)->assertSeeHtml('Kofi Boateng, Tue 20 Oct: Night 8:00 PM to 6:00 AM +1');
        // A month.
        $page->set('rosterSpan', 'month')->assertSee('October 2026')->call('shiftRosterWeek', 1)->assertSee('November 2026')->assertSet('rosterWeek', '2026-11-01');

        // A head rosters their own department only.
        $this->flushSession();
        $this->actingAs($this->head);
        $context = app(TenantContext::class);
        $context->set($this->head, $context->clinic(), $context->branch(), [$context->branchId()]);
        Livewire::test(AttendanceComponent::class)->call('openRoster')->assertSet('rosterUnit', (string) $this->nursing->id)->assertSee('Ama Owusu')
            ->set('rosterUnit', (string) $this->lab->id)->assertForbidden();
    }

    public function test_a_roster_is_filled_in_excel_and_brought_back(): void
    {
        $query = ['department' => $this->nursing->id, 'from' => '2026-10-18', 'to' => '2026-10-24'];
        // Kofi on nights on Tuesday and Wednesday, Ama off on Thursday, a change to yesterday, someone from another
        // department and a code that isn't a shift.
        $filled = "Employee ID,Name,2026-10-18 Sun,2026-10-20 Tue,2026-10-21 Wed,2026-10-22 Thu\n"
            ."001,Ama Owusu,,M,M,OFF\n"
            ."002,Kofi Boateng,N,N,night,Q\n"
            ."003,Yaa Mensah,,N,,\n";
        $page = Livewire::test(AttendanceComponent::class)->call('openRoster')->set('rosterUnit', (string) $this->nursing->id)->set('showRosterUpload', true)
            ->assertSee('Download this week for Nursing')
            ->set('rosterFile', UploadedFile::fake()->createWithContent('roster.csv', $filled))->call('readRosterFile')->assertHasNoErrors();
        $page
            ->assertSeeText('3 days change for 2 people')->assertSee('"Q" is not a shift')->assertSee('1 change is for days before today')
            ->assertSee('Yaa Mensah is not in this department');
        $this->assertSame(0, AttendanceRoster::count());
        $page->call('applyRosterFile')->assertSet('rosterPreview', null);
        $on = fn (AttendanceStaff $staff, string $date) => AttendanceRoster::where('attendance_staff_id', $staff->id)->whereDate('date', $date)->first()?->attendance_shift_id;
        $this->assertSame([$this->night->id, $this->night->id, null], [$on($this->kofi, '2026-10-20'), $on($this->kofi, '2026-10-21'), $on($this->ama, '2026-10-22')]);
        $this->assertSame(1, AttendanceRoster::where('attendance_staff_id', $this->ama->id)->count());
        $this->assertSame(0, AttendanceRoster::whereDate('date', '2026-10-18')->count());
        $this->assertSame(1, \App\Models\AuditTrail::where('event', 'attendance.roster_uploaded')->count());

        // The file to fill in: what each person is down for now, in codes.
        $csv = $this->get(route('admin.attendance.roster', ['format' => 'csv'] + $query))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Employee ID",Name,"2026-10-18 Sun","2026-10-19 Mon"', $csv);
        $this->assertStringContainsString('"=""001""","Ama Owusu",OFF,M,M,M,OFF,M,OFF', $csv);
        $this->assertStringNotContainsString('Yaa Mensah', $csv);
        $this->assertStringContainsString('M = Morning, N = Night', $csv);

        // USUAL takes a day back to the person's usual days.
        Livewire::test(AttendanceComponent::class)->call('openRoster')->set('rosterUnit', (string) $this->nursing->id)->set('showRosterUpload', true)
            ->set('rosterFile', UploadedFile::fake()->createWithContent('roster.csv', "Employee ID,Name,2026-10-22 Thu\n001,Ama Owusu,usual\n"))->call('readRosterFile')
            ->assertSeeText('1 day changes for 1 person')->call('applyRosterFile');
        $this->assertSame(0, AttendanceRoster::where('attendance_staff_id', $this->ama->id)->count());
    }

    public function test_the_roster_prints_and_staff_see_their_own_shifts(): void
    {
        $response = $this->get(route('admin.attendance.roster', ['format' => 'pdf', 'department' => $this->nursing->id, 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        AttendanceRoster::create(['attendance_staff_id' => $this->ama->id, 'date' => '2026-10-21', 'attendance_shift_id' => $this->night->id, 'note' => 'Swapped with Kofi Boateng']);
        Livewire::test(UserProfileComponent::class)->set('activeTab', 'attendance')->assertSee('My shifts, the next two weeks')->assertSee('Today')
            ->assertSeeHtml('title="Swapped with Kofi Boateng"')->assertSee('Swapped');
    }
}
