<?php

namespace Tests\Feature;

use App\Livewire\Platform\SupportSettingsComponent;
use App\Models\{Clinic, ClinicSubscription, PlatformSetting, SubscriptionPlan, User};
use App\Services\{ClinicAccessService, SubscriptionBillingService};
use App\Support\Licensing\AccessStage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SubscriptionLockTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private ClinicSubscription $subscription;
    private User $admin;
    private User $secretary;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['tenancy.enabled' => true, 'subscriptions.grace_days' => 1, 'subscriptions.payment_due_days' => 1, 'subscriptions.expiry_warning_days' => 7]);

        $this->clinic = Clinic::create(['name' => 'Clear Sight', 'slug' => 'clear-sight', 'deployment_mode' => 'hosted', 'status' => 'active']);
        $branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true, 'public_booking_key' => 'web-key']);
        $plan = SubscriptionPlan::create(['name' => 'Standard', 'code' => 'standard', 'included_branches' => 1, 'included_users' => 10,
            'features' => ['*'], 'base_price' => 100, 'billing_interval' => 'monthly']);
        $this->subscription = ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id,
            'status' => 'active', 'billing_interval' => 'monthly', 'current_period_starts_at' => now()->subMonth(), 'current_period_ends_at' => now()->addDays(20)]);

        foreach (['Super Admin', 'Secretary', 'Doctor', 'Manager', 'Cashier', 'Optician'] as $roleName) Role::findOrCreate($roleName, 'web');
        foreach (['admin' => 'Super Admin', 'secretary' => 'Secretary'] as $property => $roleName) {
            $user = User::factory()->create();
            $role = Role::findOrCreate($roleName, 'web');
            $user->assignRole($role);
            $this->clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => $roleName]);
            $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
            DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->$property = $user;
        }
    }

    /** Put the subscription's expiry this many hours in the past (negative = future). */
    private function expiredHoursAgo(int $hours): void
    {
        $this->subscription->update(['current_period_ends_at' => now()->subHours($hours)]);
        app(TenantContext::class)->clear();
    }

    private function stageFor(User $user): ?AccessStage
    {
        app(TenantContext::class)->resolveFor($user);
        return app(ClinicAccessService::class)->stage();
    }

    public function test_stages_follow_the_expiry_date(): void
    {
        $expiry = now()->startOfHour();
        $stage = fn (string $offset) => AccessStage::forExpiry($expiry, true, $expiry->copy()->modify($offset))->stage;

        $this->assertSame(AccessStage::ACTIVE, $stage('-8 days'));
        $this->assertSame(AccessStage::EXPIRING, $stage('-3 days'));
        $this->assertSame(AccessStage::GRACE, $stage('+1 hour'));
        $this->assertSame(AccessStage::PAYMENT_DUE, $stage('+25 hours'));
        $this->assertSame(AccessStage::LOCKED, $stage('+49 hours'));
    }

    public function test_grace_day_keeps_the_clinic_fully_working(): void
    {
        $this->expiredHoursAgo(3);

        $this->actingAs($this->secretary)->get(route('secretary.appointments'))->assertOk()->assertSee('has expired');
        $this->assertFalse(app(ClinicAccessService::class)->access()['read_only']);
        $this->get(route('subscription.locked'))->assertRedirect('/');
    }

    public function test_after_grace_staff_see_payment_not_made_and_admin_only_reaches_subscription(): void
    {
        $this->expiredHoursAgo(30);

        $this->actingAs($this->secretary)->get(route('secretary.appointments'))->assertRedirect(route('subscription.locked'));
        $this->get(route('subscription.locked'))->assertOk()->assertSee('Subscription payment has not been made')->assertSee('Clear Sight');
        $this->postJson('/livewire/update', [])->assertForbidden();

        app(TenantContext::class)->clear();
        $this->flushSession();
        $this->actingAs($this->admin)->get(route('secretary.appointments'))->assertRedirect(route('admin.subscription'));
        $this->get(route('subscription.locked'))->assertRedirect(route('admin.subscription'));
        $this->get(route('admin.subscription'))->assertOk()->assertSee('Staff are locked out');
    }

    public function test_after_two_days_staff_are_told_to_contact_support_and_admin_can_still_pay(): void
    {
        PlatformSetting::put(['support_name' => 'EyeClinic Support', 'support_phone' => '0302000000', 'support_whatsapp' => '0241234567']);
        $this->expiredHoursAgo(50);

        $this->actingAs($this->secretary)->get(route('subscription.locked'))->assertOk()
            ->assertSee('Clinic access suspended')->assertSee('EyeClinic Support')->assertSee('0302000000')
            ->assertSee('https://wa.me/233241234567?text=', false);

        app(TenantContext::class)->clear();
        $this->flushSession();
        $this->actingAs($this->admin)->get(route('admin.subscription'))->assertOk()->assertSee('0302000000');
    }

    public function test_platform_suspension_locks_regardless_of_dates(): void
    {
        $this->subscription->update(['status' => 'suspended']);

        $this->assertTrue($this->stageFor($this->secretary)->blocksStaff());
        app(TenantContext::class)->clear();
        $this->actingAs($this->secretary)->get(route('secretary.appointments'))->assertRedirect(route('subscription.locked'));
    }

    public function test_late_payment_starts_a_full_period_on_the_payment_date(): void
    {
        $this->subscription->update(['current_period_ends_at' => now()->subDays(5)->endOfDay()]);
        $invoice = app(SubscriptionBillingService::class)->invoiceFor($this->subscription->fresh(), now()->subDays(4)->startOfDay());

        app(SubscriptionBillingService::class)->allocatePayment($invoice, (float) $invoice->total, 'mobile_money', 'MM-9', $this->admin->id);

        $subscription = $this->subscription->fresh();
        $this->assertSame('active', $subscription->status);
        $this->assertSame(now()->toDateString(), $subscription->current_period_starts_at->toDateString());
        $this->assertSame(now()->addMonth()->subDay()->toDateString(), $subscription->current_period_ends_at->toDateString());
        $this->assertSame(now()->toDateString(), $invoice->fresh()->period_start->toDateString());
        $this->assertFalse($this->stageFor($this->secretary)->blocksStaff());
    }

    public function test_payment_before_expiry_continues_the_current_cycle(): void
    {
        $end = now()->addDays(3)->endOfDay();
        $this->subscription->update(['current_period_ends_at' => $end]);
        $invoice = app(SubscriptionBillingService::class)->invoiceFor($this->subscription->fresh());

        app(SubscriptionBillingService::class)->allocatePayment($invoice, (float) $invoice->total, 'bank_transfer', 'BT-1', $this->admin->id);

        // The next period starts the day after the old one ends and lasts a full month.
        $this->assertSame($end->copy()->addDay()->toDateString(), $invoice->period_start->toDateString());
        $this->assertSame($invoice->period_start->toDateString(), $invoice->due_date->toDateString());
        $subscription = $this->subscription->fresh();
        $this->assertSame($end->copy()->addDay()->toDateString(), $subscription->current_period_starts_at->toDateString());
        $this->assertSame($end->copy()->addDay()->addMonth()->subDay()->toDateString(), $subscription->current_period_ends_at->toDateString());
    }

    public function test_renewal_invoice_issued_under_the_old_same_day_rule_is_reused_and_realigned(): void
    {
        $end = now()->addDays(3)->endOfDay();
        $this->subscription->update(['current_period_ends_at' => $end]);
        $legacy = \App\Models\PlatformInvoice::create(['number' => 'INV-LEGACY', 'clinic_id' => $this->clinic->id,
            'clinic_subscription_id' => $this->subscription->id, 'period_start' => $end->toDateString(),
            'period_end' => $end->copy()->addMonth()->subDay()->toDateString(), 'due_date' => $end->copy()->addDays(7)->toDateString(),
            'subtotal' => 100, 'total' => 100, 'status' => 'unpaid', 'source' => 'renewal',
            'idempotency_key' => "subscription:{$this->subscription->id}:{$end->toDateString()}:monthly"]);

        $this->assertSame($legacy->id, app(SubscriptionBillingService::class)->invoiceFor($this->subscription->fresh())->id);
        $this->assertSame(1, \App\Models\PlatformInvoice::where('source', 'renewal')->count());

        (require database_path('migrations/2026_09_24_000007_align_open_renewal_invoices_with_next_day_start.php'))->up();

        $legacy->refresh();
        $this->assertSame($end->copy()->addDay()->toDateString(), $legacy->period_start->toDateString());
        $this->assertSame($end->copy()->addDay()->toDateString(), $legacy->due_date->toDateString());
        $this->assertSame($legacy->id, app(SubscriptionBillingService::class)->invoiceFor($this->subscription->fresh())->id);
    }

    public function test_online_bookings_are_rejected_once_staff_are_locked_out(): void
    {
        $this->expiredHoursAgo(3); // grace: still accepted
        $this->postJson('/api/v1/appointments', ['booking_key' => 'web-key', 'name' => 'Kofi', 'phone' => '0551234567'])->assertCreated();

        $this->expiredHoursAgo(30);
        $this->postJson('/api/v1/appointments', ['booking_key' => 'web-key', 'name' => 'Ama', 'phone' => '0551234568'])
            ->assertStatus(503)->assertJson(['success' => false]);
    }

    public function test_every_user_sees_a_renewal_banner_in_the_last_week(): void
    {
        $this->subscription->update(['current_period_ends_at' => now()->addDays(3)]);
        $this->actingAs($this->secretary)->get(route('secretary.appointments'))->assertOk()->assertSee('Renewal reminder');

        $this->subscription->update(['current_period_ends_at' => now()->addDays(20)]);
        app(TenantContext::class)->clear();
        $this->actingAs($this->secretary)->get(route('secretary.appointments'))->assertOk()->assertDontSee('Renewal reminder');
    }

    public function test_platform_admin_edits_support_contact(): void
    {
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));

        Livewire::test(SupportSettingsComponent::class)
            ->set('name', 'EyeClinic Support')->set('phone', '0302000000')->set('email', 'not-an-email')->call('save')->assertHasErrors('email')
            ->set('email', 'help@example.com')->call('save')->assertHasNoErrors();

        $this->assertSame(['EyeClinic Support', '0302000000', 'help@example.com'],
            [PlatformSetting::support()['name'], PlatformSetting::support()['phone'], PlatformSetting::support()['email']]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'PLATFORM_SUPPORT_CONTACT_UPDATED']);
    }
}
