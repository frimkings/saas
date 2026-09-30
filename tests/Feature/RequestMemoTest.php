<?php

namespace Tests\Feature;

use App\Models\{Clinic, ClinicSubscription, Setting, SubscriptionPlan, User};
use App\Services\ClinicAccessService;
use App\Services\SubscriptionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Subscription and settings lookups are read once per request, and saved changes show at once. */
class RequestMemoTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private ClinicSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $this->clinic = Clinic::create(['name' => 'Memo Clinic', 'slug' => 'memo-clinic', 'deployment_mode' => 'hosted']);
        $branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Memo plan', 'code' => 'memo-plan', 'included_branches' => 1, 'included_users' => 5,
            'storage_limit_mb' => 100, 'features' => ['clinical'], 'base_price' => 10, 'billing_interval' => 'monthly']);
        $this->subscription = ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'feature_snapshot' => ['clinical'], 'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        app(TenantContext::class)->set($user, $this->clinic, $branch, [$branch->id]);
    }

    public function test_subscription_is_read_once_per_request(): void
    {
        DB::enableQueryLog();
        foreach (range(1, 5) as $_) app(ClinicAccessService::class)->access('clinical');
        $lookups = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from `clinic_subscriptions`'));

        $this->assertCount(1, $lookups);
    }

    public function test_saved_subscription_change_applies_within_the_same_request(): void
    {
        $access = app(ClinicAccessService::class);
        $this->assertTrue($access->access('clinical')['allowed']);

        $this->subscription->update(['status' => 'suspended']);

        $this->assertFalse($access->access('clinical')['allowed']);
        $this->assertSame('suspended', app(SubscriptionService::class)->current($this->clinic)->status);
    }

    public function test_settings_are_read_once_and_reuse_the_resolved_clinic(): void
    {
        Setting::getSettings();
        DB::enableQueryLog();

        $this->assertSame('Memo Clinic', Setting::getSettings()->clinic_name);
        Setting::currency();

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_unsaved_edits_stay_with_the_caller_and_saved_edits_are_seen(): void
    {
        $draft = Setting::getSettings();
        $draft->clinic_address = 'Never saved';
        $this->assertNotSame('Never saved', Setting::getSettings()->clinic_address);

        $settings = Setting::getSettings();
        $settings->currency_symbol = '$';
        $settings->save();

        $this->assertSame('$', Setting::currency());
    }
}
