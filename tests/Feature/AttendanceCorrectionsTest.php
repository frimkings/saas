<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\AttendanceStaff;
use App\Models\AuditTrail;
use App\Models\Clinic;
use App\Models\User;
use App\Services\Attendance\AttendanceAnalyzer;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** Managers correct punches; the terminal's own record is kept, and the day is counted from the corrected punches. */
class AttendanceCorrectionsTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private User $admin;
    private AttendanceStaff $ama;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Carbon::setTestNow('2026-10-31 12:00:00');
        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $clinic = $this->activeClinic(['name' => 'Clinic A', 'slug' => 'clinic-a']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $this->admin = User::factory()->create(['name' => 'Grace Manager']);
        $this->admin->assignRole($role);
        $clinic->users()->attach($this->admin, ['status' => 'active', 'clinic_role' => 'Super Admin', 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $branch->id, 'user_id' => $this->admin->id, 'status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $this->admin->id, 'role_id' => $role->id]);
        $this->actingAs($this->admin);
        app(TenantContext::class)->set($this->admin, $clinic, $branch, [$branch->id]);

        // A terminal that has been sending punches all month, so October's days are judged.
        AttendanceDevice::create(['name' => 'Front desk'])->forceFill(['first_event_at' => '2026-10-01 07:00:00', 'last_event_at' => '2026-10-31 11:00:00', 'last_seen_at' => now()])->save();
        $this->ama = AttendanceStaff::create(['employee_no' => '001', 'name' => 'Ama Owusu', 'is_tracked' => true]);
        // Tuesday 20 October: clocked in, forgot to clock out. A stray double punch at 12:30.
        foreach (['2026-10-20 08:02:00', '2026-10-20 12:30:00'] as $at) {
            AttendancePunch::create(['attendance_staff_id' => $this->ama->id, 'punched_at' => $at, 'source' => 'terminal']);
        }
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function day(): array
    {
        $report = app(AttendanceAnalyzer::class)->range(CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-20'));

        return collect($report['rows'])->first(fn ($row) => $row['staff']->id === $this->ama->id)['days']['2026-10-20'];
    }

    public function test_a_missed_punch_is_added_a_time_changed_and_a_mistake_removed_keeping_the_originals(): void
    {
        $page = Livewire::test(AttendanceComponent::class)->call('openPunches', $this->ama->id, '2026-10-20')
            ->assertSet('showPunches', true)->assertSee('Correct punches')->assertSee('8:02 AM')->assertSee('12:30 PM');

        // A reason is required, and the future is refused.
        $page->set('punchTime', '17:00')->call('savePunch')->assertHasErrors(['punchReason']);
        $page->set('punchTime', '17:00')->set('punchReason', 'Forgot to clock out, left at 5 PM')->call('savePunch')->assertHasNoErrors();
        $added = AttendancePunch::where('punched_at', '2026-10-20 17:00:00')->firstOrFail();
        $this->assertSame(['manual', $this->admin->id, 'Forgot to clock out, left at 5 PM'], [$added->source, $added->created_by, $added->reason]);
        $page->set('punchTime', '17:00')->set('punchReason', 'Trying the same time again')->call('savePunch')->assertHasErrors(['punchTime']);

        // Remove the stray 12:30 punch: it is voided, not deleted.
        $stray = AttendancePunch::where('punched_at', '2026-10-20 12:30:00')->firstOrFail();
        $page->set('punchReason', '')->call('removePunch', $stray->id)->assertHasErrors(['punchReason']);
        $page->set('punchReason', 'Punched twice by mistake')->call('removePunch', $stray->id)->assertHasNoErrors();
        $this->assertNull(AttendancePunch::find($stray->id));
        $voided = AttendancePunch::withVoided()->findOrFail($stray->id);
        $this->assertSame([$this->admin->id, 'Punched twice by mistake'], [$voided->voided_by, $voided->void_reason]);
        $this->assertNotNull($voided->voided_at);

        // Change 8:02 to 7:55: the original is voided and the new punch points back at it.
        $in = AttendancePunch::where('punched_at', '2026-10-20 08:02:00')->firstOrFail();
        $page->call('editPunch', $in->id)->assertSet('punchTime', '08:02')
            ->set('punchTime', '07:55')->set('punchReason', 'Terminal clock was seven minutes fast')->call('savePunch')->assertHasNoErrors();
        $moved = AttendancePunch::where('punched_at', '2026-10-20 07:55:00')->firstOrFail();
        $this->assertSame($in->id, $moved->replaces_id);
        $this->assertNotNull(AttendancePunch::withVoided()->findOrFail($in->id)->voided_at);

        // The day is now counted from the corrected punches: in 7:55, out 5:00, nothing missing.
        $day = $this->day();
        $this->assertSame(['07:55', '17:00', false, 545], [$day['in'], $day['out'], $day['no_clock_out'], $day['minutes_worked']]);
        // The window shows the history, removed punches included, with who and why.
        $page->assertSee('Removed by Grace Manager')->assertSee('Punched twice by mistake')->assertSee('Added by Grace Manager');

        // Each correction is in the audit trail, even without the audit extra.
        $this->assertSame(['attendance.punch_added', 'attendance.punch_removed', 'attendance.punch_changed'], AuditTrail::orderBy('id')->pluck('event')->all());
    }

    public function test_a_punch_cannot_be_in_the_future_and_a_removed_time_can_be_brought_back(): void
    {
        $page = Livewire::test(AttendanceComponent::class)->call('openPunches', $this->ama->id, '2026-10-31');
        $page->set('punchTime', '18:00')->set('punchReason', 'Not happened yet')->call('savePunch')->assertHasErrors(['punchTime']);

        $page = Livewire::test(AttendanceComponent::class)->call('openPunches', $this->ama->id, '2026-10-20');
        $stray = AttendancePunch::where('punched_at', '2026-10-20 12:30:00')->firstOrFail();
        $page->set('punchReason', 'Removed in error')->call('removePunch', $stray->id);
        $page->set('punchTime', '12:30')->set('punchReason', 'It was a real punch after all')->call('savePunch')->assertHasNoErrors();
        $this->assertSame(1, AttendancePunch::withVoided()->where('punched_at', '2026-10-20 12:30:00')->count());
        $this->assertNull(AttendancePunch::findOrFail($stray->id)->voided_at);

        // Uploading the report again never brings a removed punch back or doubles a corrected one.
        $page->set('punchReason', 'Removed again')->call('removePunch', $stray->id);
        DB::table('attendance_punches')->insertOrIgnore(['clinic_id' => $this->ama->clinic_id, 'branch_id' => $this->ama->branch_id,
            'attendance_staff_id' => $this->ama->id, 'punched_at' => '2026-10-20 12:30:00', 'source' => 'report']);
        $this->assertNull(AttendancePunch::find($stray->id));
    }

    public function test_only_managers_correct_punches(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff);
        Livewire::test(AttendanceComponent::class)->assertForbidden();
    }
}
