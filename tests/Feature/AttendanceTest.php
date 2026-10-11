<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Models\AttendanceHoliday;
use App\Models\AttendanceImport;
use App\Models\AttendanceLeave;
use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\AttendanceStaff;
use App\Models\Clinic;
use App\Models\User;
use App\Services\Attendance\AttendanceAnalyzer;
use App\Services\Attendance\AttendanceImportService;
use App\Services\Attendance\AttendanceRecordParser;
use App\Services\Attendance\XlsReader;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * The terminal's monthly report is read, stored per branch and turned into late and absent days.
 * The fixture is October 2026, made on the 21st at 18:30: Ama (001), Kofi (002), Esi (004, no punches).
 */
class AttendanceTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private User $admin;
    private Clinic $clinic;
    private int $branchId;
    private int $otherBranchId;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $this->clinic = $this->activeClinic(['name' => 'Clinic A', 'slug' => 'clinic-a']);
        $this->branchId = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true])->id;
        $this->otherBranchId = $this->clinic->branches()->create(['code' => 'EAST', 'name' => 'East'])->id;

        $this->admin = User::factory()->create();
        $this->admin->assignRole($role);
        $this->clinic->users()->attach($this->admin, ['status' => 'active', 'clinic_role' => 'Super Admin', 'is_default' => true]);
        foreach ([$this->branchId, $this->otherBranchId] as $branchId) {
            DB::table('branch_user')->insert(['branch_id' => $branchId, 'user_id' => $this->admin->id, 'status' => 'active', 'is_default' => $branchId === $this->branchId]);
            DB::table('branch_user_role')->insert(['branch_id' => $branchId, 'user_id' => $this->admin->id, 'role_id' => $role->id]);
        }

        $this->actingAs($this->admin);
        $this->useBranch($this->branchId);
    }

    private function useBranch(int $branchId): void
    {
        app(TenantContext::class)->set($this->admin, $this->clinic, $this->clinic->branches()->findOrFail($branchId), [$this->branchId, $this->otherBranchId]);
    }

    private function fixture(string $name = 'attendance-record.xls'): string
    {
        return base_path('tests/Fixtures/'.$name);
    }

    private function importFixture(): AttendanceImport
    {
        return app(AttendanceImportService::class)->import(app(AttendanceRecordParser::class)->parse($this->fixture()), 'attendance-record.xls');
    }

    private function october(): array
    {
        return app(AttendanceAnalyzer::class)->month(CarbonImmutable::create(2026, 10, 1));
    }

    private function row(array $report, string $employeeNo): array
    {
        return collect($report['rows'])->first(fn ($row) => $row['staff']->employee_no === $employeeNo);
    }

    public function test_the_reader_returns_the_cells_of_an_xls_file(): void
    {
        $rows = (new XlsReader())->read($this->fixture());

        $this->assertSame('Attendance Record', $rows[0][0]);
        $this->assertSame('Made Date:2026/10/01-2026/10/31', $rows[3][0]);
        $this->assertSame(['Employee ID', 'Name', 'Department', '1'], array_slice($rows[4], 0, 4));
        $this->assertSame('Kofi Añané', $rows[7][1]);
        $this->assertSame("07:55\n17:05\n", $rows[6][3]);
    }

    public function test_the_reader_follows_text_that_runs_over_several_records(): void
    {
        $rows = (new XlsReader())->read($this->fixture('attendance-record-large.xls'));

        $this->assertSame('filler 00000 '.str_repeat('x', 40).' é€ß', $rows[9][1]);
        $this->assertSame('filler 00599 '.str_repeat('x', 40), $rows[608][1]);
    }

    public function test_the_reader_refuses_a_file_that_is_not_xls(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'att_');
        file_put_contents($path, 'Employee ID,Name');

        try {
            $this->expectException(ValidationException::class);
            app(AttendanceRecordParser::class)->parse($path);
        } finally {
            unlink($path);
        }
    }

    public function test_the_report_is_parsed_into_staff_and_dated_punches(): void
    {
        $report = app(AttendanceRecordParser::class)->parse($this->fixture());

        $this->assertSame('2026-10-01', $report['period_start']->format('Y-m-d'));
        $this->assertSame('2026-10-31', $report['period_end']->format('Y-m-d'));
        $this->assertSame('2026-10-21 18:30:00', $report['exported_at']->format('Y-m-d H:i:s'));
        $this->assertSame(['001', '002', '004'], array_column($report['staff'], 'employee_no'));
        $this->assertSame('RECEPTION', $report['staff'][0]['department']);
        $this->assertSame(['2026-10-01 07:55:00', '2026-10-01 17:05:00', '2026-10-02 08:20:00'], array_slice($report['staff'][0]['punches'], 0, 3));
        $this->assertSame([], $report['staff'][2]['punches']);
    }

    public function test_uploading_the_same_report_again_adds_nothing(): void
    {
        $first = $this->importFixture();
        $second = $this->importFixture();

        $this->assertSame(3, $first->staff_count);
        $this->assertSame(45, $first->new_punches);
        $this->assertSame(0, $second->new_punches);
        $this->assertSame(3, AttendanceStaff::count());
        $this->assertSame(45, AttendancePunch::count());
    }

    public function test_days_are_judged_against_the_working_hours(): void
    {
        $this->importFixture();
        $ama = $this->row($this->october(), '001')['days'];

        $this->assertSame('on_time', $ama['2026-10-01']['status']);
        $this->assertSame(['07:55', '17:05'], [$ama['2026-10-01']['in'], $ama['2026-10-01']['out']]);

        // 08:20 is past the 15 minutes' grace; 08:15 would not be.
        $this->assertSame('late', $ama['2026-10-02']['status']);
        $this->assertSame(20, $ama['2026-10-02']['late_minutes']);

        $this->assertSame('off', $ama['2026-10-03']['status']); // Saturday
        $this->assertTrue($ama['2026-10-05']['left_early']);
        $this->assertSame(50, $ama['2026-10-05']['early_minutes']);
        $this->assertTrue($ama['2026-10-06']['no_clock_out']);

        // Four punches: the first is arrival, the last departure.
        $this->assertSame(['07:50', '17:02'], [$ama['2026-10-07']['in'], $ama['2026-10-07']['out']]);
        $this->assertSame(552, $ama['2026-10-07']['minutes_worked']);

        $kofi = $this->row($this->october(), '002')['days'];
        $this->assertSame('absent', $kofi['2026-10-02']['status']);
        $this->assertSame('worked_off', $kofi['2026-10-03']['status']);
        $this->assertSame('on_time', $kofi['2026-10-06']['status']); // 08:15, the last minute of grace
    }

    public function test_days_the_report_does_not_cover_are_not_absences(): void
    {
        $this->importFixture();
        $report = $this->october();
        $ama = $this->row($report, '001')['days'];
        $esi = $this->row($report, '004');

        $this->assertSame('2026-10-21', $report['covered_until']);
        // The day the report was made is still in progress: no clock-out or absence yet.
        $this->assertSame('on_time', $ama['2026-10-21']['status']);
        $this->assertFalse($ama['2026-10-21']['no_clock_out']);
        $this->assertSame('none', $esi['days']['2026-10-21']['status']);
        $this->assertSame('none', $esi['days']['2026-10-22']['status']);
        $this->assertSame('absent', $esi['days']['2026-10-20']['status']);
        // 14 working days from the 1st to the 20th.
        $this->assertSame(14, $esi['totals']['absent']);

        $september = app(AttendanceAnalyzer::class)->month(CarbonImmutable::create(2026, 9, 1));
        $this->assertNull($september['covered_until']);
        $this->assertSame(0, $september['totals']['absent']);
    }

    public function test_monthly_totals_count_late_and_absent_days(): void
    {
        $this->importFixture();
        $report = $this->october();
        $ama = $this->row($report, '001')['totals'];
        $kofi = $this->row($report, '002')['totals'];

        $this->assertSame([15, 2, 0, 36], [$ama['present'], $ama['late'], $ama['absent'], $ama['late_minutes']]);
        $this->assertSame(1, $ama['left_early']);
        $this->assertSame(1, $ama['no_clock_out']);
        $this->assertSame(1, $kofi['worked_off']);
        $this->assertSame(8, $kofi['absent']);
        $this->assertSame([3, 4, 22], [$report['totals']['staff'], $report['totals']['late'], $report['totals']['absent']]);
    }

    public function test_someone_not_followed_or_not_yet_started_is_left_out_of_absences(): void
    {
        $this->importFixture();
        AttendanceStaff::where('employee_no', '004')->update(['is_tracked' => false]);
        AttendanceStaff::where('employee_no', '002')->update(['starts_on' => '2026-10-05']);

        $report = $this->october();

        $this->assertSame('none', $this->row($report, '002')['days']['2026-10-02']['status']);
        $this->assertSame(7, $this->row($report, '002')['totals']['absent']);
        $this->assertSame(2, $report['totals']['staff']);
        $this->assertSame(7, $report['totals']['absent']);
    }

    public function test_working_hours_can_be_changed(): void
    {
        $this->importFixture();

        Livewire::test(AttendanceComponent::class)
            ->call('editHours')
            ->assertSet('hours.1.start', '08:00')
            ->assertSet('hours.6.on', false)
            ->set('hours.1.start', '09:00')
            ->set('hours.6.on', true)
            ->set('hours.6.start', '09:00')
            ->set('hours.6.end', '13:00')
            ->set('graceMinutes', 0)
            ->call('saveHours')
            ->assertHasNoErrors()
            ->assertSet('showHours', false);

        $settings = AttendanceSetting::current();
        $this->assertEquals(['start' => '09:00', 'end' => '13:00'], $settings->hoursOn(6));
        $this->assertSame(0, $settings->grace_minutes);
        // Thursday 1st is now judged against 09:00; Saturday 3rd has become a working day.
        $this->assertSame('on_time', $this->row($this->october(), '001')['days']['2026-10-01']['status']);
        $this->assertSame('absent', $this->row($this->october(), '001')['days']['2026-10-03']['status']);
        $this->assertSame('late', $this->row($this->october(), '002')['days']['2026-10-03']['status']);
    }

    public function test_a_closing_time_before_the_start_is_refused(): void
    {
        Livewire::test(AttendanceComponent::class)
            ->call('editHours')
            ->set('hours.1.end', '07:00')
            ->call('saveHours')
            ->assertHasErrors('hours.1.end');

        $this->assertSame(0, AttendanceSetting::count());
    }

    public function test_a_report_is_uploaded_from_the_page(): void
    {
        $file = UploadedFile::fake()->createWithContent('AttendanceRecord.xls', file_get_contents($this->fixture()));

        Livewire::test(AttendanceComponent::class)
            ->assertSee('No attendance uploaded yet')
            ->set('showUpload', true)
            ->set('file', $file)
            ->call('importReport')
            ->assertHasNoErrors()
            ->assertSet('month', '2026-10')
            ->assertSet('showUpload', false)
            ->assertSee('Ama Mensah')
            ->assertSee('Recorded up to Wed 21 Oct 2026')
            ->set('view', 'grid')
            ->assertSee('Absent')
            ->call('openStaff', AttendanceStaff::where('employee_no', '001')->value('id'))
            ->assertSee('Left 50m early')
            ->set('staffTracked', false)
            ->call('saveStaff')
            ->assertHasNoErrors();

        $this->assertSame(45, AttendancePunch::count());
        $this->assertSame($this->admin->id, AttendanceImport::first()->uploaded_by);
        $this->assertFalse(AttendanceStaff::where('employee_no', '001')->value('is_tracked'));
    }

    public function test_other_files_are_refused(): void
    {
        Livewire::test(AttendanceComponent::class)
            ->set('file', UploadedFile::fake()->createWithContent('report.csv', 'Employee ID,Name'))
            ->call('importReport')
            ->assertHasErrors('file');

        Livewire::test(AttendanceComponent::class)
            ->set('file', UploadedFile::fake()->createWithContent('report.xls', 'not really a spreadsheet'))
            ->call('importReport')
            ->assertHasErrors('file');

        $this->assertSame(0, AttendanceImport::count());
    }

    public function test_attendance_belongs_to_the_branch_it_was_uploaded_in(): void
    {
        $this->importFixture();

        $this->useBranch($this->otherBranchId);
        $this->assertSame(0, AttendanceStaff::count());
        $this->assertSame([], $this->october()['rows']);

        $this->importFixture();
        $this->assertSame(3, AttendanceStaff::count());
        $this->assertSame(6, AttendanceStaff::withoutGlobalScopes()->count());
    }

    public function test_only_managers_and_admins_can_open_the_page(): void
    {
        $this->get(route('admin.attendance'))->assertOk()->assertSee('Attendance');

        $cashier = User::factory()->create();
        $cashierRole = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $cashier->assignRole($cashierRole);
        $this->clinic->users()->attach($cashier, ['status' => 'active', 'clinic_role' => 'Cashier', 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $this->branchId, 'user_id' => $cashier->id, 'status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $this->branchId, 'user_id' => $cashier->id, 'role_id' => $cashierRole->id]);

        // Sent back to their own dashboard.
        $this->actingAs($cashier)->get(route('admin.attendance'))->assertRedirect();
    }

    public function test_nobody_is_absent_on_a_holiday(): void
    {
        $this->importFixture();

        Livewire::test(AttendanceComponent::class)
            ->set('showHolidays', true)
            ->set('holidayDate', '2026-10-02')
            ->set('holidayName', 'Founders Day')
            ->call('addHoliday')
            ->assertHasNoErrors()
            ->assertSee('Founders Day');

        $report = $this->october();
        $this->assertSame(['2026-10-02' => 'Founders Day'], $report['holidays']);
        $this->assertSame('holiday', $this->row($report, '002')['days']['2026-10-02']['status']);
        $this->assertSame(7, $this->row($report, '002')['totals']['absent']);
        // Ama came in anyway: worked on a day off, not late.
        $this->assertSame('worked_off', $this->row($report, '001')['days']['2026-10-02']['status']);
        $this->assertSame(1, $this->row($report, '001')['totals']['late']);

        Livewire::test(AttendanceComponent::class)->call('removeHoliday', AttendanceHoliday::value('id'));
        $this->assertSame('absent', $this->row($this->october(), '002')['days']['2026-10-02']['status']);
    }

    public function test_leave_is_not_counted_as_absence(): void
    {
        $this->importFixture();
        $esi = AttendanceStaff::where('employee_no', '004')->value('id');

        Livewire::test(AttendanceComponent::class)
            ->call('openStaff', $esi)
            ->set('leaveFrom', '2026-10-05')
            ->set('leaveTo', '2026-10-09')
            ->set('leaveType', 'sick')
            ->call('addLeave')
            ->assertHasNoErrors()
            ->assertSee('5 Oct 2026 to 9 Oct 2026')
            ->set('leaveFrom', '2026-10-12')
            ->set('leaveTo', '2026-10-01')
            ->call('addLeave')
            ->assertHasErrors('leaveTo');

        $report = $this->october();
        $row = $this->row($report, '004');
        $this->assertSame('leave', $row['days']['2026-10-05']['status']);
        $this->assertSame('Sick leave', $row['days']['2026-10-05']['note']);
        $this->assertSame('absent', $row['days']['2026-10-12']['status']);
        $this->assertSame([9, 5], [$row['totals']['absent'], $row['totals']['leave']]);
        $this->assertSame(5, $report['totals']['leave']);

        Livewire::test(AttendanceComponent::class)->call('openStaff', $esi)->call('removeLeave', AttendanceLeave::value('id'));
        $this->assertSame(14, $this->row($this->october(), '004')['totals']['absent']);
    }

    public function test_someone_with_their_own_hours_is_judged_against_them(): void
    {
        $this->importFixture();
        $kofi = AttendanceStaff::where('employee_no', '002')->value('id');

        // Mondays and Wednesdays only, from 9.
        $page = Livewire::test(AttendanceComponent::class)
            ->call('openStaff', $kofi)
            ->assertSet('staffOwnHours', false)
            ->assertSet('staffHours.1.start', '08:00')
            ->set('staffOwnHours', true);
        foreach ([2, 4, 5] as $weekday) {
            $page->set("staffHours.$weekday.on", false);
        }
        $page->set('staffHours.1.start', '09:00')->set('staffHours.3.start', '09:00')
            ->call('saveStaff')
            ->assertHasNoErrors()
            ->assertSee('Own hours');

        $row = $this->row($this->october(), '002');
        $this->assertSame('worked_off', $row['days']['2026-10-01']['status']); // Thursday
        $this->assertSame('on_time', $row['days']['2026-10-05']['status']);
        $this->assertSame('off', $row['days']['2026-10-02']['status']);
        $this->assertSame(3, $row['totals']['absent']); // Mon 12, Wed 14, Mon 19
        // Everyone else keeps the branch's hours.
        $this->assertSame(14, $this->row($this->october(), '004')['totals']['absent']);

        Livewire::test(AttendanceComponent::class)->call('openStaff', $kofi)->assertSet('staffOwnHours', true)
            ->set('staffOwnHours', false)->call('saveStaff');
        $this->assertNull(AttendanceStaff::find($kofi)->hours);
    }

    public function test_times_are_shown_on_a_twelve_hour_clock(): void
    {
        $this->assertSame('7:55 AM', AttendanceAnalyzer::time('07:55'));
        $this->assertSame('5:05 PM', AttendanceAnalyzer::time('17:05'));
        $this->assertSame('12:30 AM', AttendanceAnalyzer::time('00:30'));
        $this->assertSame('12:00 PM', AttendanceAnalyzer::time('12:00'));
        $this->assertNull(AttendanceAnalyzer::time(null));

        $this->importFixture();
        Livewire::test(AttendanceComponent::class)
            ->assertSee('7:58 AM') // Ama's usual arrival
            ->call('openStaff', AttendanceStaff::where('employee_no', '001')->value('id'))
            ->assertSee('5:05 PM')
            ->assertDontSee('17:05');
    }

    public function test_the_month_is_exported_for_excel_and_as_a_pdf(): void
    {
        $this->importFixture();
        AttendanceStaff::where('employee_no', '004')->update(['is_tracked' => false]);

        $csv = $this->get(route('admin.attendance.export', ['format' => 'csv', 'month' => '2026-10']))->assertOk()->streamedContent();
        $this->assertStringContainsString('SUMMARY', $csv);
        $this->assertStringContainsString('"=""001""","Ama Mensah",RECEPTION,15,15,13,2,36,0,0,1,1,0,"7:58 AM"', $csv);
        $this->assertStringContainsString('"Ama Mensah",2026-10-02,Fri,"8:20 AM","5:00 PM",8.67,Late,20', $csv);
        $this->assertStringContainsString('Kofi Añané', $csv);
        $this->assertStringNotContainsString('Esi Boateng', $csv);

        $pdf = $this->get(route('admin.attendance.export', ['format' => 'pdf', 'month' => '2026-10']))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->streamedContent());

        $this->get(route('admin.attendance.export', ['format' => 'csv', 'month' => 'October']))->assertSessionHasErrors('month');
    }

    private function clinicUser(string $name, ?Clinic $clinic = null): User
    {
        $user = User::factory()->create(['name' => $name]);
        ($clinic ?? $this->clinic)->users()->attach($user, ['status' => 'active', 'clinic_role' => 'Cashier', 'is_default' => true]);

        return $user;
    }

    public function test_new_terminal_staff_are_linked_to_the_user_of_the_same_name(): void
    {
        $this->assertSame('AMA MENSAH', AttendanceImportService::plainName('Dr. Ama  MENSAH'));
        $this->assertSame('KOFI AÑANÉ', AttendanceImportService::plainName('Mr Kofi Añané'));

        $ama = $this->clinicUser('Dr. Ama MENSAH');
        // Two users called Esi Boateng: not linked, since it could be either.
        $this->clinicUser('Esi Boateng');
        $this->clinicUser('Esi Boateng');

        $this->importFixture();

        $this->assertSame($ama->id, AttendanceStaff::where('employee_no', '001')->value('user_id'));
        $this->assertNull(AttendanceStaff::where('employee_no', '002')->value('user_id'));
        $this->assertNull(AttendanceStaff::where('employee_no', '004')->value('user_id'));
    }

    public function test_a_person_is_linked_to_a_user_account_by_hand(): void
    {
        $this->importFixture();
        $kofi = $this->clinicUser('K. Anane');
        $outsider = $this->clinicUser('Someone Else', $this->activeClinic(['name' => 'Clinic B', 'slug' => 'clinic-b']));
        $staff = fn (string $no) => AttendanceStaff::where('employee_no', $no)->first();

        Livewire::test(AttendanceComponent::class)
            ->call('openStaff', $staff('002')->id)
            ->assertSee('K. Anane')
            ->assertDontSee('Someone Else')
            ->set('staffUserId', (string) $outsider->id)
            ->call('saveStaff')
            ->assertHasErrors('staffUserId')
            ->set('staffUserId', (string) $kofi->id)
            ->call('saveStaff')
            ->assertHasNoErrors();
        $this->assertSame($kofi->id, $staff('002')->user_id);

        // One user, one person on the terminal.
        Livewire::test(AttendanceComponent::class)
            ->call('openStaff', $staff('004')->id)
            ->set('staffUserId', (string) $kofi->id)
            ->call('saveStaff')
            ->assertHasErrors('staffUserId');
        $this->assertNull($staff('004')->user_id);
    }

    public function test_a_linked_person_sees_their_own_attendance_on_their_profile(): void
    {
        $this->importFixture();

        Livewire::test(\App\Livewire\UserProfileComponent::class)->assertDontSee('My attendance');

        AttendanceStaff::where('employee_no', '001')->update(['user_id' => $this->admin->id]);
        Livewire::test(\App\Livewire\UserProfileComponent::class)
            ->assertSee('My attendance')
            ->call('switchTab', 'attendance')
            ->assertSee('Your attendance for October 2026')
            ->assertSee('8:20 AM')
            ->assertSee('Left 50m early')
            // Nobody else's.
            ->assertDontSee('9:10 AM');

        AttendanceStaff::where('employee_no', '001')->update(['is_tracked' => false]);
        Livewire::test(\App\Livewire\UserProfileComponent::class)->assertDontSee('My attendance');
    }

    public function test_the_owners_morning_email_lists_staff_late_or_absent_in_the_latest_report(): void
    {
        $this->importFixture();
        AttendanceStaff::where('employee_no', '004')->update(['is_tracked' => false]);

        $items = collect(app(\App\Services\OwnerAlertDigestService::class)->current($this->clinic, \Illuminate\Support\Carbon::parse('2026-10-22 10:00')))
            ->where('section', 'attendance')->keyBy('title');

        $this->assertSame(['Ama Mensah', 'Kofi Añané'], $items->keys()->sort()->values()->all());
        $this->assertSame('2 late (36m) in October to 21 Oct', $items['Ama Mensah']['detail']);
        $this->assertSame('2 late (1h 50m), 8 absent in October to 21 Oct', $items['Kofi Añané']['detail']);
        $this->assertSame('l2a8', $items['Kofi Añané']['stage']);
        $this->assertSame('Main', $items['Kofi Añané']['branch']);
    }
}
