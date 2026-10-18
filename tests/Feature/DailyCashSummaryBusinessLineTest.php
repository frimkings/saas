<?php

namespace Tests\Feature;

use App\Livewire\Admin\DailyCashSummaryComponent;
use App\Models\PaymentTransaction;
use App\Models\Sales;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DailyCashSummaryBusinessLineTest extends TestCase
{
    use RefreshDatabase;

    private function sale(User $user, string $line, string $reference, float $amount, string $method): void
    {
        $sale = Sales::create(['user_id' => $user->id, 'business_line' => $line, 'transaction_id' => $reference,
            'total_amount' => $amount, 'amount_paid' => $amount, 'payment_status' => 'paid']);
        PaymentTransaction::create(['sale_id' => $sale->id, 'amount' => $amount, 'payment_method' => $method, 'collected_by' => $user->id]);
    }

    public function test_cash_summary_is_per_business_with_a_combined_view(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $this->actingAs($user);
        $this->sale($user, 'clinic', 'CLINIC-CASH-1', 120, 'cash');
        $this->sale($user, 'optical', 'OPT-CASH-1', 80, 'momo');

        // Both businesses: combined by default, the same totals as before, with the split shown.
        $page = Livewire::test(DailyCashSummaryComponent::class)->assertSet('line', 'combined')
            ->assertViewHas('amountPaid', fn ($value) => (float) $value === 200.0)
            ->assertSee('Collected by business')->assertSee('Clinic &amp; Optical', false);

        $page->set('line', 'clinic')
            ->assertViewHas('amountPaid', fn ($value) => (float) $value === 120.0)
            ->assertViewHas('payments', fn ($payments) => $payments->pluck('payment_method')->all() === ['cash'])
            ->assertDontSee('Collected by business');
        $page->set('line', 'optical')
            ->assertViewHas('grossSales', fn ($value) => (float) $value === 80.0)
            ->assertViewHas('payments', fn ($payments) => $payments->pluck('payment_method')->all() === ['momo']);

        // A line the subscriber cannot pick falls back to the default.
        $page->set('line', 'something-else')->assertSet('line', 'combined');
    }

    public function test_the_summary_covers_a_range_of_days(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $this->actingAs($user);
        $this->travelTo(now()->subDays(3));
        $this->sale($user, 'clinic', 'OLDER', 50, 'cash');
        $this->travelBack();
        $this->sale($user, 'clinic', 'TODAY', 70, 'cash');

        $page = Livewire::test(DailyCashSummaryComponent::class)
            ->assertSet('fromDate', today()->toDateString())->assertSet('toDate', today()->toDateString())
            ->assertViewHas('amountPaid', fn ($value) => (float) $value === 70.0)
            ->assertViewHas('periodLabel', today()->format('M d, Y'));

        $page->set('fromDate', today()->subDays(5)->toDateString())
            ->assertViewHas('amountPaid', fn ($value) => (float) $value === 120.0)
            ->assertViewHas('salesCount', 2)
            ->assertViewHas('payments', fn ($payments) => (float) $payments->sum('total') === 120.0)
            ->assertSee(today()->subDays(5)->format('M d, Y').' – '.today()->format('M d, Y'));

        // Dates picked the wrong way round still give the same days.
        $page->set('fromDate', today()->toDateString())->set('toDate', today()->subDays(5)->toDateString())
            ->assertViewHas('amountPaid', fn ($value) => (float) $value === 120.0);
    }
}
