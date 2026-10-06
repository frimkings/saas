<?php

namespace Tests\Feature;

use App\Livewire\ReportsComponent;
use App\Models\Patient;
use App\Models\Sales;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->actingAs($this->admin);

        $this->patient = Patient::factory()->create(['user_id' => $this->admin->id]);
    }

    private function makeSale(array $overrides = []): Sales
    {
        return Sales::create(array_merge([
            'user_id'        => $this->admin->id,
            'patient_id'     => $this->patient->id,
            'transaction_id' => 'TXN-' . strtoupper(bin2hex(random_bytes(4))),
            'total_amount'   => 100.00,
            'amount_paid'    => 100.00,
            'profit'         => 30.00,
            'payment_status' => 'paid',
            'is_refunded'    => false,
        ], $overrides));
    }

    // ── Access control ───────────────────────────────────────────────────

    public function test_admin_can_view_reports_component(): void
    {
        Livewire::test(ReportsComponent::class)
            ->assertStatus(200);
    }

    public function test_non_admin_gets_403(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->withoutExceptionHandling();

        Livewire::test(ReportsComponent::class);
    }

    // ── Filter bar ───────────────────────────────────────────────────────

    public function test_period_picker_switches_between_today_and_a_custom_range(): void
    {
        Livewire::test(ReportsComponent::class)
            ->assertSee('Refunded only')->assertSee('id="reports-refunds"', false)
            ->assertDontSee('Custom Range')
            ->set('fromDate', now()->subDays(6)->format('Y-m-d'))
            ->assertSet('activeTab', 'range')
            ->set('fromDate', now()->format('Y-m-d'))
            ->assertSet('activeTab', 'today');
    }

    public function test_refunds_filter_includes_shows_only_and_excludes_refunds(): void
    {
        $this->makeSale(['is_refunded' => true, 'refunded_at' => now()]);

        Livewire::test(ReportsComponent::class)
            ->set('refundMode', 'include')
            ->assertSet('showRefunded', true)
            ->assertSet('activeTab', 'today')
            ->set('refundMode', 'only')
            ->assertSet('activeTab', 'trash')
            ->assertSet('showRefunded', true)
            ->set('refundMode', 'exclude')
            ->assertSet('activeTab', 'today')
            ->assertSet('showRefunded', false)
            ->assertSet('fromDate', now()->format('Y-m-d'));
    }

    public function test_refresh_drops_cached_figures_so_a_new_sale_shows_at_once(): void
    {
        $this->makeSale();
        $component = Livewire::test(ReportsComponent::class);
        $before = $component->viewData('summary')['count'];

        $this->makeSale();
        $this->assertSame($before, Livewire::test(ReportsComponent::class)->viewData('summary')['count'], 'Figures are kept for 5 minutes.');

        $component->call('refreshData');
        $this->assertSame($before + 1, $component->viewData('summary')['count']);
    }

    public function test_pdf_link_and_export_use_the_screen_filters(): void
    {
        $paid = $this->makeSale(['total_amount' => 100]);
        $unpaid = $this->makeSale(['total_amount' => 70, 'amount_paid' => 0, 'payment_status' => 'unpaid']);

        Livewire::test(ReportsComponent::class)
            ->set('paymentStatus', 'unpaid')
            ->assertSee('payment_status=unpaid', false);

        $captured = null;
        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('download')->andReturn(response('pdf'));
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()
            ->withArgs(function ($view, $data) use (&$captured) { $captured = $data; return $view === 'reports.pdf-sales'; })
            ->andReturn($pdf);

        $this->get(route('reports.export.pdf', [
            'from' => now()->toDateString(), 'to' => now()->toDateString(), 'payment_status' => 'unpaid',
        ]))->assertOk();

        $ids = $captured['sales']->pluck('id')->all();
        $this->assertContains($unpaid->id, $ids);
        $this->assertNotContains($paid->id, $ids);
        $this->assertSame(['Payment: Unpaid'], $captured['applied']);

        $html = view('reports.pdf-sales', $captured + ['clinicSettings' => null])->render();
        $this->assertStringContainsString('Filters: Payment: Unpaid', $html);
    }

    public function test_previous_period_matches_the_picked_period(): void
    {
        $component = new ReportsComponent;
        $component->activeTab = 'today';
        $component->fromDate = $component->toDate = '2026-10-05';
        $this->assertSame(['2026-10-04', '2026-10-04'], array_slice($component->previousPeriod(), 0, 2));

        $component->activeTab = 'range';
        [$component->fromDate, $component->toDate] = ['2026-10-01', '2026-10-31'];
        $this->assertSame(['2026-09-01', '2026-09-30', 'vs Sep'], $component->previousPeriod());

        [$component->fromDate, $component->toDate] = ['2026-09-29', '2026-10-05'];
        $this->assertSame(['2026-09-22', '2026-09-28', 'vs previous week'], $component->previousPeriod());

        $component->activeTab = 'trash';
        $this->assertNull($component->previousPeriod());
    }

    public function test_cards_compare_with_the_day_before(): void
    {
        $this->makeSale(['total_amount' => 300]);
        $yesterday = $this->makeSale(['total_amount' => 200]);
        $yesterday->forceFill(['created_at' => now()->subDay()])->save();

        $component = Livewire::test(ReportsComponent::class);
        $this->assertSame(200.0, $component->viewData('previous')['total_sales']);
        $component->assertSee('+50%')->assertSee('vs yesterday');
    }

    public function test_chart_follows_the_payment_filter_and_refunds(): void
    {
        $this->makeSale(['total_amount' => 100]);
        $this->makeSale(['total_amount' => 40, 'amount_paid' => 0, 'payment_status' => 'unpaid']);
        $this->makeSale(['total_amount' => 25, 'is_refunded' => true, 'refunded_at' => now()]);

        $component = new ReportsComponent;
        $component->mount();
        $chart = fn () => array_sum((fn () => $this->buildChartPayload())->call($component)['revenue']);

        $this->assertSame(140.0, $chart());
        $component->paymentStatus = 'unpaid';
        $this->assertSame(40.0, $chart());
        $component->paymentStatus = '';
        $component->showRefunded = true;
        $this->assertSame(165.0, $chart());
    }

    public function test_every_report_tab_renders_with_a_sale(): void
    {
        $this->makeSale();
        $component = Livewire::test(ReportsComponent::class);

        foreach (['overview' => 'Top 5 Products', 'items' => 'Sales by Item', 'categories' => 'No category data for this period',
                  'payments' => 'Payment Breakdown', 'transactions' => 'Transactions'] as $view => $heading) {
            $component->call('switchAnalyticsView', $view)->assertOk()->assertSee($heading)->assertDontSee(' badge-', false);
        }
    }

    // ── Summary aggregation ──────────────────────────────────────────────

    public function test_summary_counts_sales_in_date_range(): void
    {
        $this->makeSale();
        $this->makeSale();
        $this->makeSale();

        $component = Livewire::test(ReportsComponent::class)
            ->set('fromDate', now()->format('Y-m-d'))
            ->set('toDate', now()->format('Y-m-d'));

        $this->assertGreaterThanOrEqual(3, $component->viewData('summary')['count']);
    }

    public function test_clinic_report_excludes_optical_sale_for_same_subscriber(): void
    {
        $this->makeSale(['business_line' => 'clinic', 'total_amount' => 100]);
        $this->makeSale(['business_line' => 'optical', 'total_amount' => 250]);

        $component = Livewire::test(ReportsComponent::class)
            ->set('fromDate', now()->format('Y-m-d'))
            ->set('toDate', now()->format('Y-m-d'));

        $this->assertSame(1, $component->viewData('summary')['count']);
        $this->assertEquals(100, $component->viewData('summary')['total_sales']);
    }

    public function test_summary_total_revenue_is_correct(): void
    {
        $this->makeSale(['total_amount' => 150.00]);
        $this->makeSale(['total_amount' => 250.00]);

        $component = Livewire::test(ReportsComponent::class)
            ->set('fromDate', now()->format('Y-m-d'))
            ->set('toDate', now()->format('Y-m-d'));

        $this->assertGreaterThanOrEqual(400.00, $component->viewData('summary')['total_sales']);
    }

    public function test_summary_excludes_refunded_sales_by_default(): void
    {
        $this->makeSale(['is_refunded' => false]);
        $refunded = $this->makeSale(['is_refunded' => true]);

        $component = Livewire::test(ReportsComponent::class)
            ->set('fromDate', now()->format('Y-m-d'))
            ->set('toDate', now()->format('Y-m-d'))
            ->set('showRefunded', false);

        $ids = $component->viewData('sales')->pluck('id');

        $this->assertNotContains($refunded->id, $ids->toArray());
    }

    public function test_summary_includes_refunded_when_flag_set(): void
    {
        $normal   = $this->makeSale(['is_refunded' => false]);
        $refunded = $this->makeSale(['is_refunded' => true]);

        $component = Livewire::test(ReportsComponent::class)
            ->set('fromDate', now()->format('Y-m-d'))
            ->set('toDate', now()->format('Y-m-d'))
            ->set('showRefunded', true);

        $ids = $component->viewData('sales')->pluck('id');

        $this->assertContains($normal->id, $ids->toArray());
        $this->assertContains($refunded->id, $ids->toArray());
    }

    // ── Search ────────────────────────────────────────────────────────────

    public function test_search_filters_by_transaction_id(): void
    {
        $txnId = 'TXN-REPORTS-FIND99';
        $this->makeSale(['transaction_id' => $txnId]);
        $this->makeSale(); // noise

        $ids = Livewire::test(ReportsComponent::class)
            ->set('fromDate', now()->format('Y-m-d'))
            ->set('toDate', now()->format('Y-m-d'))
            ->set('searchQuery', 'REPORTS-FIND99')
            ->viewData('sales')
            ->pluck('transaction_id');

        $this->assertContains($txnId, $ids->toArray());
    }

    public function test_search_by_patient_name_does_not_throw(): void
    {
        $this->makeSale();

        Livewire::test(ReportsComponent::class)
            ->set('fromDate', now()->format('Y-m-d'))
            ->set('toDate', now()->format('Y-m-d'))
            ->set('searchQuery', $this->patient->name)
            ->assertStatus(200);
    }

    public function test_purchase_type_filter_shows_only_direct_purchases(): void
    {
        $patientSale = $this->makeSale();
        $directSale = $this->makeSale([
            'patient_id' => null,
            'customer_name' => 'Direct Buyer',
        ]);

        $ids = Livewire::test(ReportsComponent::class)
            ->set('fromDate', now()->format('Y-m-d'))
            ->set('toDate', now()->format('Y-m-d'))
            ->set('purchaseType', 'direct')
            ->viewData('sales')
            ->pluck('id');

        $this->assertContains($directSale->id, $ids->toArray());
        $this->assertNotContains($patientSale->id, $ids->toArray());
    }

    public function test_purchase_type_filter_shows_only_patient_purchases(): void
    {
        $patientSale = $this->makeSale();
        $directSale = $this->makeSale([
            'patient_id' => null,
            'customer_name' => 'Direct Buyer',
        ]);

        $ids = Livewire::test(ReportsComponent::class)
            ->set('fromDate', now()->format('Y-m-d'))
            ->set('toDate', now()->format('Y-m-d'))
            ->set('purchaseType', 'patient')
            ->viewData('sales')
            ->pluck('id');

        $this->assertContains($patientSale->id, $ids->toArray());
        $this->assertNotContains($directSale->id, $ids->toArray());
    }

    // ── Date range ────────────────────────────────────────────────────────

    public function test_date_range_excludes_old_sales(): void
    {
        $old    = $this->makeSale();
        $recent = $this->makeSale();

        Sales::where('id', $old->id)->update(['created_at' => now()->subMonth()]);

        $ids = Livewire::test(ReportsComponent::class)
            ->set('fromDate', now()->format('Y-m-d'))
            ->set('toDate', now()->format('Y-m-d'))
            ->viewData('sales')
            ->pluck('id');

        $this->assertContains($recent->id, $ids->toArray());
        $this->assertNotContains($old->id, $ids->toArray());
    }

    // ── Chart ─────────────────────────────────────────────────────────────

    public function test_load_chart_dispatches_update_chart_event(): void
    {
        $this->makeSale();

        Livewire::test(ReportsComponent::class)
            ->set('fromDate', now()->format('Y-m-d'))
            ->set('toDate', now()->format('Y-m-d'))
            ->call('loadChart')
            ->assertDispatched('update-chart');
    }
}
