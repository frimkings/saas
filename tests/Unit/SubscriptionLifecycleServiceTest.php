<?php

namespace Tests\Unit;

use App\Models\ClinicSubscription;
use App\Services\SubscriptionLifecycleService;
use Carbon\Carbon;
use Tests\TestCase;

class SubscriptionLifecycleServiceTest extends TestCase
{
    private SubscriptionLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SubscriptionLifecycleService::class);
        config(['subscriptions.restriction_days' => 7]);
    }

    public function test_active_subscription_remains_active_before_period_end(): void
    {
        $at = Carbon::parse('2026-09-09 12:00:00');
        $subscription = new ClinicSubscription(['status'=>'active','current_period_ends_at'=>$at->copy()->addDay()]);
        $this->assertSame('active', $this->service->calculateStatus($subscription, $at));
    }

    public function test_expired_subscription_ignores_legacy_open_grace(): void
    {
        $at = Carbon::parse('2026-09-09 12:00:00');
        $subscription = new ClinicSubscription(['status'=>'overdue','grace_ends_at'=>$at->copy()->addDay()]);
        $this->assertSame('restricted', $this->service->calculateStatus($subscription, $at));
    }

    public function test_expired_grace_becomes_restricted(): void
    {
        $at = Carbon::parse('2026-09-09 12:00:00');
        $subscription = new ClinicSubscription(['status'=>'overdue','grace_ends_at'=>$at->copy()->subSecond()]);
        $this->assertSame('restricted', $this->service->calculateStatus($subscription, $at));
    }

    public function test_restricted_subscription_becomes_suspended_after_configured_period(): void
    {
        $at = Carbon::parse('2026-09-09 12:00:00');
        $subscription = new ClinicSubscription(['status'=>'restricted','restricted_at'=>$at->copy()->subDays(8)]);
        $this->assertSame('suspended', $this->service->calculateStatus($subscription, $at));
    }
}
