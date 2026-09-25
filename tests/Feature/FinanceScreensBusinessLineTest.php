<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminDashboardComponent;
use App\Models\ReportDelivery;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Models\User;
use App\Services\FinancialReportDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** An offline install with every feature runs both businesses. */
class FinanceScreensBusinessLineTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $this->actingAs($this->owner);
    }

    private function sale(string $line, string $reference, float $total, float $paid, array $attributes = []): Sales
    {
        return Sales::create(['user_id' => $this->owner->id, 'business_line' => $line, 'transaction_id' => $reference,
            'total_amount' => $total, 'amount_paid' => $paid, 'payment_status' => $paid >= $total ? 'paid' : 'partial'] + $attributes);
    }

    public function test_dashboard_money_figures_cover_the_chosen_business(): void
    {
        $this->sale('clinic', 'CLINIC-DASH-1', 100, 100);
        $optical = $this->sale('optical', 'OPT-DASH-1', 250, 50);
        $category = \App\Models\OpticalCategory::create(['code' => 'FRAMES', 'name' => 'Frames', 'is_active' => true]);
        $frame = \App\Models\OpticalProduct::create(['optical_category_id' => $category->id, 'name' => 'Dashboard Frame', 'sku' => 'DF-1', 'selling_price' => 250, 'cost_price' => 100]);
        SaleItem::create(['sale_id' => $optical->id, 'optical_product_id' => $frame->id, 'prescribed_quantity' => 0, 'dispensed_quantity' => 1, 'selling_price' => 250, 'subtotal' => 250]);

        Livewire::test(AdminDashboardComponent::class)
            ->assertSet('line', 'combined')->assertSet('lineOptions', ['clinic', 'optical', 'combined'])
            ->assertSet('todayRevenue', fn ($value) => (float) $value === 350.0)
            ->assertSet('outstandingCount', 1)
            ->assertSee('Dashboard Frame')->assertSee('Combined Statement');

        Livewire::withQueryParams(['line' => 'clinic'])->test(AdminDashboardComponent::class)
            ->assertSet('line', 'clinic')->assertSet('todayRevenue', fn ($value) => (float) $value === 100.0)
            ->assertSet('outstandingCount', 0)->assertDontSee('Dashboard Frame');

        Livewire::withQueryParams(['line' => 'optical'])->test(AdminDashboardComponent::class)
            ->assertSet('todayRevenue', fn ($value) => (float) $value === 250.0)
            ->assertSee('Profit &amp; Loss', false)->assertSee(route('optical.expenses'));
    }

    public function test_report_email_covers_both_businesses_with_a_breakdown(): void
    {
        $this->sale('clinic', 'CLINIC-MAIL-1', 100, 100);
        $this->sale('optical', 'OPT-MAIL-1', 250, 50);
        $this->sale('optical', 'OPT-MAIL-2', 40, 40, ['is_refunded' => true, 'refunded_at' => now()]);

        $delivery = app(FinancialReportDeliveryService::class)->queueReport('daily', true, true);
        $report = $delivery->report_payload;

        $this->assertEquals(350, $report['gross_revenue']);
        $this->assertEquals(310, $report['net_revenue']);
        $this->assertEquals(100, $report['lines']['clinic']['gross_revenue']);
        $this->assertEquals(210, $report['lines']['optical']['net_revenue']);
        $this->assertEquals(200, $report['lines']['optical']['outstanding']);

        $html = (new \App\Mail\FinancialReportMail(app(FinancialReportDeliveryService::class)->reportForMail(ReportDelivery::sole())))->render();
        $this->assertStringContainsString('By Business', $html);
        $this->assertStringContainsString('Clinic &amp; Optical', $html);
    }
}
