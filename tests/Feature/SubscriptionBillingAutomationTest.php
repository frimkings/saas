<?php

namespace Tests\Feature;

use App\Models\{Clinic,ClinicSubscription,SubscriptionPlan};
use App\Services\SubscriptionBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionBillingAutomationTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(): array
    {
        $clinic=Clinic::create(['name'=>'Billing Clinic','slug'=>'billing-clinic']);
        $clinic->branches()->create(['code'=>'MAIN','name'=>'Main','is_default'=>true,'is_active'=>true]);
        $plan=SubscriptionPlan::create(['name'=>'Professional','code'=>'professional','included_branches'=>1,'base_price'=>100,'annual_price'=>1000,'additional_branch_price'=>25,'tax_rate'=>10,'billing_interval'=>'monthly','features'=>['inventory']]);
        $subscription=ClinicSubscription::create(['clinic_id'=>$clinic->id,'subscription_plan_id'=>$plan->id,'status'=>'active','billing_interval'=>'monthly','renewal_mode'=>'automatic','current_period_starts_at'=>now()->subMonth(),'current_period_ends_at'=>now()->addDay()]);
        return [$clinic,$plan,$subscription];
    }

    public function test_renewal_invoice_generation_is_idempotent_and_uses_snapshot_price(): void
    {
        [,,$subscription]=$this->subscription();$service=app(SubscriptionBillingService::class);
        $first=$service->invoiceFor($subscription);$second=$service->invoiceFor($subscription);
        $this->assertSame($first->id,$second->id);$this->assertSame('110.00',$first->total);
        $this->assertDatabaseCount('platform_invoices',1);
    }

    public function test_an_extra_branch_costs_its_monthly_price_on_a_monthly_renewal(): void
    {
        [$clinic,,$subscription]=$this->subscription();
        $clinic->branches()->create(['code'=>'B2','name'=>'Second','is_default'=>false,'is_active'=>true]);
        $invoice=app(SubscriptionBillingService::class)->invoiceFor($subscription);
        $this->assertSame(25.0,(float)$invoice->lines->firstWhere('description','1 extra branch')->amount);
    }

    public function test_an_extra_branch_costs_a_year_at_the_plans_yearly_rate_on_a_yearly_renewal(): void
    {
        [$clinic,,$subscription]=$this->subscription();
        $subscription->update(['billing_interval'=>'yearly']);
        $clinic->branches()->create(['code'=>'B2','name'=>'Second','is_default'=>false,'is_active'=>true]);

        // 25 a month at the plan's yearly rate (1000 a year for 100 a month) = 250, not 25; plus 10% tax.
        $invoice=app(SubscriptionBillingService::class)->invoiceFor($subscription->fresh());
        $this->assertSame(250.0,(float)$invoice->lines->firstWhere('description','1 extra branch')->amount);
        $this->assertSame('1375.00',$invoice->total);
    }

    public function test_full_payment_is_allocated_and_renews_subscription(): void
    {
        [,,$subscription]=$this->subscription();$service=app(SubscriptionBillingService::class);$invoice=$service->invoiceFor($subscription);
        $service->allocatePayment($invoice,(float)$invoice->total,'bank_transfer','BANK-001',null,'gateway:BANK-001');
        $this->assertSame('paid',$invoice->refresh()->status);$this->assertNotNull($invoice->paid_at);
        $this->assertSame('active',$subscription->refresh()->status);$this->assertSame($invoice->period_end->toDateString(),$subscription->current_period_ends_at->toDateString());
    }

    public function test_period_end_plan_change_is_applied_only_when_due(): void
    {
        [,,$subscription]=$this->subscription();$newPlan=SubscriptionPlan::create(['name'=>'Enterprise','code'=>'enterprise','base_price'=>300,'annual_price'=>3000,'billing_interval'=>'monthly']);$service=app(SubscriptionBillingService::class);
        $change=$service->scheduleChange($subscription,$newPlan,'monthly','period_end','Approved upgrade',null);
        $this->assertSame('scheduled',$change->status);$this->assertSame(0,$service->applyScheduledChanges());
        $change->update(['effective_at'=>now()->subMinute()]);$this->assertSame(1,$service->applyScheduledChanges());
        $this->assertSame('applied',$change->refresh()->status);$this->assertSame($newPlan->id,ClinicSubscription::where('clinic_id',$subscription->clinic_id)->latest('id')->value('subscription_plan_id'));
    }
}
