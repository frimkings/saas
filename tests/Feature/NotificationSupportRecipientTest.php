<?php

namespace Tests\Feature;

use App\Models\{Clinic, ClinicSubscription, SubscriptionPlan, User};
use App\Services\NotificationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-11-24 live 500: a refund asked for during a support visit was approved/processed by the owner,
 * and the "your refund was approved" bell to the platform admin (not a clinic member) threw.
 */
class NotificationSupportRecipientTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_platform_admin_outside_the_branch_is_skipped_but_other_outsiders_still_fail(): void
    {
        config(['tenancy.enabled' => true]);
        $clinic = Clinic::create(['name' => 'Hope', 'slug' => 'hope']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Kasoa', 'is_default' => true, 'is_active' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Basic', 'code' => 'basic-hope', 'included_branches' => 1, 'included_users' => 5, 'base_price' => 99, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth()]);
        $owner = User::factory()->create();
        $clinic->users()->attach($owner->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($owner->id, ['status' => 'active', 'is_default' => true]);
        app(TenantContext::class)->set($owner, $clinic, $branch, [$branch->id]);

        $this->assertNotNull(NotificationService::send($owner->id, 'refund_approved', 'Approved', 'Body'));

        $support = User::factory()->create();
        $support->forceFill(['is_platform_admin' => true])->save();
        $this->assertNull(NotificationService::send($support->id, 'refund_approved', 'Approved', 'Body'));
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $support->id]);

        $stranger = User::factory()->create();
        $this->expectException(\LogicException::class);
        NotificationService::send($stranger->id, 'refund_approved', 'Approved', 'Body');
    }
}
