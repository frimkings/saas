<?php

namespace Tests\Feature;

use App\Livewire\Admin\IncomeStatementComponent;
use App\Livewire\Cashier\SalesRecordsComponent;
use App\Models\SaleAdjustment;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Models\User;
use App\Services\Finance\ClinicStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IncomeStatementDiscountsTest extends TestCase
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

    private function sale(string $ref, array $prices, float $discount = 0, string $line = 'clinic'): Sales
    {
        $gross = array_sum($prices);
        $sale = Sales::create(['user_id' => $this->owner->id, 'business_line' => $line, 'transaction_id' => $ref,
            'total_amount' => $gross - $discount, 'amount_paid' => $gross - $discount, 'payment_status' => 'paid',
            'discount_type' => $discount > 0 ? 'fixed' : null, 'discount_value' => $discount ?: null, 'discount_amount' => $discount]);
        foreach ($prices as $price) {
            SaleItem::create(['sale_id' => $sale->id, 'prescribed_quantity' => 1, 'dispensed_quantity' => 1, 'selling_price' => $price]);
        }

        return $sale;
    }

    public function test_discounts_and_write_offs_are_operating_expenses_and_returned_items_leave_revenue(): void
    {
        $this->sale('TX-A', [200], 20);
        // 100 + 50 with a 30 discount; the 50 item is returned, taking 10 of the discount with it.
        $partly = $this->sale('TX-B', [100, 50], 30);
        SaleItem::where('sale_id', $partly->id)->where('subtotal', 50)->update(['refunded_quantity' => 1]);
        $insured = $this->sale('TX-C', [80]);
        SaleAdjustment::create(['sale_id' => $insured->id, 'type' => 'insurance_write_off', 'amount' => 15, 'method' => 'fixed',
            'created_by' => $this->owner->id, 'reason' => 'Insurer paid less']);
        $this->sale('TX-OPT', [500], 50, 'optical');

        $statement = app(ClinicStatementService::class)->statement(now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(380.0, $statement['revenue'], 'Full price, without the returned item or optical sales.');
        $this->assertSame([['name' => 'Discounts allowed', 'amount' => 40.0], ['name' => 'Insurance shortfalls written off', 'amount' => 15.0]],
            $statement['sales_expense_lines']->all());
        $this->assertSame(55.0, $statement['operating_expenses']);
        $this->assertSame(325.0, $statement['net_profit']);

        Livewire::test(IncomeStatementComponent::class)->assertSee('Discounts allowed')->assertSee('40.00')->assertSee('from sales');
        $csv = $this->get(route('admin.income-statement.export.csv', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()]))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('"Discounts allowed",40.00,"From sales"', $csv);
    }

    public function test_sales_records_show_the_discount_given_and_who_approved_it(): void
    {
        $sale = $this->sale('TX-D', [250], 25);
        $approver = User::factory()->create(['name' => 'Owner Kofi']);
        $sale->update(['discount_type' => 'percentage', 'discount_value' => 10, 'discount_approved_by' => $approver->id]);
        $this->sale('TX-N', [40]);

        Livewire::test(SalesRecordsComponent::class)
            ->assertSee('Discounts given')->assertSee('after '.currency().' 25.00 discount (10%)')
            ->assertSee('approved by Owner Kofi')
            ->assertViewHas('totalDiscounts', 25.0);

        $this->get(route('admin.sales-records'))->assertOk();
        $response = (new SalesRecordsComponent)->exportCSV();
        ob_start(); $response->sendContent(); $body = ob_get_clean();
        $this->assertStringContainsString('Gross,Discount,"Discount approved by",Amount,Paid,Status', $body);
        $this->assertStringContainsString('TX-D,', $body);
        $this->assertStringContainsString('250.00,25.00,"Owner Kofi",225.00,225.00,Paid', $body);
    }
}
