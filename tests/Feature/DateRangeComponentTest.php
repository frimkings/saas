<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** The shared <x-date-range> filter: presets per page type, limits, and the clinic's "today". */
class DateRangeComponentTest extends TestCase
{
    use RefreshDatabase;

    private function config(string $html): array
    {
        preg_match("/dateRange\\(JSON\\.parse\\('(.+?)'\\)\\)/", $html, $m);
        $this->assertNotEmpty($m, 'The picker config is rendered.');

        return json_decode(json_decode('"'.$m[1].'"'), true);
    }

    public function test_activity_filters_offer_recent_ranges_and_stop_at_today(): void
    {
        $config = $this->config(Blade::render('<x-date-range from="fromDate" to="toDate" clearable />'));

        $this->assertSame(['today', 'yesterday', 'last3', 'last7', 'last15', 'last30'], array_column($config['presets'], 0));
        $this->assertSame($config['today'], $config['max']);
        $this->assertTrue($config['clearable']);
        $this->assertSame(['fromDate', 'toDate'], [$config['from'], $config['to']]);
    }

    public function test_finance_and_upcoming_presets_have_no_upper_limit_unless_asked(): void
    {
        $finance = $this->config(Blade::render('<x-date-range from="from" to="to" presets="finance" />'));
        $this->assertContains('last_month', array_column($finance['presets'], 0));
        $this->assertNull($finance['max']);

        $upcoming = $this->config(Blade::render('<x-date-range from="a" to="b" presets="upcoming" />'));
        $this->assertSame(['today', 'tomorrow', 'next7', 'next30', 'this_month_full'], array_column($upcoming['presets'], 0));

        $pickup = $this->config(Blade::render('<x-date-range from="a" to="b" max="none" />'));
        $this->assertNull($pickup['max'], 'max="none" lifts the today limit on activity presets.');
    }

    public function test_today_is_the_clinics_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-25 22:30:00', 'UTC'));
        $clinic = Clinic::create(['name' => 'Far East', 'slug' => 'far-east', 'status' => 'active', 'default_timezone' => 'Pacific/Kiritimati']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        app(TenantContext::class)->set(User::factory()->create(), $clinic, $branch, [$branch->id]);

        $config = $this->config(Blade::render('<x-date-range from="a" to="b" />'));

        $this->assertSame('2026-09-26', $config['today'], 'UTC+14: already the 26th at the clinic.');
        Carbon::setTestNow();
    }
}
