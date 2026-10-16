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
        // The last day of the fixture's month, so "this month to date" is all of October 2026.
        \Illuminate\Support\Carbon::setTestNow('2026-10-31 12:00:00');
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

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
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

        // Four punches: in, out for lunch, back in, out. Time on site leaves the 57-minute break out.
        $this->assertSame(['07:50', '17:02'], [$ama['2026-10-07']['in'], $ama['2026-10-07']['out']]);
        $this->assertSame(['in', 'out', 'in', 'out'], array_column($ama['2026-10-07']['punches'], 'kind'));
        $this->assertSame(['07:50', '12:01', '12:58', '17:02'], array_column($ama['2026-10-07']['punches'], 'time'));
        $this->assertSame(495, $ama['2026-10-07']['minutes_worked']);
        $this->assertSame(57, $ama['2026-10-07']['break_minutes']);
        $this->assertFalse($ama['2026-10-07']['long_break']);

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
        // On the real clock: Livewire clears uploads it thinks are a day old.
        DB::table('clinic_subscriptions')->update(['current_period_starts_at' => '2026-01-01 00:00:00', 'current_period_ends_at' => '2036-01-01 00:00:00']);
        \Illuminate\Support\Carbon::setTestNow();
        $file = UploadedFile::fake()->createWithContent('AttendanceRecord.xls', file_get_contents($this->fixture()));

        Livewire::test(AttendanceComponent::class)
            ->assertSee('No attendance uploaded yet')
            ->set('showUpload', true)
            ->set('file', $file)
            ->call('importReport')
            ->assertHasNoErrors()
            ->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-31')
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

    public function test_every_punch_is_read_in_order_as_in_then_out(): void
    {
        $hours = ['start' => '08:00', 'end' => '17:00'];
        $day = fn (array $times, int $break = 60, string $state = 'complete') => app(AttendanceAnalyzer::class)->day($times, $hours, 15, $state, null, $break);

        // Out for two hours in the middle of the day: a long break, and those hours are not time on site.
        $out = $day(['08:00', '10:00', '12:00', '17:00']);
        $this->assertSame([120, true, 420], [$out['break_minutes'], $out['long_break'], $out['minutes_worked']]);
        $this->assertFalse($day(['08:00', '10:00', '12:00', '17:00'], 120)['long_break']);

        // Two breaks add up.
        $two = $day(['08:00', '10:00', '10:40', '13:00', '13:40', '17:00']);
        $this->assertSame([80, true], [$two['break_minutes'], $two['long_break']]);

        // Three punches: went out, came back, never punched out. The departure is not known.
        $odd = $day(['08:00', '12:00', '13:00']);
        $this->assertSame(['08:00', null, true, 240], [$odd['in'], $odd['out'], $odd['no_clock_out'], $odd['minutes_worked']]);
        $this->assertFalse($odd['left_early']);
        // On a day still going they are simply in.
        $this->assertFalse($day(['08:00', '12:00', '13:00'], 60, 'partial')['no_clock_out']);

        // The terminal recognising someone twice in a moment is one punch.
        $twice = $day(['07:59', '08:00', '08:01', '17:00', '17:01']);
        $this->assertSame(['07:59', '17:00'], array_column($twice['punches'], 'time'));
        $this->assertSame(541, $twice['minutes_worked']);
    }

    public function test_long_breaks_are_counted_and_every_punch_is_shown(): void
    {
        $this->importFixture();

        Livewire::test(AttendanceComponent::class)
            ->call('editHours')
            ->assertSet('breakMinutes', 60)
            ->set('breakMinutes', 30)
            ->call('saveHours')
            ->assertHasNoErrors()
            ->call('openStaff', AttendanceStaff::where('employee_no', '001')->value('id'))
            ->assertSee('Out 12:01 PM')
            ->assertSee('In 12:58 PM')
            ->assertSee('Long break');

        $this->assertSame(30, AttendanceSetting::current()->break_minutes);
        $ama = $this->row($this->october(), '001');
        $this->assertTrue($ama['days']['2026-10-07']['long_break']);
        $this->assertSame([1, 57], [$ama['totals']['long_breaks'], $ama['totals']['break_minutes']]);

        $csv = $this->get(route('admin.attendance.export', ['format' => 'csv', 'month' => '2026-10']))->streamedContent();
        $this->assertStringContainsString('57,Yes,"In 7:50 AM; Out 12:01 PM; In 12:58 PM; Out 5:02 PM;"', $csv);
    }

    private function digest(string $period, string $from, string $to, string $previousFrom, string $previousTo): ?array
    {
        $day = fn (string $date) => \Illuminate\Support\Carbon::parse($date);

        return app(\App\Services\Attendance\AttendanceDigest::class)->block($period, $day($from), $day($to), $day($previousFrom), $day($previousTo));
    }

    public function test_the_daily_email_names_only_the_exceptions(): void
    {
        $this->assertNull($this->digest('daily', '2026-10-02', '2026-10-02', '2026-10-01', '2026-10-01'), 'No terminal staff yet.');
        $this->importFixture();

        // Friday the 2nd: Ama came in late, the other two did not come.
        $friday = $this->digest('daily', '2026-10-02', '2026-10-02', '2026-10-01', '2026-10-01');
        $this->assertSame('1 of 3 staff present', $friday['headline']);
        $this->assertSame([
            ['name' => 'Ama Mensah', 'detail' => 'In at 8:20 AM, 20m late', 'tone' => 'late'],
            ['name' => 'Esi Boateng', 'detail' => 'Absent', 'tone' => 'absent'],
            ['name' => 'Kofi Añané', 'detail' => 'Absent', 'tone' => 'absent'],
        ], $friday['exceptions']);

        // Monday the 5th: Ama left early; Tuesday: she never punched out.
        $this->assertSame('Left 50m early (4:10 PM)', collect($this->digest('daily', '2026-10-05', '2026-10-05', '2026-10-02', '2026-10-02')['exceptions'])->firstWhere('name', 'Ama Mensah')['detail']);
        $this->assertSame('A punch is missing', collect($this->digest('daily', '2026-10-06', '2026-10-06', '2026-10-05', '2026-10-05')['exceptions'])->firstWhere('name', 'Ama Mensah')['detail']);

        // A Saturday, when nobody is expected: nothing to say, even though Kofi came in.
        $this->assertNull($this->digest('daily', '2026-10-03', '2026-10-03', '2026-10-02', '2026-10-02'));

        // A day nothing was received for is not a day of absences.
        $unknown = $this->digest('daily', '2026-10-22', '2026-10-22', '2026-10-21', '2026-10-21');
        $this->assertNull($unknown['headline']);
        $this->assertSame([], $unknown['exceptions']);
        $this->assertStringContainsString('No attendance was received', $unknown['note']);
    }

    public function test_someone_on_leave_is_named_in_the_daily_email_and_not_counted_absent(): void
    {
        $this->importFixture();
        AttendanceLeave::create(['attendance_staff_id' => AttendanceStaff::where('employee_no', '004')->value('id'), 'starts_on' => '2026-10-02', 'ends_on' => '2026-10-02', 'type' => 'sick']);

        $friday = $this->digest('daily', '2026-10-02', '2026-10-02', '2026-10-01', '2026-10-01');

        $this->assertSame('1 of 2 staff present, 1 on leave', $friday['headline']);
        $this->assertSame(['detail' => 'On leave (Sick leave)', 'tone' => 'note'], \Illuminate\Support\Arr::only(collect($friday['exceptions'])->firstWhere('name', 'Esi Boateng'), ['detail', 'tone']));
    }

    public function test_the_weekly_and_monthly_emails_list_everyone_and_name_who_was_latest(): void
    {
        $this->importFixture();
        AttendanceStaff::where('employee_no', '004')->update(['is_tracked' => false]);

        // Monday 5th to Sunday 11th, against the week before (Thursday 1st and Friday 2nd are all it holds).
        $week = $this->digest('weekly', '2026-10-05', '2026-10-11', '2026-09-28', '2026-10-04');
        $this->assertSame('10 of 10 working days attended', $week['headline']);
        $this->assertSame([100.0, 75.0], [$week['rate'], $week['previousRate']]);
        $this->assertSame(['Ama Mensah', 'Kofi Añané'], array_column($week['rows'], 'name'));
        $ama = $week['rows'][0];
        $this->assertSame([5, 5, 1, '16m', 0], [$ama['present'], $ama['expected'], $ama['late'], $ama['late_time'], $ama['absent']]);
        $this->assertSame('Ama Mensah (16m over 1 day)', $week['mostLate']);
        $this->assertNull($week['note']);

        $month = $this->digest('monthly', '2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30');
        $this->assertSame('22 of 30 working days attended', $month['headline']);
        $this->assertSame(73.3, $month['rate']);
        $this->assertNull($month['previousRate']);
        $this->assertSame('Kofi Añané (1h 50m over 2 days), Ama Mensah (36m over 2 days)', $month['mostLate']);
        $this->assertSame([], $month['perfect']);
        $this->assertSame('Recorded up to Wed 21 Oct; later days are not counted.', $month['note']);
        $this->assertSame(8, collect($month['rows'])->firstWhere('name', 'Kofi Añané')['absent']);
    }

    public function test_the_owner_summary_email_carries_the_attendance_block(): void
    {
        $this->importFixture();
        $summaries = app(\App\Services\OwnerSummaryService::class);
        $day = fn (string $date) => \Illuminate\Support\Carbon::parse($date);

        $daily = $summaries->build($this->clinic, 'daily', $day('2026-10-02'), $day('2026-10-02'));
        $this->assertSame(['Main'], array_keys($daily['attendance']));
        $html = (new \App\Mail\OwnerSummaryMail($daily))->render();
        $this->assertStringContainsString('Staff attendance', $html);
        $this->assertStringContainsString('1 of 3 staff present', $html);
        $this->assertStringContainsString('In at 8:20 AM, 20m late', $html);
        $this->assertStringContainsString(route('admin.attendance'), $html);

        $monthly = (new \App\Mail\OwnerSummaryMail($summaries->build($this->clinic, 'monthly', $day('2026-10-01'), $day('2026-10-31'))))->render();
        $this->assertStringContainsString('Most time lost to lateness:', $monthly);
        $this->assertStringContainsString('Kofi Añané (1h 50m over 2 days)', $monthly);
        $this->assertStringContainsString('On site', $monthly);

        // A clinic with no terminal gets the email exactly as before.
        AttendanceStaff::query()->delete();
        $plain = $summaries->build($this->clinic, 'daily', $day('2026-10-02'), $day('2026-10-02'));
        $this->assertSame([], $plain['attendance']);
        $this->assertStringNotContainsString('Staff attendance', (new \App\Mail\OwnerSummaryMail($plain))->render());
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

    public function test_leave_for_part_of_a_day_moves_when_they_are_expected(): void
    {
        $this->importFixture();
        $ama = AttendanceStaff::where('employee_no', '001')->value('id');
        $this->assertSame('late', $this->row($this->october(), '001')['days']['2026-10-02']['status']);
        $this->assertTrue($this->row($this->october(), '001')['days']['2026-10-05']['left_early']);

        // Friday the 2nd she came at 8:20 with permission until 9; Monday the 5th she left at 4:10 with the last hour off.
        Livewire::test(AttendanceComponent::class)
            ->call('openStaff', $ama)
            ->set('leaveFrom', '2026-10-02')->set('leaveFromTime', '08:00')->set('leaveToTime', '09:00')->set('leaveType', 'permission')
            ->call('addLeave')
            ->assertHasNoErrors()
            ->assertSee('2 Oct 2026, 8:00 AM to 9:00 AM')
            ->set('leaveFrom', '2026-10-05')->set('leaveFromTime', '16:00')->set('leaveToTime', '17:00')
            ->call('addLeave')
            ->assertHasNoErrors()
            // Times need both ends, in order, and one day only.
            ->set('leaveFrom', '2026-10-06')->set('leaveFromTime', '12:00')->set('leaveToTime', '11:00')
            ->call('addLeave')
            ->assertHasErrors('leaveToTime')
            ->set('leaveToTime', '13:00')->set('leaveTo', '2026-10-07')
            ->call('addLeave')
            ->assertHasErrors('leaveToTime');

        $days = $this->row($this->october(), '001')['days'];
        $this->assertSame(['on_time', 0, 'Permission 8:00 AM to 9:00 AM'], [$days['2026-10-02']['status'], $days['2026-10-02']['late_minutes'], $days['2026-10-02']['note']]);
        $this->assertFalse($days['2026-10-05']['left_early']);
        $this->assertSame(0, $this->row($this->october(), '001')['totals']['leave']);

        // Time off in the middle of the day is extra break; the whole working day off is a day of leave.
        $analyzer = app(AttendanceAnalyzer::class);
        $less = new \ReflectionMethod($analyzer, 'lessTimeAway');
        $hours = ['start' => '08:00', 'end' => '17:00'];
        $this->assertSame([$hours, 150, false], $less->invoke($analyzer, $hours, [['from' => '11:00', 'to' => '12:30']], 60));
        $this->assertSame([['start' => '17:00', 'end' => '17:00'], 60, true], $less->invoke($analyzer, $hours, [['from' => '12:30', 'to' => '17:00'], ['from' => '08:00', 'to' => '12:30']], 60));
    }

    public function test_staff_ask_for_leave_and_it_counts_once_a_manager_approves(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->importFixture();
        $this->clinic->update(['billing_email' => 'owner@clinic-a.test']);
        DB::table('clinic_subscriptions')->update(['current_period_starts_at' => '2026-01-01 00:00:00', 'current_period_ends_at' => '2036-01-01 00:00:00']);
        \Illuminate\Support\Carbon::setTestNow('2026-10-01 09:00:00');
        $esi = AttendanceStaff::where('employee_no', '004')->first();
        $esi->update(['user_id' => $this->admin->id]);

        $profile = Livewire::test(\App\Livewire\UserProfileComponent::class)
            ->call('switchTab', 'attendance')
            ->assertSee('Ask for time off')
            // Not for days gone by, and a morning needs a working day.
            ->set('leaveFrom', '2026-09-28')->call('requestLeave')->assertHasErrors('leaveFrom')
            ->set('leaveKind', 'morning')->set('leaveFrom', '2026-10-10')->call('requestLeave')->assertHasErrors('leaveFrom')
            ->set('leaveKind', 'days')->set('leaveFrom', '2026-10-12')->set('leaveTo', '2026-10-13')->set('leaveType', 'sick')->set('leaveNote', 'Clinic appointment')
            ->call('requestLeave')
            ->assertHasNoErrors()
            ->assertSee('12 Oct 2026 to 13 Oct 2026')
            ->assertSee('Waiting for approval')
            ->set('leaveKind', 'morning')->set('leaveFrom', '2026-10-14')
            ->call('requestLeave')
            ->assertHasNoErrors()
            ->assertSee('14 Oct 2026, 8:00 AM to 12:30 PM')
            ->set('leaveKind', 'hours')->set('leaveFrom', '2026-10-15')->set('leaveFromTime', '10:00')->set('leaveToTime', '11:00')
            ->call('requestLeave')
            ->assertHasNoErrors();
        [$days, $morning, $hours] = AttendanceLeave::orderBy('id')->get()->all();
        $this->assertSame(['pending', $this->admin->id, null], [$days->status, $days->requested_by, $days->from_time]);
        $this->assertSame(['08:00', '12:30'], [$morning->from_time, $morning->to_time]);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OwnerNoticeMail::class, fn ($mail) => $mail->hasTo('owner@clinic-a.test')
            && $mail->heading === 'Leave waiting for approval' && $mail->details['When'] === '12 Oct 2026 to 13 Oct 2026' && $mail->details['Reason'] === 'Clinic appointment');

        // Not answered yet: still an absence. A request can be taken back until it is.
        $this->assertSame('absent', $this->row($this->october(), '004')['days']['2026-10-12']['status']);
        $profile->call('withdrawLeave', $hours->id);
        $this->assertSame(2, AttendanceLeave::count());

        Livewire::test(AttendanceComponent::class)
            ->assertSee('leave requests are', false)
            ->set('showRequests', true)
            ->assertSee('Clinic appointment')
            ->call('reviewLeave', $days->id, true)
            ->set('reviewNotes.'.$morning->id, 'We are short that day')
            ->call('reviewLeave', $morning->id, false)
            ->assertSee('No requests are waiting.');

        $this->assertSame(['approved', $this->admin->id], [$days->fresh()->status, $days->fresh()->reviewed_by]);
        $this->assertSame(['rejected', 'We are short that day'], [$morning->fresh()->status, $morning->fresh()->review_note]);
        $row = $this->row($this->october(), '004');
        $this->assertSame(['leave', 'leave', 'absent'], [$row['days']['2026-10-12']['status'], $row['days']['2026-10-13']['status'], $row['days']['2026-10-14']['status']]);
        $this->assertNull($row['days']['2026-10-14']['note']);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OwnerNoticeMail::class, fn ($mail) => $mail->hasTo($this->admin->email) && $mail->heading === 'Your leave was approved');
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OwnerNoticeMail::class, fn ($mail) => $mail->hasTo($this->admin->email)
            && $mail->heading === 'Your leave was not approved' && $mail->details['Note'] === 'We are short that day');
        // An answered request cannot be withdrawn or answered again.
        $profile->call('withdrawLeave', $days->id);
        $this->assertSame(2, AttendanceLeave::count());
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_day_by_day_is_a_month_calendar_with_a_list_for_each_day(): void
    {
        $this->importFixture();
        $cell = fn (array $calendar, string $key) => collect($calendar['weeks'])->flatten(1)->filter()->firstWhere('key', $key);

        Livewire::test(AttendanceComponent::class)
            ->set('view', 'grid')
            ->assertSee('October 2026')
            ->assertViewHas('calendar', function (array $calendar) use ($cell) {
                // October 2026 starts on a Thursday: five weeks, Monday first.
                $friday = $cell($calendar, '2026-10-02')['counts'];

                return count($calendar['weeks']) === 5 && $calendar['weeks'][0][0] === null && $calendar['weeks'][0][3]['key'] === '2026-10-01'
                    && [$friday['in'], $friday['late'], $friday['absent'], $friday['leave']] === [1, 1, 2, 0]
                    // Kofi came in on a Saturday; nobody was expected on the Sunday; nothing was received after the 21st.
                    && $cell($calendar, '2026-10-03')['counts']['in'] === 1 && $cell($calendar, '2026-10-03')['state'] === 'day'
                    && $cell($calendar, '2026-10-04')['state'] === 'off' && $cell($calendar, '2026-10-22')['state'] === 'none'
                    && ! $calendar['previous'] && ! $calendar['next'];
            })
            ->assertSee('2 absent')
            ->assertSee('No data')
            // A day opens as a list of everyone, those to look at first.
            ->call('openDay', '2026-10-02')
            ->assertSet('showDay', true)
            ->assertSee('Friday 2 October 2026')
            ->assertSee('In 8:20 AM')
            ->assertViewHas('dayList', fn (array $list) => array_map(fn ($person) => $person['staff']->name, $list['people']) === ['Esi Boateng', 'Kofi Añané', 'Ama Mensah'])
            ->set('showDay', false)
            ->call('openDay', '2026-11-05')
            ->assertSet('showDay', false)
            // Days picked across two months: one month at a time, the later one first.
            ->set('from', '2026-09-15')->set('to', '2026-10-09')
            ->assertViewHas('calendar', fn (array $calendar) => $calendar['month']->format('Y-m') === '2026-10' && $calendar['previous'] && ! $calendar['next']
                && $cell($calendar, '2026-10-09')['shown'] && ! $cell($calendar, '2026-10-10')['shown'])
            ->call('shiftCalendar', -1)
            ->assertSee('September 2026')
            ->call('shiftCalendar', -1)
            ->assertSet('calendarMonth', '2026-09')
            ->call('shiftCalendar', 1)
            ->assertSet('calendarMonth', '2026-10');
    }

    public function test_the_page_covers_whatever_days_are_picked(): void
    {
        $this->importFixture();
        $period = \App\Services\Attendance\AttendancePeriod::class;
        $day = fn (string $date) => CarbonImmutable::parse($date);

        // It opens on this month to date; a week shows that week alone.
        Livewire::test(AttendanceComponent::class)
            ->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-31')
            ->assertSee('from=2026-10-01&amp;to=2026-10-31', false)
            ->set('from', '2026-10-05')->set('to', '2026-10-09')
            ->assertViewHas('periodLabel', '5 Oct – 9 Oct 2026')
            ->assertViewHas('report', fn ($report) => array_keys($report['days']) === ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09']
                && $this->row($report, '001')['totals']['expected'] === 5 && $this->row($report, '001')['totals']['late'] === 1)
            ->call('openStaff', AttendanceStaff::where('employee_no', '001')->value('id'))
            ->assertSee('Days, 5 Oct – 9 Oct 2026')
            // Dates the wrong way round are read the right way.
            ->set('from', '2026-10-09')->set('to', '2026-10-05')
            ->assertViewHas('first', fn ($first) => $first->format('Y-m-d') === '2026-10-05')
            ->set('from', '2026-07-01')->set('to', '2026-10-31')
            ->assertViewHas('report', fn ($report) => count($report['days']) === 123);
        // An older link to a month still opens that month.
        Livewire::withQueryParams(['month' => '2026-09'])->test(AttendanceComponent::class)->assertSet('from', '2026-09-01')->assertSet('to', '2026-09-30');

        $this->assertSame(['October 2026', 'Mon 5 Oct 2026', '28 Dec 2025 – 3 Jan 2026'], [
            $period::label($day('2026-10-01'), $day('2026-10-31')), $period::label($day('2026-10-05'), $day('2026-10-05')), $period::label($day('2025-12-28'), $day('2026-01-03')),
        ]);
        // Nothing picked is this month to date; more than a year is cut to a year.
        $this->assertSame(['2026-10-01', '2026-10-12'], array_map(fn ($d) => $d->format('Y-m-d'), $period::resolve('', 'nonsense', $day('2026-10-12'))));
        $this->assertSame(366, $period::days(...$period::resolve('2024-01-01', '2026-10-12', $day('2026-10-12'))));

        // The files follow the days too.
        $week = $this->get(route('admin.attendance.export', ['format' => 'csv', 'from' => '2026-10-05', 'to' => '2026-10-09']))->assertOk();
        $this->assertStringContainsString('attendance-2026-10-05-to-2026-10-09.csv', $week->headers->get('Content-Disposition'));
        $csv = $week->streamedContent();
        $this->assertStringContainsString('Attendance,"5 Oct – 9 Oct 2026"', $csv);
        $this->assertStringNotContainsString('2026-10-02', $csv);
        $this->assertStringContainsString('attendance-2026-10.pdf', $this->get(route('admin.attendance.export', ['format' => 'pdf', 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->headers->get('Content-Disposition'));
        $this->get(route('admin.attendance.export', ['format' => 'pdf', 'from' => '2026-07-01', 'to' => '2026-10-31']))->assertOk();
        $this->get(route('admin.attendance.export', ['format' => 'csv', 'from' => '2026-10-09', 'to' => '2026-10-05']))->assertSessionHasErrors('to');

        // Staff pick their own days on their profile.
        AttendanceStaff::where('employee_no', '001')->update(['user_id' => $this->admin->id]);
        Livewire::test(\App\Livewire\UserProfileComponent::class)->call('switchTab', 'attendance')
            ->assertSet('attendanceFrom', '2026-10-01')->assertSee('Your attendance for October 2026')->assertSee('Left 50m early')
            ->set('attendanceFrom', '2026-10-02')->set('attendanceTo', '2026-10-02')
            ->assertSee('Your attendance for Fri 2 Oct 2026')->assertSee('8:20 AM')->assertDontSee('Left 50m early');
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

    public function test_a_first_time_owner_gets_a_checklist_that_ticks_itself_and_a_tour(): void
    {
        // An empty page: five steps to go, and a Tour button, which does not start by itself on an empty page.
        Livewire::test(AttendanceComponent::class)
            ->assertSee('Getting started with Attendance')->assertSee('0 of 5 done')
            ->assertSee('Connect the terminal, or upload its report')
            ->assertSeeHtml('data-tour="tour-replay"')->assertSeeHtml('data-tour="setup-checklist"');

        // A report brings in the punches and the people; saving hours and a holiday tick those too.
        $this->importFixture();
        AttendanceSetting::create(['hours' => array_fill_keys([1, 2, 3, 4, 5], ['start' => '08:00', 'end' => '17:00']), 'grace_minutes' => 15, 'break_minutes' => 60]);
        AttendanceHoliday::create(['date' => '2026-12-25', 'name' => 'Christmas Day']);
        $page = Livewire::test(AttendanceComponent::class)->assertSee('4 of 5 done')
            ->assertSeeHtml('data-tour="att-tab-report"')->assertSeeHtml('data-tour="att-period"')->assertSeeHtml('data-tour="att-people"')->assertSeeHtml('data-tour="att-setup"');

        // Put away, it stays away for this person.
        $page->call('hideSetup')->assertDontSee('Getting started with Attendance');
        Livewire::test(AttendanceComponent::class)->assertDontSee('Getting started with Attendance');

        // Linking the last step instead also ends it, for someone who never hid it.
        \App\Support\Guides\Guides::forget(\App\Support\Guides\AttendanceSetup::GUIDE);
        AttendanceStaff::where('employee_no', '001')->update(['user_id' => $this->admin->id]);
        Livewire::test(AttendanceComponent::class)->assertDontSee('Getting started with Attendance');
    }

    public function test_tours_are_chosen_for_the_person_and_remembered_once_finished_or_skipped(): void
    {
        $tours = \App\Support\Guides\TourCatalog::class;
        $parts = ['shifts' => true, 'overtime' => true, 'leave' => true, 'payroll' => true, 'phone' => false, 'trends' => true, 'hospital' => false];
        $manager = $tours::tour('attendance', ['manager' => true, 'extras' => $parts, 'board' => false]);
        $this->assertSame('attendance.manager', $manager['key']);
        $targets = array_column($manager['steps'], 'target');
        $this->assertContains('att-tab-requests', $targets);
        $this->assertNotContains('att-tab-today', $targets, 'No live board, so no Today step.');
        $this->assertStringContainsString('Trends', collect($manager['steps'])->firstWhere('target', 'att-views')['body']);
        $this->assertStringNotContainsString('phone', $manager['steps'][0]['body']);
        $this->assertSame('attendance.head', $tours::tour('attendance', ['manager' => false, 'board' => true])['key']);
        $this->assertSame('att-my-tab', $tours::tour('my-attendance')['steps'][0]['open']);
        $this->assertNull($tours::tour('nowhere'));

        // Finishing or skipping is kept on the account; anything else is refused.
        $this->assertFalse(\App\Support\Guides\Guides::seen('attendance.manager'));
        $this->postJson(route('guides.record', 'attendance.manager'), ['status' => 'skipped'])->assertNoContent();
        $this->assertTrue(\App\Support\Guides\Guides::seen('attendance.manager'));
        $this->assertFalse(\App\Support\Guides\Guides::seen('attendance.manager', 2), 'A newer version of the tour starts again.');
        $this->postJson(route('guides.record', 'attendance.unknown'), ['status' => 'done'])->assertNotFound();
        $this->postJson(route('guides.record', 'attendance.head'), ['status' => 'maybe'])->assertUnprocessable();
        $this->assertFalse(\App\Support\Guides\Guides::seen('attendance.head'));
    }

    public function test_staff_get_the_my_attendance_tour_on_their_profile(): void
    {
        $this->importFixture();
        Livewire::test(\App\Livewire\UserProfileComponent::class)->assertDontSeeHtml('data-tour="att-my-tab"');

        AttendanceStaff::where('employee_no', '001')->update(['user_id' => $this->admin->id]);
        Livewire::test(\App\Livewire\UserProfileComponent::class)
            ->assertSeeHtml('data-tour="att-my-tab"')->assertSeeHtml('data-tour="tour-replay"')
            ->call('switchTab', 'attendance')
            ->assertSeeHtml('data-tour="att-my-period"')->assertSeeHtml('data-tour="att-my-totals"')->assertSeeHtml('data-tour="att-my-leave"');
    }
}
