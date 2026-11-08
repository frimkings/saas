<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Mail\OwnerNoticeMail;
use App\Models\AttendanceDevice;
use App\Models\AttendanceSetting;
use App\Models\AttendanceStaff;
use App\Models\Clinic;
use App\Models\User;
use App\Services\Attendance\AttendanceCorrections;
use App\Services\Attendance\AttendancePunchEmails;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * Clock-in emails: the owner ticks them under Set up and hears of each punch from a terminal or
 * phone as it happens (or in an evening summary); staff can be sent their own times. 12 October
 * 2026 is a Monday; the branch works 8 to 5 with 15 minutes' grace.
 */
class AttendancePunchEmailsTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private User $admin;
    private User $kofi;
    private Clinic $clinic;
    private int $branchId;
    private AttendanceDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow('2026-10-12 07:00:00');
        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $this->clinic = $this->activeClinic(['name' => 'Clinic A', 'slug' => 'clinic-a']);
        $this->clinic->update(['billing_email' => 'owner@clinic-a.test']);
        $this->branchId = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true])->id;
        $this->admin = User::factory()->create(['name' => 'Ama Owusu']);
        $this->admin->assignRole($role);
        $this->kofi = User::factory()->create(['name' => 'Kofi Boateng', 'email' => 'kofi@clinic-a.test']);
        foreach ([$this->admin, $this->kofi] as $user) {
            $this->clinic->users()->attach($user, ['status' => 'active', 'clinic_role' => 'Super Admin', 'is_default' => true]);
            DB::table('branch_user')->insert(['branch_id' => $this->branchId, 'user_id' => $user->id, 'status' => 'active', 'is_default' => true]);
        }
        DB::table('branch_user_role')->insert(['branch_id' => $this->branchId, 'user_id' => $this->admin->id, 'role_id' => $role->id]);

        $this->asAdmin();
        $this->device = AttendanceDevice::create(['name' => 'Front door']);
        AttendanceStaff::create(['employee_no' => '002', 'name' => 'Kofi Boateng', 'user_id' => $this->kofi->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function asAdmin(): void
    {
        $this->actingAs($this->admin);
        app(TenantContext::class)->set($this->admin, $this->clinic, $this->clinic->branches()->findOrFail($this->branchId), [$this->branchId]);
    }

    private function settings(array $changes): void
    {
        $this->asAdmin();
        $settings = AttendanceSetting::current();
        $settings->fill($changes + ['punch_emails' => true, 'punch_emails_since' => '2026-10-01'])->save();
    }

    /** The terminal (not signed in) sends a punch at $time, a minute before now. */
    private function punch(string $time): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 '.$time)->addMinute());
        auth()->logout();
        app(TenantContext::class)->clear();
        $event = ['ipAddress' => '192.168.1.64', 'dateTime' => '2026-10-12T'.$time.':00+00:00', 'eventType' => 'AccessControllerEvent', 'eventState' => 'active',
            'AccessControllerEvent' => ['majorEventType' => 5, 'subEventType' => 75, 'name' => 'Kofi Boateng', 'employeeNoString' => '002', 'attendanceStatus' => 'undefined']];
        $this->call('POST', '/api/v1/attendance/push/'.$this->device->token, [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event))->assertOk();
    }

    private function sentTo(string $address): array
    {
        return Mail::sent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo($address))->values()->all();
    }

    public function test_the_owner_and_the_person_hear_of_each_punch_as_it_happens(): void
    {
        $this->settings(['punch_email_to' => ['hr@clinic-a.test']]);

        $this->punch('08:20');
        $owner = $this->sentTo('owner@clinic-a.test');
        $this->assertCount(1, $owner);
        $this->assertSame('Kofi Boateng clocked in at 8:20 AM', $owner[0]->heading);
        $this->assertSame(['Late by 20m', 'Front door terminal', 'Main'], [$owner[0]->details['Status'], $owner[0]->details['How'], $owner[0]->details['Branch']]);
        $this->assertCount(1, $this->sentTo('hr@clinic-a.test'));
        $staff = $this->sentTo('kofi@clinic-a.test');
        $this->assertCount(1, $staff);
        $this->assertSame('You clocked in at 8:20 AM', $staff[0]->heading);
        $this->assertArrayNotHasKey('Staff member', $staff[0]->details);

        // A second tap a minute later is the same punch: no more emails.
        $this->punch('08:21');
        $this->assertCount(1, $this->sentTo('owner@clinic-a.test'));

        $this->punch('16:00');
        $owner = $this->sentTo('owner@clinic-a.test');
        $this->assertCount(2, $owner);
        $this->assertSame('Kofi Boateng clocked out at 4:00 PM', $owner[1]->heading);
        $this->assertSame('1h before the end of the day', $owner[1]->details['Status']);
        $this->assertCount(2, $this->sentTo('kofi@clinic-a.test'));
    }

    public function test_nothing_goes_while_switched_off_or_for_punches_a_terminal_kept_while_cut_off(): void
    {
        $this->punch('08:00');
        Mail::assertNothingSent();

        $this->settings([]);
        // Sent long after it was made: the terminal was cut off.
        Carbon::setTestNow('2026-10-12 12:00:00');
        auth()->logout();
        app(TenantContext::class)->clear();
        $event = ['dateTime' => '2026-10-12T09:30:00+00:00', 'eventType' => 'AccessControllerEvent',
            'AccessControllerEvent' => ['majorEventType' => 5, 'subEventType' => 75, 'name' => 'Kofi Boateng', 'employeeNoString' => '002']];
        $this->call('POST', '/api/v1/attendance/push/'.$this->device->token, [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event))->assertOk();
        Mail::assertNothingSent();
    }

    public function test_only_late_or_early_reaches_the_owner_when_asked_and_staff_copies_can_be_off(): void
    {
        $this->settings(['punch_email_when' => 'late_early']);
        $this->punch('08:05');
        $this->assertCount(0, $this->sentTo('owner@clinic-a.test'));
        $this->assertCount(1, $this->sentTo('kofi@clinic-a.test'));
        $this->assertSame('On time', $this->sentTo('kofi@clinic-a.test')[0]->details['Status']);

        $this->settings(['punch_email_when' => 'late_early', 'punch_email_staff' => false]);
        $this->punch('15:00');
        $this->assertCount(1, $this->sentTo('owner@clinic-a.test'));
        $this->assertCount(1, $this->sentTo('kofi@clinic-a.test'));
    }

    public function test_the_evening_summary_goes_once_with_each_persons_day(): void
    {
        $this->settings(['punch_email_mode' => 'daily']);
        $this->punch('08:20');
        $this->punch('16:30');
        // In summary mode the owner gets nothing per punch; the person still gets their own.
        $this->assertCount(0, $this->sentTo('owner@clinic-a.test'));
        $this->assertCount(2, $this->sentTo('kofi@clinic-a.test'));

        $emails = app(AttendancePunchEmails::class);
        // Before 8 PM it is yesterday's summary that is due: a Sunday nobody worked, so nothing.
        $this->assertSame(0, $emails->sendSummaries($this->clinic, CarbonImmutable::parse('2026-10-12 19:00:00')));
        $this->assertCount(0, $this->sentTo('owner@clinic-a.test'));

        $this->assertSame(1, $emails->sendSummaries($this->clinic, CarbonImmutable::parse('2026-10-12 20:30:00')));
        $summary = $this->sentTo('owner@clinic-a.test');
        $this->assertCount(1, $summary);
        $this->assertSame('Clock-ins for Mon 12 Oct', $summary[0]->heading);
        $this->assertSame('In 8:20 AM (late by 20m) · Out 4:30 PM (30m early)', $summary[0]->details['Kofi Boateng']);
        $this->assertStringContainsString('1 came in at Main: 1 late, 1 left before the end of the day', $summary[0]->intro);

        // Once a day.
        $this->assertSame(0, $emails->sendSummaries($this->clinic, CarbonImmutable::parse('2026-10-12 21:30:00')));
        $this->assertSame(0, $emails->sendSummaries($this->clinic, CarbonImmutable::parse('2026-10-13 07:00:00')));
    }

    public function test_a_correction_tells_the_person_with_the_reason(): void
    {
        $this->settings([]);
        Carbon::setTestNow('2026-10-12 18:00:00');
        $staff = AttendanceStaff::query()->where('employee_no', '002')->firstOrFail();
        app(AttendanceCorrections::class)->add($staff, CarbonImmutable::parse('2026-10-12 17:05:00'), 'Forgot to punch out', CarbonImmutable::now());
        app()->terminate();

        $mail = $this->sentTo('kofi@clinic-a.test');
        $this->assertCount(1, $mail);
        $this->assertSame('Your attendance was corrected', $mail[0]->heading);
        $this->assertSame(['Forgot to punch out', 'Ama Owusu'], [$mail[0]->details['Reason'], $mail[0]->details['By']]);
        $this->assertCount(0, $this->sentTo('owner@clinic-a.test'));
    }

    public function test_the_owner_sets_them_up_and_a_manager_cannot(): void
    {
        Livewire::test(AttendanceComponent::class)->assertSee('Clock-in emails')->call('openPunchEmails')
            ->assertSee('owner@clinic-a.test')
            ->set('punchEmailsOn', true)->set('punchEmailMode', 'daily')->set('punchEmailWhen', 'late_early')
            ->set('punchEmailTo', 'hr@clinic-a.test, not-an-email')->call('savePunchEmails')->assertHasErrors(['punchEmailTo'])
            ->set('punchEmailTo', 'HR@clinic-a.test; boss@clinic-a.test')->set('punchEmailStaff', false)->call('savePunchEmails')->assertHasNoErrors();

        $settings = AttendanceSetting::current();
        $this->assertSame([true, '2026-10-12', 'daily', 'late_early', ['hr@clinic-a.test', 'boss@clinic-a.test'], false],
            [$settings->punch_emails, $settings->punch_emails_since->format('Y-m-d'), $settings->punch_email_mode, $settings->punch_email_when, $settings->punch_email_to, $settings->punch_email_staff]);

        $manager = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $this->kofi->assignRole($manager);
        DB::table('branch_user_role')->insert(['branch_id' => $this->branchId, 'user_id' => $this->kofi->id, 'role_id' => $manager->id]);
        $this->actingAs($this->kofi);
        app(TenantContext::class)->set($this->kofi, $this->clinic, $this->clinic->branches()->findOrFail($this->branchId), [$this->branchId]);
        Livewire::test(AttendanceComponent::class)->assertDontSee('Clock-in emails')->call('openPunchEmails')->assertForbidden();
    }
}
