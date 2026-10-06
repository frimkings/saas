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

    public function test_usage_page_ranks_clinics_and_splits_the_bill_by_server_time(): void
    {
        $busy = $this->activeClinic(['name' => 'Busy Clinic', 'slug' => 'busy-clinic', 'deployment_mode' => 'hosted']);
        $row = fn (Clinic $c, string $date, int $requests, int $serverMs) => DB::table('clinic_usage_daily')->insert([
            'clinic_id' => $c->id, 'date' => $date, 'requests' => $requests, 'page_views' => $requests, 'bytes_out' => $requests * 2048,
            'server_ms' => $serverMs, 'db_ms' => intdiv($serverMs, 2), 'created_at' => now(), 'updated_at' => now()]);
        $row($this->clinic, today()->toDateString(), 10, 1000);
        $row($busy, today()->toDateString(), 50, 3000);
        $row($busy, today()->subMonthNoOverflow()->toDateString(), 999, 999_000); // outside "this month"

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
}
