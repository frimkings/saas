<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Livewire\UserProfileComponent;
use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\AttendanceStaff;
use App\Models\User;
use App\Services\Attendance\AttendanceAnalyzer;
use App\Services\Attendance\AttendancePhoneClock;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * Staff punch from their own phone when it is within the branch's distance of the branch. The
 * branch here is at 5.6037, -0.1870; 0.0003 degrees of latitude north of it is about 33 metres.
 * October 2026: the 20th is a Tuesday.
 */
class AttendancePhoneClockTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private User $admin;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 07:30:00');
        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $clinic = $this->activeClinic(['name' => 'Clinic A', 'slug' => 'clinic-a']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $this->admin = User::factory()->create(['name' => 'Ama Owusu']);
        $this->admin->assignRole($role);
        $this->other = User::factory()->create(['name' => 'Kofi Boateng']);
        foreach ([$this->admin, $this->other] as $user) {
            $clinic->users()->attach($user, ['status' => 'active', 'clinic_role' => 'Super Admin', 'is_default' => true]);
            DB::table('branch_user')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'status' => 'active', 'is_default' => true]);
        }
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $this->admin->id, 'role_id' => $role->id]);
        $this->actingAs($this->admin);
        app(TenantContext::class)->set($this->admin, $clinic, $branch, [$branch->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_manager_switches_it_on_and_adds_people_who_are_not_on_a_terminal(): void
    {
        $this->assertSame(33, AttendancePhoneClock::distance(5.6040, -0.1870, 5.6037, -0.1870));
        $this->assertSame(['420 m', '2.4 km'], [AttendancePhoneClock::metres(420), AttendancePhoneClock::metres(2350)]);

        Livewire::test(AttendanceComponent::class)->assertSee('Phone clock-in')->call('openPhone')->assertSet('phoneRadius', '150')
            // On needs a place.
            ->set('phoneOn', true)->call('savePhone')->assertHasErrors(['phoneLatitude', 'phoneLongitude'])
            ->set('phoneLatitude', '95')->set('phoneLongitude', '-0.1870')->call('savePhone')->assertHasErrors(['phoneLatitude'])
            ->set('phoneLatitude', '5.6037')->set('phoneRadius', '10')->call('savePhone')->assertHasErrors(['phoneRadius'])
            ->set('phoneRadius', '200')->call('savePhone')->assertHasNoErrors()
            // Someone with an account and no terminal record.
            ->set('phoneUserId', (string) $this->other->id)->call('addPhonePerson')->assertHasNoErrors()->assertSee('Kofi Boateng')
            ->set('phoneUserId', (string) $this->other->id)->call('addPhonePerson')->assertHasErrors(['phoneUserId']);

        $settings = AttendanceSetting::current();
        $this->assertSame([true, '2026-10-20', 5.6037, -0.187, 200], [$settings->phone_clock_in, $settings->phone_clock_in_since->format('Y-m-d'),
            $settings->clock_in_latitude, $settings->clock_in_longitude, $settings->clock_in_radius]);
        $kofi = AttendanceStaff::where('user_id', $this->other->id)->firstOrFail();
        $this->assertSame(['APP-'.$this->other->id, 'Kofi Boateng', true], [$kofi->employee_no, $kofi->name, $kofi->is_tracked]);
    }

    public function test_a_punch_is_taken_only_near_the_branch_and_counts_on_attendance(): void
    {
        $ama = AttendanceStaff::create(['employee_no' => 'APP-1', 'name' => 'Ama Owusu', 'is_tracked' => true, 'user_id' => $this->admin->id]);
        $profile = fn () => Livewire::test(UserProfileComponent::class)->set('activeTab', 'attendance');

        // Off: no card, and a punch is refused.
        $profile()->assertDontSee('from this phone')->call('clockIn', 5.6040, -0.1870, 20)->assertDispatched('notify', fn ($name, $params) => $params['type'] === 'error');
        $this->assertSame(0, AttendancePunch::count());

        AttendanceSetting::current()->fill(['phone_clock_in' => true, 'phone_clock_in_since' => '2026-10-20', 'clock_in_latitude' => 5.6037, 'clock_in_longitude' => -0.1870, 'clock_in_radius' => 150])->save();
        Carbon::setTestNow('2026-10-21 08:05:00');

        // Two kilometres away, then a phone that cannot tell where it is: both refused.
        $profile()->assertSee('Clock in from this phone')
            ->call('clockIn', 5.6217, -0.1870, 15)->assertDispatched('notify', fn ($name, $params) => $params['type'] === 'error' && str_contains($params['message'], 'You are about 2.0 km from the branch'))
            ->call('clockIn', 5.6037, -0.1870, 800)->assertDispatched('notify', fn ($name, $params) => $params['type'] === 'error' && str_contains($params['message'], 'unsure where it is'))
            ->call('clockIn', 'nowhere', null)->assertDispatched('notify', fn ($name, $params) => $params['type'] === 'error');
        $this->assertSame(0, AttendancePunch::count());

        // At the door: taken, with where they were.
        $page = $profile()->call('clockIn', 5.6040, -0.1870, 20)->assertDispatched('notify', fn ($name, $params) => $params['type'] === 'success' && str_contains($params['message'], '33 m from the branch'))
            ->assertSee('Clock out from this phone')->assertSee('In 8:05 AM');
        $punch = AttendancePunch::firstOrFail();
        $this->assertSame(['phone', '2026-10-21 08:05:00', 33, 20, $ama->id], [$punch->source, $punch->punched_at->format('Y-m-d H:i:s'), $punch->distance, $punch->accuracy, $punch->attendance_staff_id]);
        $this->assertEqualsWithDelta(5.6040, (float) $punch->latitude, 0.00001);

        // Pressing again straight away is refused; at five it clocks them out.
        $page->call('clockIn', 5.6040, -0.1870, 20)->assertDispatched('notify', fn ($name, $params) => $params['type'] === 'error' && str_contains($params['message'], 'You punched at 8:05 AM'));
        Carbon::setTestNow('2026-10-21 17:02:00');
        $profile()->call('clockIn', 5.6036, -0.1871, 30);
        $this->assertSame(2, AttendancePunch::count());

        // Attendance: nothing counts before the first punch ever; the 21st is on time; the 22nd, with no
        // punch and the day over, is absent; today is still in progress.
        Carbon::setTestNow('2026-10-23 09:00:00');
        $days = collect(app(AttendanceAnalyzer::class)->range(CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-23'))['rows'])->first(fn ($row) => $row['staff']->id === $ama->id)['days'];
        $this->assertSame(['none', 'none', 'on_time', 'absent', 'none'], array_column($days, 'status'));
        $this->assertSame(['08:05', '17:02', 537], [$days['2026-10-21']['in'], $days['2026-10-21']['out'], $days['2026-10-21']['minutes_worked']]);

        // The manager sees where each punch was made.
        Livewire::test(AttendanceComponent::class)->set('from', '2026-10-19')->set('to', '2026-10-23')->call('openPunches', $ama->id, '2026-10-21')
            ->assertSee('Their phone')->assertSee('33 m from the branch')->assertSee('google.com/maps?q=5.604', false);
    }
}
