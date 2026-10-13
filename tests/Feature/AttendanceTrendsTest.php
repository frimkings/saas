<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\AttendanceStaff;
use App\Models\User;
use App\Services\Attendance\AttendanceTrends;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * Trends: the last six months side by side. September 2026 has 22 working days and starts on a
 * Tuesday; October's first full week is Monday the 5th to Friday the 9th.
 */
class AttendanceTrendsTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private AttendanceStaff $ama;

    private AttendanceStaff $kofi;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Carbon::setTestNow('2026-10-10 12:00:00');
        config()->set('tenancy.enabled', true);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $clinic = $this->activeClinic(['name' => 'Clinic A', 'slug' => 'clinic-a']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $admin = User::factory()->create();
        $admin->assignRole($role);
        $clinic->users()->attach($admin, ['status' => 'active', 'clinic_role' => 'Super Admin', 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $branch->id, 'user_id' => $admin->id, 'status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $admin->id, 'role_id' => $role->id]);
        $this->actingAs($admin);
        app(TenantContext::class)->set($admin, $clinic, $branch, [$branch->id]);

        AttendanceDevice::create(['name' => 'Front desk'])->forceFill(['first_event_at' => '2026-09-01 07:00:00', 'last_event_at' => '2026-10-10 11:00:00', 'last_seen_at' => now()])->save();
        $this->ama = AttendanceStaff::create(['employee_no' => '001', 'name' => 'Ama Owusu', 'is_tracked' => true]);
        $this->kofi = AttendanceStaff::create(['employee_no' => '002', 'name' => 'Kofi Boateng', 'is_tracked' => true]);

        // September: both in every working day; Kofi late on one Monday.
        // October 5 to 9: Ama always on time; Kofi late on Monday and Tuesday and absent on Friday.
        $kofi = ['2026-09-07' => '08:40', '2026-10-05' => '09:00', '2026-10-06' => '08:30', '2026-10-09' => null];
        for ($day = CarbonImmutable::parse('2026-09-01'); $day->lte(CarbonImmutable::parse('2026-10-09')); $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            if ($day->isWeekend() || in_array($key, ['2026-10-01', '2026-10-02'], true)) {
                continue;
            }
            foreach ([[$this->ama, '08:00'], [$this->kofi, array_key_exists($key, $kofi) ? $kofi[$key] : '08:00']] as [$staff, $in]) {
                if ($in) {
                    AttendancePunch::create(['attendance_staff_id' => $staff->id, 'punched_at' => "{$key} {$in}:00", 'source' => 'terminal']);
                    AttendancePunch::create(['attendance_staff_id' => $staff->id, 'punched_at' => "{$key} 17:00:00", 'source' => 'terminal']);
                }
            }
        }
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_months_people_and_weekdays_are_compared(): void
    {
        $trends = app(AttendanceTrends::class)->months(CarbonImmutable::parse('2026-10-10'));
        $this->assertSame(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], array_keys($trends['months']));
        $this->assertSame([0, null], [$trends['months']['2026-08']['expected'], $trends['months']['2026-08']['rate']]);

        $september = $trends['months']['2026-09'];
        $this->assertSame(['Sep 2026', 44, 44, 1, 0, 100, 40], [$september['label'], $september['expected'], $september['present'], $september['late'], $september['absent'], $september['rate'], $september['late_minutes']]);
        // October so far: the 1st and 2nd nobody came (two absences each), then the week of the 5th.
        $october = $trends['months']['2026-10'];
        $this->assertSame([14, 9, 2, 5, 64], [$october['expected'], $october['present'], $october['late'], $october['absent'], $october['rate']]);

        // Kofi has the most trouble and is getting worse: 1 in September, 5 in October.
        $this->assertSame(['Kofi Boateng', 'Ama Owusu'], array_map(fn ($person) => $person['staff']->name, $trends['people']));
        $this->assertSame([3, 3, 4], [$trends['people'][0]['late'], $trends['people'][0]['absent'], $trends['people'][0]['change']]);
        $this->assertSame(['late' => 1, 'absent' => 0, 'rate' => 100], $trends['people'][0]['months']['2026-09']);
        $this->assertSame([0, 2, 2], [$trends['people'][1]['late'], $trends['people'][1]['absent'], $trends['people'][1]['change']]);

        // Mondays: two late arrivals (7 September, 5 October) out of ten times in.
        $this->assertSame(['late' => 2, 'present' => 10], $trends['weekdays'][1]);
        $this->assertSame(0, $trends['weekdays'][3]['late']);
    }

    public function test_the_page_shows_trends(): void
    {
        Livewire::withQueryParams(['view' => 'trends'])->test(AttendanceComponent::class)->assertSet('view', 'trends')
            ->assertSee('Month by month')->assertSee('Sep 2026')->assertSee('Lateness by weekday')->assertSee('Person by person')
            ->assertSee('4 more than the month before')->assertSee('Nothing recorded')
            ->set('view', 'summary')->assertDontSee('Month by month')
            ->set('view', 'grid')->assertDontSee('Month by month')->assertSee('Click a day');
    }
}
