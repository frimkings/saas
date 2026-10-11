<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\AttendanceStaff;
use App\Models\Clinic;
use App\Models\User;
use App\Services\Attendance\AttendanceAnalyzer;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
