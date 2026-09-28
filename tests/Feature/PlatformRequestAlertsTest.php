<?php

namespace Tests\Feature;

use App\Livewire\Admin\ClinicSubscriptionPortalComponent;
use App\Livewire\Admin\OwnerEmailsComponent;
use App\Livewire\Admin\SmsSettingsComponent;
use App\Livewire\Platform\SupportSettingsComponent;
use App\Mail\OwnerNoticeMail;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\OwnerEmail;
use App\Models\PlatformSetting;
use App\Models\SmsBundle;
use App\Models\SubscriptionChangeRequest;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Messaging\SmsCreditService;
use App\Services\PlatformRequestAlerts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Clinic requests only the platform can answer are emailed to one shared inbox. */
class PlatformRequestAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const INBOX = 'ops@platform.test';

    private function clinic(): array
    {
        config(['tenancy.enabled' => true]);
        PlatformSetting::put([PlatformRequestAlerts::INBOX_SETTING => self::INBOX]);
        $owner = User::factory()->create(['name' => 'Kofi Owner', 'email' => 'kofi@brighteyes.test']);
        $owner->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $clinic = Clinic::create(['name' => 'Bright Eyes', 'slug' => 'bright-eyes', 'deployment_mode' => 'hosted', 'status' => 'active', 'billing_email' => 'owner@brighteyes.test']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($owner->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($owner->id, ['status' => 'active', 'is_default' => true]);
        $basic = SubscriptionPlan::create(['name' => 'Basic', 'code' => 'basic', 'features' => ['*'], 'base_price' => 100, 'billing_interval' => 'monthly', 'is_active' => true]);
        $pro = SubscriptionPlan::create(['name' => 'Pro', 'code' => 'pro', 'features' => ['*'], 'base_price' => 250, 'billing_interval' => 'monthly', 'is_active' => true]);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $basic->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        app(TenantContext::class)->set($owner, $clinic, $branch, [$branch->id]);
        $this->actingAs($owner);

        return [$clinic, $owner, $pro];
    }

    private function toInbox(): \Illuminate\Support\Collection
    {
        return Mail::sent(OwnerNoticeMail::class)->filter(fn ($mail) => $mail->hasTo(self::INBOX))->values();
    }

    public function test_plan_change_request_is_emailed_to_the_platform_inbox_once(): void
    {
        Mail::fake();
        [, , $pro] = $this->clinic();

        Livewire::test(ClinicSubscriptionPortalComponent::class)
            ->set('requestedPlanId', $pro->id)->set('requestedInterval', 'monthly')->set('requestMessage', 'We opened a second branch')
            ->call('requestPlanChange');

        $mails = $this->toInbox();
        $this->assertCount(1, $mails);
        $this->assertSame('Bright Eyes wants to change plan', $mails[0]->heading);
        $this->assertSame(['Clinic' => 'Bright Eyes', 'Current plan' => 'Basic', 'Requested plan' => 'Pro', 'Billing' => 'Monthly',
            'Message' => 'We opened a second branch', 'Asked by' => 'Kofi Owner (kofi@brighteyes.test)'], $mails[0]->details);
        $this->assertStringContainsString('tab=billing', $mails[0]->buttonUrl);
        $this->assertStringContainsString('Platform → Support', $mails[0]->footer);
    }

    public function test_sms_order_and_sender_id_request_are_emailed_to_the_platform_inbox(): void
    {
        Mail::fake();
        [$clinic] = $this->clinic();
        $bundle = SmsBundle::create(['name' => 'Starter', 'credits' => 1000, 'price' => 150]);

        $invoice = app(SmsCreditService::class)->requestBundle($clinic, $bundle);
        Livewire::test(SmsSettingsComponent::class)->set('senderIdRequest', 'brighteyes')->call('requestSenderId');

        $headings = $this->toInbox()->pluck('heading')->all();
        $this->assertSame(['Bright Eyes ordered SMS credits', 'Bright Eyes asked for sender ID brighteyes'], $headings);
        $this->assertSame($invoice->number, $this->toInbox()[0]->details['Invoice']);
        $this->assertSame('GHS 150.00', $this->toInbox()[0]->details['Amount']);
    }

    public function test_nothing_is_sent_without_an_inbox_and_the_clinic_never_sees_platform_emails(): void
    {
        Mail::fake();
        [$clinic, , $pro] = $this->clinic();
        PlatformSetting::put([PlatformRequestAlerts::INBOX_SETTING => null]);

        Livewire::test(ClinicSubscriptionPortalComponent::class)->set('requestedPlanId', $pro->id)->set('requestedInterval', 'monthly')->call('requestPlanChange');

        $this->assertCount(0, $this->toInbox());
        $this->assertDatabaseHas('owner_emails', ['clinic_id' => $clinic->id, 'kind' => 'platform_plan_request', 'status' => 'skipped']);
        Livewire::test(OwnerEmailsComponent::class)->assertDontSee('wants to change plan');
    }

    public function test_morning_reminder_lists_requests_waiting_over_a_day_once(): void
    {
        Mail::fake();
        [$clinic, $owner, $pro] = $this->clinic();
        $old = SubscriptionChangeRequest::create(['clinic_id' => $clinic->id, 'requested_plan_id' => $pro->id, 'billing_interval' => 'monthly', 'requested_by' => $owner->id]);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        $alerts = app(PlatformRequestAlerts::class);

        $this->assertSame('sent', $alerts->remindWaiting());
        $this->assertSame('sent', $alerts->remindWaiting());   // same day: not again
        $reminders = $this->toInbox()->filter(fn ($mail) => str_contains($mail->heading, 'still waiting'));
        $this->assertCount(1, $reminders);
        $this->assertSame('1 request is still waiting', $reminders->first()->heading);
        $this->assertSame(['Plan change · Bright Eyes' => 'waiting 2 days'], $reminders->first()->details);

        // Answered: nothing waits, nothing is sent.
        $old->update(['status' => 'approved']);
        $this->travel(1)->days();
        $this->assertNull($alerts->remindWaiting());
    }

    public function test_owner_replies_reach_support_and_request_replies_reach_the_clinic_owner(): void
    {
        Mail::fake();
        [$clinic, , $pro] = $this->clinic();
        PlatformSetting::put(['support_email' => 'support@visionspacegh.com']);

        Livewire::test(ClinicSubscriptionPortalComponent::class)->set('requestedPlanId', $pro->id)->set('requestedInterval', 'monthly')->call('requestPlanChange');
        app(\App\Services\OwnerAlerts::class)->smsCreditsLow($clinic->id, 40);
        app(\App\Services\BillingNotificationService::class)->invoiceIssued(app(SmsCreditService::class)
            ->requestBundle($clinic, SmsBundle::create(['name' => 'Starter', 'credits' => 1000, 'price' => 150]))->load(['clinic', 'subscription']));

        // The platform's copy: pressing Reply writes to the clinic owner.
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo(self::INBOX) && $mail->heading === 'Bright Eyes wants to change plan'
            && $mail->hasReplyTo('owner@brighteyes.test'));
        // The owner's emails: replies reach support.
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo('owner@brighteyes.test') && $mail->hasReplyTo('support@visionspacegh.com'));
        Mail::assertSent(\App\Mail\SubscriptionBillingMail::class, fn ($mail) => $mail->build() && $mail->hasReplyTo('support@visionspacegh.com'));
    }

    public function test_without_a_separate_inbox_requests_go_to_the_support_email(): void
    {
        Mail::fake();
        [, , $pro] = $this->clinic();
        PlatformSetting::put([PlatformRequestAlerts::INBOX_SETTING => null, 'support_email' => 'support@visionspacegh.com']);

        Livewire::test(ClinicSubscriptionPortalComponent::class)->set('requestedPlanId', $pro->id)->set('requestedInterval', 'monthly')->call('requestPlanChange');

        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo('support@visionspacegh.com') && $mail->heading === 'Bright Eyes wants to change plan');
    }

    public function test_platform_admin_sets_the_requests_inbox(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        Livewire::actingAs($admin)->test(SupportSettingsComponent::class)
            ->set('name', 'EyeClinic Support')->set('requestsEmail', 'bad-address')->call('save')->assertHasErrors('requestsEmail')
            ->set('requestsEmail', 'requests@platform.test')->call('save')->assertHasNoErrors();

        $this->assertSame('requests@platform.test', PlatformRequestAlerts::inbox());
    }
}
