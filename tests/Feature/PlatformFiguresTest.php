<?php

namespace Tests\Feature;

use App\Livewire\Platform\PlatformDashboardComponent;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\PlatformInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\DeploymentReadinessService;
use App\Services\SubscriptionAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Figures on the developer platform that were wrong on the live data (17 Nov 2026). */
class PlatformFiguresTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan = SubscriptionPlan::create(['name' => 'BASIC', 'code' => 'basic-fig', 'base_price' => 100, 'annual_price' => 1000, 'billing_interval' => 'monthly', 'currency' => 'GHS', 'is_active' => true]);
    }

    private function clinic(string $slug, array $subscription): Clinic
    {
        $clinic = Clinic::create(['name' => ucfirst($slug), 'slug' => $slug, 'status' => 'active', 'deployment_mode' => 'hosted']);
        ClinicSubscription::create($subscription + ['clinic_id' => $clinic->id, 'subscription_plan_id' => $this->plan->id, 'billing_interval' => 'monthly',
            'current_period_starts_at' => now()->subDays(10), 'current_period_ends_at' => now()->addDays(20)]);

        return $clinic;
    }

    public function test_churn_never_exceeds_everyone_and_a_trial_that_ended_suspended_did_not_convert(): void
    {
        // All of them joined this year, after the report's start: churn used to divide by nobody (shown as 500%).
        $this->clinic('paying', ['status' => 'active']);
        $converted = $this->clinic('converted', ['status' => 'trial', 'trial_ends_at' => now()->subDays(3)]);
        ClinicSubscription::create(['clinic_id' => $converted->id, 'subscription_plan_id' => $this->plan->id, 'status' => 'active', 'billing_interval' => 'monthly',
            'current_period_starts_at' => now()->subDays(3), 'current_period_ends_at' => now()->addDays(27)]);
        $this->clinic('lapsed', ['status' => 'suspended', 'trial_ends_at' => now()->subDays(5), 'status_changed_at' => now()->subDays(4)]);

        $report = app(SubscriptionAnalyticsService::class)->report(now()->startOfYear(), now());
        $metrics = $report['metrics'];
        $clinics = $report['subscriptions']->count();

        $this->assertSame(1, $metrics['churned']);
        $this->assertSame(round(100 / $clinics, 1), $metrics['churn_rate'], 'one lost out of every clinic subscribed in the dates');
        $this->assertSame([2, 1, 50.0], [$metrics['trials'], $metrics['converted'], $metrics['conversion_rate']], 'the suspended trial counts, unconverted');
    }

    public function test_overdue_accounts_count_clinics_owing_past_due_and_plans_count_only_clinics_on_them_now(): void
    {
        $owing = $this->clinic('owing', ['status' => 'active']);
        PlatformInvoice::create(['number' => 'INV-LATE', 'clinic_id' => $owing->id, 'source' => 'renewal', 'status' => 'unpaid', 'period_start' => now()->subDays(10), 'period_end' => now()->addDays(20),
            'due_date' => now()->subDays(3), 'subtotal' => 100, 'tax' => 0, 'total' => 100, 'amount_paid' => 0, 'currency' => 'GHS']);
        PlatformInvoice::create(['number' => 'INV-TODAY', 'clinic_id' => $this->clinic('due-today', ['status' => 'active'])->id, 'source' => 'renewal', 'status' => 'unpaid',
            'period_start' => now(), 'period_end' => now()->addMonth(), 'due_date' => today(), 'subtotal' => 100, 'tax' => 0, 'total' => 100, 'amount_paid' => 0, 'currency' => 'GHS']);
        // A clinic that moved to another plan: its old BASIC row no longer counts on BASIC.
        $moved = $this->clinic('moved', ['status' => 'active']);
        $pro = SubscriptionPlan::create(['name' => 'Pro', 'code' => 'pro-fig', 'base_price' => 300, 'billing_interval' => 'monthly', 'currency' => 'GHS', 'is_active' => true]);
        ClinicSubscription::create(['clinic_id' => $moved->id, 'subscription_plan_id' => $pro->id, 'status' => 'active', 'billing_interval' => 'monthly']);

        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();
        $page = Livewire::actingAs($admin)->test(PlatformDashboardComponent::class);

        $this->assertSame(1, $page->viewData('metrics')['overdue'], 'due today is not overdue yet');
        $this->assertSame([2, 1], [$page->viewData('plans')->firstWhere('id', $this->plan->id)->subscriptions_count, $page->viewData('plans')->firstWhere('id', $pro->id)->subscriptions_count]);
        $this->assertSame('BASIC — basic-fig', $this->plan->optionLabel());
        $this->plan->update(['is_active' => false]);
        $this->assertSame('BASIC — basic-fig (archived)', $this->plan->fresh()->optionLabel());
    }

    public function test_a_managed_queue_passes_the_worker_check_without_a_recent_heartbeat(): void
    {
        Cache::put('platform.queue_worker_last_seen', now()->subDay()->toIso8601String());

        config(['queue.managed' => false]);
        $this->assertFalse(app(DeploymentReadinessService::class)->run(false)['checks']['queue_worker']['passed']);

        config(['queue.managed' => true]);
        $check = app(DeploymentReadinessService::class)->run(false)['checks']['queue_worker'];
        $this->assertTrue($check['passed']);
        $this->assertStringContainsString('Managed queue', $check['evidence'] ?? $check['detail'] ?? json_encode($check));
    }
}
