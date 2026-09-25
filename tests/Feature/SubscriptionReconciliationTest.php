<?php

namespace Tests\Feature;

use App\Models\{Branch, Clinic, ClinicSubscription, PlatformInvoice, PlatformPayment, SubscriptionPlan};
use App\Services\SubscriptionReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function subscribedClinic(string $slug = 'reconcile-clean'): array
    {
        $clinic = Clinic::create(['name' => 'Reconcile Clinic', 'slug' => $slug]);
        $plan = SubscriptionPlan::create([
            'name' => 'Reconcile Plan', 'code' => 'plan-'.$slug, 'family_code' => 'plan-'.$slug,
            'included_branches' => 1, 'base_price' => 100, 'annual_price' => 1000,
            'billing_interval' => 'monthly', 'features' => [],
        ]);
        $subscription = ClinicSubscription::create([
            'clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id,
            'status' => 'active', 'billing_interval' => 'monthly',
            'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth(),
        ]);

        return compact('clinic', 'plan', 'subscription');
    }

    public function test_clean_subscription_and_billing_records_pass_reconciliation(): void
    {
        ['clinic' => $clinic, 'subscription' => $subscription] = $this->subscribedClinic();
        Branch::create(['clinic_id' => $clinic->id, 'code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $invoice = PlatformInvoice::create([
            'number' => 'INV-REC-CLEAN', 'clinic_id' => $clinic->id, 'clinic_subscription_id' => $subscription->id,
            'period_start' => now(), 'period_end' => now()->addMonth(), 'due_date' => now()->addWeek(),
            'subtotal' => 100, 'tax' => 0, 'total' => 100, 'amount_paid' => 100,
            'currency' => 'GHS', 'status' => 'paid', 'paid_at' => now(),
        ]);
        PlatformPayment::create(['platform_invoice_id' => $invoice->id, 'amount' => 100, 'method' => 'cash', 'status' => 'confirmed', 'paid_at' => now()]);

        $run = app(SubscriptionReconciliationService::class)->run();
        $this->assertSame('passed', $run->status);
        $this->assertSame(0, $run->issues->count());
    }

    public function test_reconciliation_persists_actionable_subscription_and_payment_discrepancies(): void
    {
        ['clinic' => $clinic, 'subscription' => $subscription] = $this->subscribedClinic('reconcile-broken');
        Branch::create(['clinic_id' => $clinic->id, 'code' => 'ONE', 'name' => 'One', 'is_default' => true, 'is_active' => true]);
        Branch::create(['clinic_id' => $clinic->id, 'code' => 'TWO', 'name' => 'Two', 'is_active' => true]);
        $subscription->agreement()->delete();
        $invoice = PlatformInvoice::create([
            'number' => 'INV-REC-BROKEN', 'clinic_id' => $clinic->id, 'clinic_subscription_id' => $subscription->id,
            'period_start' => now(), 'period_end' => now()->addMonth(), 'due_date' => now()->subDay(),
            'subtotal' => 100, 'tax' => 0, 'total' => 100, 'amount_paid' => 100,
            'currency' => 'GHS', 'status' => 'paid',
        ]);
        PlatformPayment::create(['platform_invoice_id' => $invoice->id, 'amount' => 40, 'method' => 'cash', 'status' => 'confirmed', 'paid_at' => now()]);

        $run = app(SubscriptionReconciliationService::class)->run();
        $this->assertSame('failed', $run->status);
        $this->assertEqualsCanonicalizing(
            ['MISSING_AGREEMENT', 'BRANCH_LIMIT_EXCEEDED', 'PAYMENT_TOTAL_MISMATCH'],
            $run->issues->pluck('code')->all()
        );
        $this->assertDatabaseHas('subscription_reconciliation_issues', ['run_id' => $run->id, 'code' => 'PAYMENT_TOTAL_MISMATCH', 'severity' => 'critical']);
    }
}
