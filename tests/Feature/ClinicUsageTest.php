<?php

namespace Tests\Feature;

use App\Http\Middleware\MeterClinicUsage;
use App\Livewire\Platform\ClinicUsageComponent;
use App\Models\{Clinic, User};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** Platform → Usage: per-clinic request metering, the nightly storage count and the usage page. */
class ClinicUsageTest extends TestCase
{
    use RefreshDatabase, GivesClinicsAccess;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true, 'tenancy.usage_metering' => null]);
        $this->clinic = $this->activeClinic(['name' => 'Usage Clinic', 'slug' => 'usage-clinic', 'deployment_mode' => 'hosted']);
    }

    private function withClinicContext(Clinic $clinic): void
    {
        $branch = $clinic->branches()->firstOrCreate(['code' => 'MAIN'], ['name' => 'Main', 'is_default' => true, 'is_active' => true]);
        app(TenantContext::class)->set(User::factory()->create(), $clinic, $branch, [$branch->id]);
    }

    /** Runs one request through the middleware the way the kernel does: handle, then terminate. */
    private function meter(Request $request, string $body = 'hello'): void
    {
        $middleware = new MeterClinicUsage();
        $response = $middleware->handle($request, function () use ($body) {
            DB::table('clinics')->count();

            return new Response($body);
        });
        $middleware->terminate($request, $response);
    }

    private function today(int $clinicId): ?object
    {
        return DB::table('clinic_usage_daily')->where('clinic_id', $clinicId)->where('date', today()->toDateString())->first();
    }

    public function test_metering_is_first_in_the_web_group(): void
    {
        $this->assertSame(MeterClinicUsage::class, app(\App\Http\Kernel::class)->getMiddlewareGroups()['web'][0]);
    }

    public function test_clinic_requests_add_up_in_one_row_per_day(): void
    {
        $this->withClinicContext($this->clinic);

        $this->meter(Request::create('/patients', 'GET'));
        $action = Request::create('/livewire/update', 'POST', server: ['HTTP_X_LIVEWIRE' => '1', 'HTTP_CONTENT_LENGTH' => '300']);
        $this->meter($action, str_repeat('x', 1000));

        $row = $this->today($this->clinic->id);
        $this->assertSame(1, DB::table('clinic_usage_daily')->count());
        $this->assertSame(2, (int) $row->requests);
        $this->assertSame(1, (int) $row->page_views);
        $this->assertSame(1, (int) $row->actions);
        $this->assertSame(1005, (int) $row->bytes_out);
        $this->assertSame(300, (int) $row->bytes_in);
        $this->assertGreaterThanOrEqual(2, (int) $row->db_queries, 'The query inside each request is counted.');
        $this->assertGreaterThan(0, (int) $row->server_ms);
    }

    public function test_requests_without_a_clinic_or_with_metering_off_are_not_counted(): void
    {
        $this->meter(Request::create('/login', 'GET'));
        $this->assertSame(0, DB::table('clinic_usage_daily')->count());

        $this->withClinicContext($this->clinic);
        config(['tenancy.usage_metering' => 'false']);
        $this->meter(Request::create('/patients', 'GET'));
        $this->assertSame(0, DB::table('clinic_usage_daily')->count());

        // Offline installs (tenancy off) are off unless switched on.
        config(['tenancy.enabled' => false, 'tenancy.usage_metering' => null]);
        $this->meter(Request::create('/patients', 'GET'));
        $this->assertSame(0, DB::table('clinic_usage_daily')->count());
    }

    public function test_storage_snapshot_counts_rows_per_clinic_without_touching_request_counts(): void
    {
        $other = $this->activeClinic(['name' => 'Other Clinic', 'slug' => 'other-clinic', 'deployment_mode' => 'hosted']);
        $this->withClinicContext($this->clinic);
        $this->meter(Request::create('/patients', 'GET'));

        $this->artisan('usage:snapshot-storage')->assertSuccessful();
        $this->artisan('usage:snapshot-storage')->assertSuccessful();

        $mine = $this->today($this->clinic->id);
        $this->assertSame(1, (int) $mine->requests);
        // Its subscription and branch at least, counted once even though the snapshot ran twice.
        $this->assertGreaterThanOrEqual(2, (int) $mine->stored_rows);
        $this->assertLessThan(20, (int) $mine->stored_rows);
        $this->assertGreaterThanOrEqual(1, (int) $this->today($other->id)->stored_rows);
        $this->assertSame(0, (int) $this->today($other->id)->requests);
    }

    public function test_staff_are_counted_once_a_day_on_page_views(): void
    {
        $this->withClinicContext($this->clinic);
        $this->meter(Request::create('/patients', 'GET'));
        $this->meter(Request::create('/patients/5', 'GET'));
        $this->meter(Request::create('/livewire/update', 'POST', server: ['HTTP_X_LIVEWIRE' => '1']));

        $this->assertSame(1, (int) $this->today($this->clinic->id)->active_staff);
        $this->assertSame(1, DB::table('clinic_usage_users')->count());

        $this->withClinicContext($this->clinic); // a second staff member
        $this->meter(Request::create('/patients', 'GET'));
        $this->assertSame(2, (int) $this->today($this->clinic->id)->active_staff);
    }

    public function test_scheduled_runs_count_as_the_clinics_scheduled_work(): void
    {
        $branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $staff = User::factory()->create();
        $branch->users()->attach($staff->id, ['status' => 'active', 'is_default' => true]);

        $this->artisan('tenancy:run-scheduled', ['tenantCommand' => 'inspire'])->assertSuccessful();
        $this->artisan('tenancy:run-scheduled', ['tenantCommand' => 'inspire'])->assertSuccessful();

        $row = $this->today($this->clinic->id);
        $this->assertSame(2, (int) $row->job_runs);
        $this->assertSame(0, (int) $row->requests, 'Scheduled work is not a page request.');
        $this->assertSame(0, (int) $row->active_staff);
    }

    public function test_storage_snapshot_adds_up_each_clinics_uploaded_files(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $other = $this->activeClinic(['name' => 'Other Clinic', 'slug' => 'other-clinic', 'deployment_mode' => 'hosted']);
        Storage::disk('public')->put('clinics/'.$this->clinic->uuid.'/branding/logo.png', str_repeat('a', 1200));
        Storage::disk('local')->put('clinics/'.$this->clinic->uuid.'/patients/1/documents/scan.pdf', str_repeat('b', 300));
        Storage::disk('public')->put('avatars/someone.png', str_repeat('c', 5000)); // not a clinic's

        $this->artisan('usage:snapshot-storage')->assertSuccessful();

        $this->assertSame(1500, (int) $this->today($this->clinic->id)->file_bytes);
        $this->assertSame(0, (int) $this->today($other->id)->file_bytes);
    }

    public function test_readiness_reports_whether_clinic_usage_is_being_counted(): void
    {
        $check = fn () => app(\App\Services\DeploymentReadinessService::class)->run(false)['checks']['usage_metering'];
        $this->assertFalse($check()['passed']);
        $this->assertSame('No clinic requests counted yet', $check()['detail']);

        $this->withClinicContext($this->clinic);
        $this->meter(Request::create('/patients', 'GET'));
        $this->assertTrue($check()['passed']);
    }

    public function test_usage_page_ranks_clinics_and_splits_the_bill_by_server_time(): void
    {
        $busy = $this->activeClinic(['name' => 'Busy Clinic', 'slug' => 'busy-clinic', 'deployment_mode' => 'hosted']);
        $row = fn (Clinic $c, string $date, int $requests, int $serverMs) => DB::table('clinic_usage_daily')->insert([
            'clinic_id' => $c->id, 'date' => $date, 'requests' => $requests, 'page_views' => $requests, 'bytes_out' => $requests * 2048,
            'server_ms' => $serverMs, 'db_ms' => intdiv($serverMs, 2), 'created_at' => now(), 'updated_at' => now()]);
        $row($this->clinic, today()->toDateString(), 10, 1000);
        $row($busy, today()->toDateString(), 50, 3000);
        $row($busy, today()->subMonthNoOverflow()->toDateString(), 999, 999_000); // outside "this month"
        // A third of Busy's time today was scheduled work: it still counts toward its share.
        DB::table('clinic_usage_daily')->where('clinic_id', $busy->id)->where('date', today()->toDateString())->update(['server_ms' => 2000, 'job_ms' => 1000, 'job_runs' => 3]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin)->get(route('platform.usage'))->assertOk()->assertSee('Clinic Usage')->assertSee('◷ Clinic Usage', false);
        $this->assertSame(0, DB::table('clinic_usage_daily')->where('requests', '>', 0)->whereNull('stored_rows')->where('clinic_id', '!=', $busy->id)
            ->where('clinic_id', '!=', $this->clinic->id)->count(), 'Platform pages have no clinic, so they are not metered.');

        Livewire::actingAs($admin)->test(ClinicUsageComponent::class)
            ->assertSeeInOrder(['Busy Clinic', 'Usage Clinic'])
            ->assertSee('75.0%')->assertSee('25.0%')
            ->set('hostingCost', '40')->assertSee('30.00')->assertSee('10.00')
            ->call('sortBy', 'name')->assertSeeInOrder(['Busy Clinic', 'Usage Clinic'])
            ->call('showClinic', $busy->id)->assertSee('Busy Clinic: day by day')->assertDontSee('999 (')
            ->set('period', 'last_month')->assertSee('999 (999 pages');
    }

    public function test_any_earlier_month_or_range_of_days_can_be_looked_at(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-12-15 10:00'));
        $row = fn (string $date, int $requests) => DB::table('clinic_usage_daily')->insert([
            'clinic_id' => $this->clinic->id, 'date' => $date, 'requests' => $requests, 'page_views' => $requests,
            'server_ms' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        $row('2026-10-05', 111);
        $row('2026-10-20', 222);
        $row('2026-11-10', 444);
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $page = Livewire::actingAs($admin)->test(ClinicUsageComponent::class)->set('period', 'month')
            ->assertSet('month', '2026-11')->assertSee('December 2026')->assertSee('October 2026')->assertSee('444');
        $this->assertSame(['2026-12', '2026-11', '2026-10'], array_keys($page->instance()->months()), 'from the first month with usage, newest first');

        $page->set('month', '2026-10')->assertSee('1 Oct 2026 – 31 Oct 2026')->assertSee('333')->assertDontSee('444')
            ->set('period', 'custom')->set('rangeFrom', '2026-10-15')->set('rangeTo', '2026-11-30')
            ->assertSee('15 Oct 2026 – 30 Nov 2026')->assertSee('666')
            // Dates the wrong way round are swapped, and nothing runs past today.
            ->set('rangeFrom', '2027-01-31')->set('rangeTo', '2026-11-01')->assertSee('1 Nov 2026 – 15 Dec 2026');
    }
}
