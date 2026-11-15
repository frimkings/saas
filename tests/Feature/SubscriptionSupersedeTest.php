<?php

namespace Tests\Feature;

use App\Models\{Clinic, ClinicSubscription, PlatformInvoice, SubscriptionPlan};
use App\Services\{SubscriptionBillingService, SubscriptionLifecycleService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubscriptionSupersedeTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $code, float $price, string $product = 'clinic'): SubscriptionPlan
    {
        return SubscriptionPlan::create(['name' => ucfirst($code), 'code' => $code, 'product' => $product, 'included_branches' => 1, 'included_users' => 5,
            'features' => [$product === 'optical' ? 'optical' : 'clinical', '*'], 'base_price' => $price, 'billing_interval' => 'monthly', 'is_active' => true]);
    }

    public function test_a_plan_change_supersedes_the_old_subscription_and_only_the_current_one_is_billed(): void
    {
        Mail::fake();
        $clinic = Clinic::create(['name' => 'Bright Eyes', 'slug' => 'bright-eyes', 'deployment_mode' => 'hosted', 'status' => 'active']);
        $old = ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $this->plan('standard', 180)->id, 'status' => 'active',
            'billing_interval' => 'monthly', 'current_period_starts_at' => now()->subDays(25), 'current_period_ends_at' => now()->addDays(5)]);

        $change = app(SubscriptionBillingService::class)->scheduleChange($old, $this->plan('pro', 320), 'monthly', 'immediate', 'Upgrade', null);
        $current = $clinic->subscriptions()->latest('id')->first();

        $this->assertSame('applied', $change->status);
        $this->assertNotSame($old->id, $current->id);
        $this->assertSame('active', $current->status);
        $old->refresh();
        $this->assertSame(ClinicSubscription::SUPERSEDED, $old->status);
        $this->assertSame('active', $old->superseded_status);
        $this->assertNotNull($old->superseded_at);

        // Both rows end within the renewal window; only the current one gets a renewal invoice.
        app(SubscriptionBillingService::class)->generateDueInvoices(7);
        $this->assertSame([$current->id], PlatformInvoice::where('source', 'renewal')->pluck('clinic_subscription_id')->unique()->values()->all());

        // The lifecycle job leaves the superseded row alone.
        app(SubscriptionLifecycleService::class)->evaluateAll(now()->addDays(40));
        $this->assertSame(ClinicSubscription::SUPERSEDED, $old->fresh()->status);
    }

    public function test_restoring_a_superseded_subscription_brings_back_the_status_it_had(): void
    {
        Mail::fake();
        $clinic = Clinic::create(['name' => 'Clear View', 'slug' => 'clear-view', 'deployment_mode' => 'hosted', 'status' => 'active']);
        $clinicSub = ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $this->plan('standard', 180)->id, 'status' => 'active',
            'billing_interval' => 'monthly', 'current_period_starts_at' => now()->subDays(10), 'current_period_ends_at' => now()->addDays(20)]);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $this->plan('shop', 300, 'optical')->id, 'status' => 'active',
            'billing_interval' => 'monthly', 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth()]);
        $this->assertSame(ClinicSubscription::SUPERSEDED, $clinicSub->fresh()->status);

        $this->artisan('subscriptions:restore-previous', ['clinics' => [$clinic->id], '--product' => 'optical'])->assertSuccessful();

        $restored = $clinic->subscriptions()->latest('id')->first();
        $this->assertSame($clinicSub->subscription_plan_id, $restored->subscription_plan_id);
        $this->assertSame('active', $restored->status);
    }
}
