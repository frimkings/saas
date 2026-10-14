<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Models\AttendanceDevice;
use App\Mail\OwnerNoticeMail;
use App\Models\AttendanceHoliday;
use App\Models\AttendanceLeave;
use App\Models\AttendancePunch;
use App\Models\AttendanceStaff;
use App\Models\Clinic;
use App\Models\User;
use App\Services\Attendance\AttendanceAnalyzer;
use App\Services\OwnerAlerts;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** A connected terminal posts each event to its own address; punches are stored for its branch. */
class AttendancePushTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private User $admin;
    private Clinic $clinic;
    private int $branchId;
    private AttendanceDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $this->clinic = $this->activeClinic(['name' => 'Clinic A', 'slug' => 'clinic-a']);
        $this->branchId = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true])->id;
        $this->admin = User::factory()->create(['name' => 'Ama Mensah']);
        $this->admin->assignRole($role);
        $this->clinic->users()->attach($this->admin, ['status' => 'active', 'clinic_role' => 'Super Admin', 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $this->branchId, 'user_id' => $this->admin->id, 'status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $this->branchId, 'user_id' => $this->admin->id, 'role_id' => $role->id]);

        $this->asAdmin();
        $this->device = AttendanceDevice::create(['name' => 'Front door']);
        // The terminal itself is not signed in.
        auth()->logout();
        app(TenantContext::class)->clear();
    }

    private function asAdmin(): void
    {
        $this->actingAs($this->admin);
        app(TenantContext::class)->set($this->admin, $this->clinic, $this->clinic->branches()->findOrFail($this->branchId), [$this->branchId]);
    }

    private function event(string $employeeNo, string $time, array $detail = [], array $top = []): array
    {
        return $top + [
            'ipAddress' => '192.168.1.64', 'macAddress' => '44:a6:42:00:00:01', 'dateTime' => $time, 'activePostCount' => 1,
            'eventType' => 'AccessControllerEvent', 'eventState' => 'active', 'eventDescription' => 'Access Controller Event',
            'AccessControllerEvent' => $detail + ['deviceName' => 'Access Controller', 'majorEventType' => 5, 'subEventType' => 75, 'name' => 'Ama Mensah',
                'employeeNoString' => $employeeNo, 'serialNo' => 331, 'userType' => 'normal', 'currentVerifyMode' => 'faceOrFpOrCardOrPw', 'attendanceStatus' => 'undefined'],
        ];
    }

    private function push(array $event, ?string $token = null)
    {
        return $this->call('POST', '/api/v1/attendance/push/'.($token ?? $this->device->token), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event));
    }

    private function punches(): array
    {
        return DB::table('attendance_punches')->orderBy('punched_at')->pluck('punched_at')->all();
    }

    public function test_a_json_punch_is_stored_for_the_terminals_branch(): void
    {
        $this->push($this->event('001', '2026-10-12T07:55:03+00:00'))->assertOk();

        $staff = AttendanceStaff::withoutGlobalScopes()->sole();
        $this->assertSame(['001', 'Ama Mensah', $this->branchId, $this->clinic->id], [$staff->employee_no, $staff->name, $staff->branch_id, $staff->clinic_id]);
        // Someone new on the terminal is linked to the user of the same name.
        $this->assertSame($this->admin->id, $staff->user_id);
        $this->assertSame(['2026-10-12 07:55:03'], $this->punches());

        $device = AttendanceDevice::withoutGlobalScopes()->sole();
        $this->assertSame(1, $device->punches_received);
        $this->assertSame('2026-10-12 07:55:03', $device->last_event_at->format('Y-m-d H:i:s'));
        $this->assertTrue($device->isOnline());
    }

    public function test_the_terminals_own_clock_is_kept_whatever_zone_it_claims(): void
    {
        $this->push($this->event('001', '2026-10-12T07:55:03+08:00'))->assertOk();

        $this->assertSame(['2026-10-12 07:55:03'], $this->punches());
    }

    public function test_a_multipart_punch_with_a_photo_is_read_and_the_photo_ignored(): void
    {
        $this->post('/api/v1/attendance/push/'.$this->device->token, [
            'event_log' => json_encode($this->event('002', '2026-10-12T08:20:00+00:00', ['name' => 'Kofi'])),
            'Picture' => UploadedFile::fake()->image('face.jpg'),
        ])->assertOk();

        $this->assertSame(['2026-10-12 08:20:00'], $this->punches());
        $this->assertSame('Kofi', AttendanceStaff::withoutGlobalScopes()->value('name'));
    }

    public function test_an_xml_punch_is_read(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><EventNotificationAlert version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema">'
            .'<ipAddress>192.168.1.64</ipAddress><dateTime>2026-10-12T17:05:00+00:00</dateTime><eventType>AccessControllerEvent</eventType>'
            .'<AccessControllerEvent><majorEventType>5</majorEventType><subEventType>38</subEventType><employeeNoString>004</employeeNoString></AccessControllerEvent>'
            .'</EventNotificationAlert>';

        $this->call('POST', '/api/v1/attendance/push/'.$this->device->token, [], [], [], ['CONTENT_TYPE' => 'application/xml'], $xml)->assertOk();

        $this->assertSame(['2026-10-12 17:05:00'], $this->punches());
        $this->assertSame('Employee 004', AttendanceStaff::withoutGlobalScopes()->value('name'));
    }

    public function test_the_terminals_label_and_method_are_kept_with_the_punch(): void
    {
        $this->push($this->event('001', '2026-10-12T07:55:03+00:00', ['attendanceStatus' => 'checkIn']))->assertOk();
        $this->push($this->event('001', '2026-10-12T12:00:00+00:00', ['attendanceStatus' => 'breakOut', 'subEventType' => 38]))->assertOk();
        $this->push($this->event('001', '2026-10-12T17:00:00+00:00'))->assertOk();

        $rows = DB::table('attendance_punches')->orderBy('punched_at')->get(['terminal_status', 'method'])->map(fn ($row) => [$row->terminal_status, $row->method])->all();
        $this->assertSame([['checkIn', 'face'], ['breakOut', 'fingerprint'], [null, 'face']], $rows);
    }

    public function test_a_replayed_punch_fills_in_a_label_that_was_not_kept_before(): void
    {
        $this->push($this->event('001', '2026-10-12T07:55:03+00:00'))->assertOk();
        DB::table('attendance_punches')->update(['terminal_status' => null, 'method' => null]);

        $this->push($this->event('001', '2026-10-12T07:55:03+00:00', ['attendanceStatus' => 'checkIn']))->assertOk();

        $punch = DB::table('attendance_punches')->sole();
        $this->assertSame(['checkIn', 'face'], [$punch->terminal_status, $punch->method]);
        $this->assertSame(1, AttendanceDevice::withoutGlobalScopes()->value('punches_received'));
    }

    public function test_a_punch_sent_again_is_stored_once(): void
    {
        $this->push($this->event('001', '2026-10-12T07:55:03+00:00'))->assertOk();
        $this->push($this->event('001', '2026-10-12T07:55:03+00:00'))->assertOk();
        $this->push($this->event('001', '2026-10-12T17:01:10+00:00'))->assertOk();

        $this->assertSame(['2026-10-12 07:55:03', '2026-10-12 17:01:10'], $this->punches());
        $this->assertSame(1, AttendanceStaff::withoutGlobalScopes()->count());
        $this->assertSame(2, AttendanceDevice::withoutGlobalScopes()->value('punches_received'));
    }

    public function test_events_that_are_not_a_person_are_not_punches_but_show_the_terminal_working(): void
    {
        // A door opening, a failed face, an alarm and a heartbeat.
        $this->push($this->event('', '2026-10-12T07:50:00+00:00', ['subEventType' => 21]))->assertOk();
        $this->push($this->event('', '2026-10-12T07:51:00+00:00', ['subEventType' => 76]))->assertOk();
        $this->push($this->event('009', '2026-10-12T07:52:00+00:00', ['majorEventType' => 1]))->assertOk();
        $this->push(['eventType' => 'heartBeat', 'dateTime' => '2026-10-12T09:00:00+00:00'])->assertOk();
        $this->get('/api/v1/attendance/push/'.$this->device->token)->assertOk();

        $this->assertSame([], $this->punches());
        $this->assertSame(0, AttendanceStaff::withoutGlobalScopes()->count());
        $device = AttendanceDevice::withoutGlobalScopes()->sole();
        $this->assertSame(['2026-10-12 07:50:00', '2026-10-12 09:00:00'], [$device->first_event_at->format('Y-m-d H:i:s'), $device->last_event_at->format('Y-m-d H:i:s')]);
    }

    public function test_an_unknown_or_removed_terminal_is_refused(): void
    {
        $this->push($this->event('001', '2026-10-12T07:55:03+00:00'), 'not-a-real-token')->assertNotFound();

        AttendanceDevice::withoutGlobalScopes()->delete();
        $this->push($this->event('001', '2026-10-12T07:55:03+00:00'))->assertNotFound();

        $this->assertSame([], $this->punches());
    }

    public function test_a_post_that_cannot_be_read_is_kept_for_a_look(): void
    {
        $this->call('POST', '/api/v1/attendance/push/'.$this->device->token, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'employee=001;time=now')->assertOk();

        $kept = DB::table('attendance_captures')->sole();
        $this->assertSame('employee=001;time=now', $kept->body);
        $this->assertSame([], $this->punches());
    }

    public function test_a_connected_terminal_accounts_for_the_days_it_was_working(): void
    {
        // Monday: Ama on time, in and out. Tuesday: nobody. Wednesday: Ama late, the day still going.
        $this->push($this->event('001', '2026-10-12T07:55:00+00:00'))->assertOk();
        $this->push($this->event('001', '2026-10-12T17:02:00+00:00'))->assertOk();
        $this->push($this->event('001', '2026-10-14T08:40:00+00:00'))->assertOk();

        $this->asAdmin();
        $report = app(AttendanceAnalyzer::class)->month(CarbonImmutable::create(2026, 10, 1));
        $days = $report['rows'][0]['days'];

        $this->assertSame('none', $days['2026-10-09']['status']); // before the terminal was connected
        $this->assertSame('on_time', $days['2026-10-12']['status']);
        $this->assertSame('absent', $days['2026-10-13']['status']);
        $this->assertSame('late', $days['2026-10-14']['status']);
        $this->assertTrue($days['2026-10-14']['in_progress']);
        $this->assertFalse($days['2026-10-14']['no_clock_out']);
        $this->assertSame('none', $days['2026-10-15']['status']);
        $this->assertSame('2026-10-14', $report['covered_until']);
    }

    public function test_terminals_are_added_and_removed_on_the_attendance_page(): void
    {
        $this->asAdmin();

        $page = Livewire::test(AttendanceComponent::class)
            ->set('showDevices', true)
            ->assertSee('Front door')
            ->assertSee('Nothing received yet')
            ->assertSee('/api/v1/attendance/push/'.$this->device->token)
            ->set('deviceName', 'Back office')
            ->call('addDevice')
            ->assertHasNoErrors()
            ->assertSee('Back office');
        $this->assertSame(2, AttendanceDevice::count());
        $this->assertSame(48, strlen(AttendanceDevice::where('name', 'Back office')->value('token')));

        $page->call('removeDevice', $this->device->id)->assertDontSee($this->device->token);
        $this->assertSame(['Back office'], AttendanceDevice::pluck('name')->all());
    }

    public function test_a_terminal_that_goes_silent_is_noticed_once_the_morning_is_under_way(): void
    {
        $this->asAdmin();
        $silent = fn (string $time) => AttendanceDevice::silent(Carbon::parse($time))->pluck('name')->all();
        $this->device->forceFill(['last_seen_at' => '2026-10-12 17:02:00'])->save();

        // Nobody followed on the terminal yet, so nobody is expected to punch.
        $this->assertSame([], $silent('2026-10-13 12:00'));
        $ama = AttendanceStaff::create(['employee_no' => '001', 'name' => 'Ama Mensah']);
        $this->device->forceFill(['last_seen_at' => null])->save();

        // Never connected: that is a setup still to finish, not a failure.
        $this->assertSame([], $silent('2026-10-13 12:00'));

        // Last heard from on Monday the 12th at 5:02 PM. Tuesday opens at 8.
        $this->device->forceFill(['last_seen_at' => '2026-10-12 17:02:00'])->save();
        $this->assertSame([], $silent('2026-10-13 09:59'));
        $this->assertSame(['Front door'], $silent('2026-10-13 10:00'));
        $this->device->forceFill(['last_seen_at' => '2026-10-13 08:05:00'])->save();
        $this->assertSame([], $silent('2026-10-13 16:00'));

        // Quiet over a weekend is expected; quiet on Monday morning is not.
        $this->device->forceFill(['last_seen_at' => '2026-10-16 17:00:00'])->save();
        $this->assertSame([], $silent('2026-10-18 15:00'));
        $this->assertSame([], $silent('2026-10-19 09:00'));
        $this->assertSame(['Front door'], $silent('2026-10-19 10:30'));

        // Nor on a holiday, or once the terminal is switched off here.
        AttendanceHoliday::create(['date' => '2026-10-19', 'name' => 'Founders day']);
        $this->assertSame([], $silent('2026-10-19 10:30'));
        $this->assertSame(['Front door'], $silent('2026-10-20 10:30'));
        // Nor when everyone followed is on leave that day.
        $leave = AttendanceLeave::create(['attendance_staff_id' => $ama->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-21', 'type' => 'annual']);
        $this->assertSame([], $silent('2026-10-20 10:30'));
        $leave->delete();
        $this->device->forceFill(['is_active' => false])->save();
        $this->assertSame([], $silent('2026-10-20 10:30'));
    }

    public function test_the_owner_is_emailed_once_about_a_silent_terminal_and_the_page_warns(): void
    {
        Mail::fake();
        $this->clinic->update(['billing_email' => 'owner@clinic-a.test']);
        $this->asAdmin();
        AttendanceStaff::create(['employee_no' => '001', 'name' => 'Ama Mensah']);
        $this->device->forceFill(['last_seen_at' => '2026-10-16 17:00:00'])->save();
        $alerts = app(OwnerAlerts::class);

        $this->assertSame(0, $alerts->terminalsSilent($this->clinic, Carbon::parse('2026-10-19 09:00')));
        Mail::assertNothingSent();
        // Monday, two hours after opening; and again an hour later and the next day, with nothing new to say.
        foreach (['2026-10-19 10:00', '2026-10-19 11:00', '2026-10-20 10:00'] as $time) {
            $this->assertSame(1, $alerts->terminalsSilent($this->clinic, Carbon::parse($time)));
        }
        Mail::assertSent(OwnerNoticeMail::class, 1);
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo('owner@clinic-a.test') && $mail->heading === 'Attendance terminal not sending'
            && str_contains($mail->intro, 'from Front door since Fri 16 Oct, 5:00 PM') && $mail->details['Branch'] === 'Main');

        // It comes back, then stops again: a new silence, a new email.
        $this->device->forceFill(['last_seen_at' => '2026-10-20 12:00:00'])->save();
        $this->assertSame(0, $alerts->terminalsSilent($this->clinic, Carbon::parse('2026-10-20 15:00')));
        $this->assertSame(1, $alerts->terminalsSilent($this->clinic, Carbon::parse('2026-10-21 10:00')));
        Mail::assertSent(OwnerNoticeMail::class, 2);

        $this->pretendItIs('2026-10-21 11:00:00');
        $this->asAdmin();
        Livewire::test(AttendanceComponent::class)->assertSee('has sent no punches since Tue 20 Oct, 12:00 PM');
        $this->device->forceFill(['last_seen_at' => now()])->save();
        Livewire::test(AttendanceComponent::class)->assertDontSee('has sent no punches since');
        Carbon::setTestNow();
    }

    /** Set the clock, with the clinic's subscription running whatever the real date is. */
    private function pretendItIs(string $time): void
    {
        DB::table('clinic_subscriptions')->update(['current_period_starts_at' => '2026-01-01 00:00:00', 'current_period_ends_at' => '2036-01-01 00:00:00']);
        Carbon::setTestNow($time);
    }

    public function test_the_board_shows_where_everyone_is_right_now(): void
    {
        // Wednesday the 14th. Ama is in, Kofi came late and stepped out, Esi has not come, Yaw is on leave.
        $this->push($this->event('001', '2026-10-14T07:58:00+00:00'))->assertOk();
        $this->push($this->event('002', '2026-10-14T08:40:00+00:00', ['name' => 'Kofi Boateng']))->assertOk();
        $this->push($this->event('002', '2026-10-14T09:30:00+00:00', ['name' => 'Kofi Boateng']))->assertOk();
        $this->asAdmin();
        AttendanceStaff::create(['employee_no' => '004', 'name' => 'Esi Owusu']);
        $yaw = AttendanceStaff::create(['employee_no' => '005', 'name' => 'Yaw Darko']);
        AttendanceLeave::create(['attendance_staff_id' => $yaw->id, 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-14', 'type' => 'sick']);
        AttendanceStaff::create(['employee_no' => '006', 'name' => 'Not Followed', 'is_tracked' => false]);

        $board = fn (string $time) => collect(app(AttendanceAnalyzer::class)->today(CarbonImmutable::parse($time)))
            ->mapWithKeys(fn ($person) => [$person['staff']->name => $person['state'].($person['left'] ? ' left' : '').' '.$person['time']])->all();

        $this->assertSame(['Ama Mensah' => 'in 07:58', 'Esi Owusu' => 'missing ', 'Kofi Boateng' => 'out 09:30', 'Yaw Darko' => 'leave '], $board('2026-10-14 10:00'));
        // Before the grace period is up, someone not in yet is not late.
        $this->assertSame('due ', $board('2026-10-14 08:10')['Esi Owusu']);
        // After closing time, the last punch out is them gone for the day.
        $this->assertSame('out left 09:30', $board('2026-10-14 17:30')['Kofi Boateng']);
        // The next day nobody has punched yet; a Saturday is a day off.
        $this->assertSame('due ', $board('2026-10-15 07:00')['Ama Mensah']);
        $this->assertSame('off ', $board('2026-10-17 10:00')['Ama Mensah']);

        $this->pretendItIs('2026-10-14 10:00:00');
        $this->asAdmin();
        Livewire::test(AttendanceComponent::class)
            ->assertSee('Today, Wed 14 Oct')
            ->assertSee('In since 7:58 AM')
            ->assertSee('Out since 9:30 AM')
            ->assertSee('Expected by 8:00 AM')
            ->assertDontSee('Not Followed</span>', false)
            // Those away are a count until asked for.
            ->assertDontSee('Sick leave')->set('boardShow', 'away')->assertSee('Sick leave');
        Carbon::setTestNow();
    }

    public function test_one_clinics_terminal_cannot_be_removed_by_another(): void
    {
        $other = $this->activeClinic(['name' => 'Clinic B', 'slug' => 'clinic-b']);
        $otherBranch = $other->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $theirs = DB::table('attendance_devices')->insertGetId(['clinic_id' => $other->id, 'branch_id' => $otherBranch->id, 'name' => 'Theirs', 'token' => str_repeat('x', 48)]);

        $this->asAdmin();
        Livewire::test(AttendanceComponent::class)->set('showDevices', true)->assertDontSee('Theirs')->call('removeDevice', $theirs);

        $this->assertTrue(AttendanceDevice::withoutGlobalScopes()->whereKey($theirs)->exists());
    }
}
