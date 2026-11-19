<?php

namespace Tests\Feature;

use App\Models\{Clinic, Expense, User};
use App\Services\Finance\ClinicStatementService;
use App\Support\FinanceCache;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** Report figures for finished periods are kept, and refresh as soon as a row dated in them changes. */
class FinanceCacheTest extends TestCase
{
    use RefreshDatabase, GivesClinicsAccess;

    private Clinic $clinic;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        FinanceCache::enable(true);
        $this->clinic = $this->activeClinic(['name' => 'Clinic A', 'slug' => 'clinic-a', 'deployment_mode' => 'hosted']);
        $branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $this->owner = User::factory()->create();
        $this->clinic->users()->attach($this->owner->id, ['status' => 'active', 'is_default' => true]);
        app(TenantContext::class)->set($this->owner, $this->clinic, $branch, [$branch->id]);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        FinanceCache::enable(false);
        parent::tearDown();
    }

    private function expense(string $date, float $amount): Expense
    {
        return Expense::create(['expense_date' => $date, 'description' => 'Rent', 'amount' => $amount, 'payment_method' => 'cash',
            'recorded_by' => $this->owner->id, 'business_line' => 'clinic']);
    }

    private function lastMonth(): array
    {
        return [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()];
    }

    public function test_a_finished_period_is_worked_out_once(): void
    {
        [$from, $to] = $this->lastMonth();
        $runs = 0;
        $compute = function () use (&$runs) { $runs++; return ['total' => 10]; };

        FinanceCache::remember('test', $from, $to, $compute);
        FinanceCache::remember('test', $from, $to, $compute);
        $this->assertSame(1, $runs);

        // A different branch or kind is kept apart.
        FinanceCache::remember('other', $from, $to, $compute);
        $this->assertSame(2, $runs);
    }

    public function test_a_period_that_includes_today_is_always_live(): void
    {
        $runs = 0;
        $compute = function () use (&$runs) { $runs++; return 1; };
        FinanceCache::remember('test', now()->startOfMonth(), today(), $compute);
        FinanceCache::remember('test', now()->startOfMonth(), today(), $compute);
        $this->assertSame(2, $runs);
    }

    public function test_changing_a_row_dated_in_the_period_refreshes_the_statement(): void
    {
        [$from, $to] = $this->lastMonth();
        $service = app(ClinicStatementService::class);
        $expense = $this->expense($from->copy()->addDays(3)->toDateString(), 100);
        $before = $service->statement($from, $to);

        // Back-dated: added today, dated last month.
        $this->expense($from->copy()->addDays(5)->toDateString(), 40);
        $after = $service->statement($from, $to);
        $this->assertNotEquals($before, $after, 'a back-dated expense shows at once');

        // Corrected: the amount changes on a last-month row.
        $expense->update(['amount' => 150]);
        $corrected = $service->statement($from, $to);
        $this->assertNotEquals($after, $corrected);

        // Moved out of the period: both months refresh.
        $expense->update(['expense_date' => today()->toDateString()]);
        $this->assertNotEquals($corrected, $service->statement($from, $to));

        // Deleted.
        $snapshot = $service->statement($from, $to);
        Expense::query()->get()->each->delete();
        $this->assertNotEquals($snapshot, $service->statement($from, $to));
    }

    public function test_todays_work_does_not_refresh_past_months(): void
    {
        [$from, $to] = $this->lastMonth();
        $runs = 0;
        $compute = function () use (&$runs) { $runs++; return 1; };
        FinanceCache::remember('test', $from, $to, $compute);
        $this->expense(today()->toDateString(), 25);
        FinanceCache::remember('test', $from, $to, $compute);
        $this->assertSame(1, $runs);
    }
}
