<?php

namespace Tests\Feature;

use App\Jobs\SendSmsMessage;
use App\Livewire\Admin\SmsSettingsComponent;
use App\Livewire\Platform\SmsMessagingComponent;
use App\Models\{Appointments, Clinic, ClinicSubscription, Patient, Setting, SmsBundle, SmsLog, SmsTemplate, SubscriptionPlan, User};
use App\Services\Messaging\{AppointmentNotifier, SmsCreditService, SmsCredentials, SmsDriver};
use App\Services\{SmsService, WhatsAppService};
use App\Support\Messaging\{AutomaticChannels, MessageCategory, PhoneNumber, SmsSegments, WhatsAppLink};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Crypt, Queue};
use Livewire\Livewire;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.eazisms' => ['url' => 'https://platform.test/sms', 'key' => 'platform-key', 'default_sender' => 'EYEPLATFORM']]);
        $this->driver = new FakeSmsDriver();
        $this->app->instance(SmsDriver::class, $this->driver);
    }

    private function tenant(int $credits = 100, string $name = 'Hosted Clinic'): array
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $clinic = Clinic::create(['name' => $name, 'slug' => str($name)->slug(), 'deployment_mode' => 'hosted']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Plan '.$name, 'code' => str($name)->slug(), 'included_branches' => 1, 'included_users' => 5,
            'storage_limit_mb' => 100, 'features' => ['*'], 'base_price' => 10, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);
        $this->actingAs($user);
        Setting::getSettings()->update(['sms_enabled' => true]);
        if ($credits > 0) {
            app(SmsCreditService::class)->add($clinic->id, $credits, 'grant', ['note' => 'Test credits']);
        }

        return compact('user', 'clinic', 'branch');
    }

    private function giveRole(array $tenant, string $role): void
    {
        $model = \Spatie\Permission\Models\Role::findOrCreate($role, 'web');
        \Illuminate\Support\Facades\DB::table('branch_user_role')->insert(['branch_id' => $tenant['branch']->id,
            'user_id' => $tenant['user']->id, 'role_id' => $model->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function credits(array $tenant): int
    {
        return app(SmsCreditService::class)->balance($tenant['clinic']->id);
    }

    public function test_segments_count_gsm_and_unicode_parts(): void
    {
        $this->assertSame(1, SmsSegments::count(str_repeat('a', 160)));
        $this->assertSame(2, SmsSegments::count(str_repeat('a', 161)));
        $this->assertSame(3, SmsSegments::count(str_repeat('a', 307)));
        $this->assertSame(1, SmsSegments::count(str_repeat('€', 80)));      // extended GSM counts double
        $this->assertSame(2, SmsSegments::count(str_repeat('€', 81)));
        $this->assertSame(1, SmsSegments::count(str_repeat('ɛ', 70)));      // Twi character forces UCS-2
        $this->assertSame(2, SmsSegments::count(str_repeat('ɛ', 71)));
        $this->assertSame('233241234567', PhoneNumber::normalize('024 123 4567'));
        $this->assertSame('233241234567', PhoneNumber::normalize('+233 24 123 4567'));
        $this->assertSame(MessageCategory::MARKETING, MessageCategory::for('birthday_wishes'));
        $this->assertSame(MessageCategory::TRANSACTIONAL, MessageCategory::for('appointment_booking'));
    }

    public function test_hosted_clinic_uses_platform_gateway_and_default_sender_until_approved(): void
    {
        $this->tenant();

        $result = app(SmsService::class)->send('0241234567', 'Hello');

        $this->assertTrue($result['success']);
        $this->assertSame('platform-key', $this->driver->sent[0]['credentials']->key);
        $this->assertSame('EYEPLATFORM', $this->driver->sent[0]['credentials']->sender);
        $this->assertDatabaseHas('sms_logs', ['recipient' => '233241234567', 'status' => 'sent', 'success' => true, 'sender_id' => 'EYEPLATFORM']);

        // A requested sender ID is not used until the platform approves it.
        Setting::getSettings()->update(['sms_sender_id_requested' => 'MYCLINIC', 'sms_sender_id_status' => 'pending']);
        app(SmsService::class)->send('0241234567', 'Hello again');
        $this->assertSame('EYEPLATFORM', $this->driver->sent[1]['credentials']->sender);

        Setting::getSettings()->update(['sms_sender_id' => 'MYCLINIC', 'sms_sender_id_status' => 'approved']);
        app(SmsService::class)->send('0241234567', 'Hello approved');
        $this->assertSame('MYCLINIC', $this->driver->sent[2]['credentials']->sender);

        // Requesting a change keeps the approved sender in use until the new one is approved.
        Setting::getSettings()->update(['sms_sender_id_requested' => 'NEWNAME', 'sms_sender_id_status' => 'pending']);
        app(SmsService::class)->send('0241234567', 'Hello pending change');
        $this->assertSame('MYCLINIC', $this->driver->sent[3]['credentials']->sender);
    }

    public function test_hosted_sends_are_queued_with_tenant_context_and_branch(): void
    {
        $tenant = $this->tenant();
        config(['queue.default' => 'database']);
        \Illuminate\Support\Facades\Cache::put(\App\Services\Messaging\MessageDispatcher::WORKER_HEARTBEAT, time(), 300);
        Queue::fake();

        $result = app(SmsService::class)->send('0241234567', 'Queued hello');

        $this->assertTrue($result['queued']);
        Queue::assertPushed(SendSmsMessage::class, fn ($job) => $job->smsLogId === $result['log_id']);
        $this->assertDatabaseHas('sms_logs', ['id' => $result['log_id'], 'status' => 'queued',
            'clinic_id' => $tenant['clinic']->id, 'branch_id' => $tenant['branch']->id]);
        $this->assertCount(0, $this->driver->sent);

        (new SendSmsMessage($result['log_id']))->handle(app(SmsService::class));

        $this->assertCount(1, $this->driver->sent);
        $this->assertDatabaseHas('sms_logs', ['id' => $result['log_id'], 'status' => 'sent', 'provider_message_id' => 'msg-1', 'attempts' => 1]);
    }

    public function test_hosted_sends_go_out_at_once_when_no_queue_worker_is_running(): void
    {
        $this->tenant();
        config(['queue.default' => 'database']);
        Queue::fake();

        // No worker has checked in (or its last check-in is stale): queuing would strand the SMS.
        \Illuminate\Support\Facades\Cache::put(\App\Services\Messaging\MessageDispatcher::WORKER_HEARTBEAT, time() - 600, 300);
        $result = app(SmsService::class)->send('0241234567', 'Your glasses are ready');

        $this->assertFalse($result['queued']);
        Queue::assertNothingPushed();
        $this->assertCount(1, $this->driver->sent);
        $this->assertDatabaseHas('sms_logs', ['id' => $result['log_id'], 'status' => 'sent']);
    }

    public function test_credits_are_charged_per_sms_part_and_sending_stops_when_exhausted(): void
    {
        $tenant = $this->tenant(credits: 3);
        $this->giveRole($tenant, 'Super Admin');

        $this->assertTrue(app(SmsService::class)->send('0241234567', str_repeat('a', 200))['success']); // 2 parts
        $this->assertSame(1, $this->credits($tenant));
        $this->assertDatabaseHas('sms_credit_transactions', ['clinic_id' => $tenant['clinic']->id, 'type' => 'usage', 'credits' => -2, 'balance_after' => 1]);

        $blocked = app(SmsService::class)->send('0241234567', str_repeat('a', 200));
        $this->assertFalse($blocked['success']);
        $this->assertStringContainsString('only 1 are left', $blocked['error']);
        $this->assertSame(1, $this->credits($tenant));

        $this->assertTrue(app(SmsService::class)->send('0241234567', 'short')['success']);
        $this->assertSame(0, $this->credits($tenant));
        $this->assertFalse(app(SmsService::class)->send('0241234567', 'short')['success']);
        $this->assertSame(1, \App\Models\AppNotification::where('type', 'sms_credits_exhausted')->count()); // once per day
        $this->assertCount(2, $this->driver->sent);
    }

    public function test_undelivered_sms_gets_its_credits_back(): void
    {
        $tenant = $this->tenant(credits: 10);

        $this->driver->result = ['success' => false, 'error' => 'Invalid number'];
        $failed = app(SmsService::class)->send('0241234567', 'Hello');
        $this->assertFalse($failed['success']);
        $this->assertSame(10, $this->credits($tenant));
        $this->assertDatabaseHas('sms_logs', ['id' => $failed['log_id'], 'status' => 'failed', 'charged_credits' => 0]);
        $this->assertDatabaseHas('sms_credit_transactions', ['type' => 'refund', 'credits' => 1, 'sms_log_id' => $failed['log_id']]);

        // A connection error keeps the credit while the queue retries, and returns it when it gives up.
        config(['queue.default' => 'database']);
        \Illuminate\Support\Facades\Cache::put(\App\Services\Messaging\MessageDispatcher::WORKER_HEARTBEAT, time(), 300);
        Queue::fake();
        $this->driver->result = ['success' => false, 'error' => 'Timeout', 'retryable' => true];
        $queued = app(SmsService::class)->send('0241234567', 'Hello again');
        (new SendSmsMessage($queued['log_id']))->handle(app(SmsService::class));
        $this->assertSame(9, $this->credits($tenant));
        $this->assertDatabaseHas('sms_logs', ['id' => $queued['log_id'], 'status' => 'queued']);

        app(SmsService::class)->deliver(SmsLog::find($queued['log_id']), finalAttempt: true);
        $this->assertSame(10, $this->credits($tenant));
        app(SmsCreditService::class)->refund(SmsLog::find($queued['log_id'])); // idempotent
        $this->assertSame(10, $this->credits($tenant));
    }

    public function test_bundle_purchase_adds_credits_once_after_payment_and_is_not_a_debt(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $tenant = $this->tenant(credits: 0);
        $bundle = SmsBundle::create(['name' => 'Starter', 'credits' => 500, 'price' => 50, 'currency' => 'GHS']);

        $invoice = app(SmsCreditService::class)->requestBundle($tenant['clinic'], $bundle);
        $this->assertSame(['sms_bundle', 'unpaid', 500], [$invoice->source, $invoice->status, $invoice->sms_credits]);
        $this->assertSame(0, $this->credits($tenant));

        app(\App\Services\SubscriptionBillingService::class)->allocatePayment($invoice, (float) $invoice->total, 'mobile_money', 'MM-1', $tenant['user']->id);
        $this->assertSame(500, $this->credits($tenant));
        app(SmsCreditService::class)->creditFromInvoice($invoice->fresh());
        $this->assertSame(500, $this->credits($tenant));

        // An unpaid request past its due date is not chased as a debt and can be cancelled by the clinic.
        $unpaid = app(SmsCreditService::class)->requestBundle($tenant['clinic'], $bundle);
        $this->travel(10)->days();
        app(\App\Services\SubscriptionCollectionService::class)->synchronize();
        $this->assertDatabaseMissing('subscription_collection_cases', ['platform_invoice_id' => $unpaid->id]);
        app(SmsCreditService::class)->cancelRequest($unpaid, $tenant['clinic']->id);
        $this->assertSame('void', $unpaid->fresh()->status);
    }

    public function test_clinic_admins_are_warned_once_when_credits_run_low(): void
    {
        $tenant = $this->tenant(credits: 25); // warning level: max(20, 20% of 25)
        $this->giveRole($tenant, 'Super Admin');

        foreach (range(1, 5) as $i) app(SmsService::class)->send('0241234567', "Message {$i}");
        $this->assertSame(0, \App\Models\AppNotification::where('type', 'sms_credits_low')->count());

        app(SmsService::class)->send('0241234567', 'Message 6'); // 19 left
        app(SmsService::class)->send('0241234567', 'Message 7');
        $this->assertSame(1, \App\Models\AppNotification::where('type', 'sms_credits_low')->count());

        app(SmsCreditService::class)->add($tenant['clinic']->id, 100, 'grant');
        $this->assertNull(\App\Models\SmsWallet::where('clinic_id', $tenant['clinic']->id)->value('low_balance_notified_at'));
    }

    public function test_platform_balance_check_flags_when_provider_cannot_cover_prepaid_credits(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->tenant(credits: 100);
        config(['services.eazisms.low_balance' => 10]);

        $status = app(\App\Services\Messaging\PlatformSmsBalance::class)->check(); // fake provider balance is 42

        $this->assertSame([42, 100, 100, true], [$status['balance'], $status['outstanding'], $status['threshold'], $status['low']]);
        $this->assertTrue(app(\App\Services\Messaging\PlatformSmsBalance::class)->last()['low']);
        // The alert goes to the platform's one inbox (no support email is set here, so it's logged as not sent).
        $this->assertDatabaseHas('owner_emails', ['kind' => 'platform_sms_balance', 'status' => 'skipped', 'clinic_id' => null]);
    }

    public function test_platform_sms_balance_alert_goes_to_the_support_inbox(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        \App\Models\PlatformSetting::put(['support_email' => 'support@visionspacegh.com']);
        $this->tenant(credits: 100);
        config(['services.eazisms.low_balance' => 10]);

        app(\App\Services\Messaging\PlatformSmsBalance::class)->check();

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OwnerNoticeMail::class, fn ($mail) => $mail->hasTo('support@visionspacegh.com')
            && $mail->heading === 'Platform SMS balance is low' && $mail->details['Provider balance'] === '42');
    }

    public function test_platform_admin_grants_credits_and_records_bundle_payment(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $tenant = $this->tenant(credits: 0);
        $invoice = app(SmsCreditService::class)->requestBundle($tenant['clinic'],
            SmsBundle::create(['name' => 'Clinic pack', 'credits' => 1000, 'price' => 90, 'currency' => 'GHS']));
        app(TenantContext::class)->clear();
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));

        Livewire::test(SmsMessagingComponent::class)
            ->set('creditClinicId', $tenant['clinic']->id)->set('creditAmount', 50)->set('creditNote', 'Launch goodwill')
            ->call('adjustCredits')->assertHasNoErrors()
            ->set('creditClinicId', $tenant['clinic']->id)->set('creditAmount', -60)->set('creditNote', 'Too much')->call('adjustCredits')->assertHasErrors('credits')
            ->call('markBundlePaid', $invoice->id)->assertHasErrors('paymentMethods.'.$invoice->id)
            ->set('paymentMethods.'.$invoice->id, 'mobile_money')->call('markBundlePaid', $invoice->id)->assertHasNoErrors()
            ->set('bundleName', 'Mega')->set('bundleCredits', 10000)->set('bundlePrice', '1500')->call('saveBundle')->assertHasNoErrors();

        $this->assertSame(1050, $this->credits($tenant));
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'SMS_CREDITS_ADJUSTED', 'clinic_id' => $tenant['clinic']->id]);
        $this->assertTrue(SmsBundle::where('name', 'Mega')->exists());
    }

    public function test_opted_out_patients_are_skipped_per_category(): void
    {
        $tenant = $this->tenant();
        $patient = Patient::factory()->create(['user_id' => $tenant['user']->id, 'marketing_opt_out' => true]);

        $marketing = app(SmsService::class)->send('0241234567', 'Happy birthday', $patient->id, 'birthday_wishes');
        $transactional = app(SmsService::class)->send('0241234567', 'Your appointment', $patient->id, 'appointment_booking');

        $this->assertTrue($marketing['skipped']);
        $this->assertTrue($transactional['success']);
        $this->assertDatabaseHas('sms_logs', ['template_key' => 'birthday_wishes', 'status' => 'skipped']);

        $patient->update(['sms_opt_out' => true]);
        $this->assertTrue(app(SmsService::class)->send('0241234567', 'Your appointment', $patient->id, 'appointment_booking')['skipped']);
        $this->assertCount(1, $this->driver->sent);
    }

    public function test_whatsapp_is_queued_and_not_metered(): void
    {
        $tenant = $this->tenant(credits: 0);
        Setting::getSettings()->update(['whatsapp_enabled' => true, 'whatsapp_phone_number_id' => '123', 'whatsapp_access_token' => Crypt::encryptString('t')]);
        config(['queue.default' => 'database']);
        \Illuminate\Support\Facades\Cache::put(\App\Services\Messaging\MessageDispatcher::WORKER_HEARTBEAT, time(), 300);
        Queue::fake();

        $result = app(WhatsAppService::class)->sendTemplate('0241234567', 'appointment_reminder', 'en', ['Ama'], null, 'appointment_reminder');

        $this->assertTrue($result['queued']);
        Queue::assertPushed(\App\Jobs\SendWhatsAppMessage::class);
        $this->assertSame(0, $this->credits($tenant));
        $this->assertDatabaseMissing('sms_credit_transactions', ['type' => 'usage']);
    }

    public function test_sms_logs_are_isolated_between_clinics(): void
    {
        $this->tenant(name: 'First Clinic');
        app(SmsService::class)->send('0241234567', 'First clinic message');

        app(TenantContext::class)->clear();
        $this->tenant(name: 'Second Clinic');

        $this->assertSame(0, SmsLog::count());
    }

    public function test_templates_fall_back_to_defaults_and_fill_branch_placeholders(): void
    {
        $tenant = $this->tenant(name: 'Clear Sight');
        $tenant['branch']->update(['name' => 'Kumasi', 'contact' => '0320000000']);

        // New SaaS clinics have no template rows yet.
        $this->assertSame(0, SmsTemplate::count());
        $this->assertStringContainsString('Hello Ama, your appointment at Clear Sight', SmsTemplate::render('appointment_booking', ['[NAME]' => 'Ama']));

        SmsTemplate::create(['key' => 'appointment_booking', 'label' => 'Booking', 'placeholders' => [], 'message' => '[NAME] booked at [BRANCH] ([BRANCH_PHONE])']);
        $this->assertSame('Ama booked at Kumasi (0320000000)', SmsTemplate::render('appointment_booking', ['[NAME]' => 'Ama']));

        $other = $tenant['clinic']->branches()->create(['code' => 'ACC', 'name' => 'Accra', 'is_active' => true]);
        $this->assertSame('Ama booked at Accra ()', SmsTemplate::render('appointment_booking', ['[NAME]' => 'Ama'], $other));

        SmsTemplate::ensureDefaults();
        $this->assertTrue(SmsTemplate::where('key', 'online_booking_received')->exists());
        $this->assertSame('[NAME] booked at [BRANCH] ([BRANCH_PHONE])', SmsTemplate::where('key', 'appointment_booking')->value('message'));
    }

    public function test_online_booking_acknowledges_patient_and_alerts_front_desk(): void
    {
        $tenant = $this->tenant();
        $role = \Spatie\Permission\Models\Role::findOrCreate('Secretary', 'web');
        \Illuminate\Support\Facades\DB::table('branch_user_role')->insert(['branch_id' => $tenant['branch']->id,
            'user_id' => $tenant['user']->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        $tenant['branch']->update(['public_booking_key' => 'web-key']);
        app(TenantContext::class)->clear();

        $this->postJson('/api/v1/appointments', ['booking_key' => 'web-key', 'name' => 'Kofi', 'phone' => '0551234567'])->assertCreated();

        $this->assertSame('233551234567', $this->driver->sent[0]['to']);
        $this->assertStringContainsString('Hello Kofi, we have received your booking request', $this->driver->sent[0]['message']);
        $this->assertDatabaseHas('sms_logs', ['template_key' => 'online_booking_received', 'status' => 'sent',
            'clinic_id' => $tenant['clinic']->id, 'branch_id' => $tenant['branch']->id]);
        $this->assertDatabaseHas('app_notifications', ['type' => 'online_booking', 'user_id' => $tenant['user']->id]);
    }

    public function test_platform_can_approve_a_sender_id_under_the_exact_spelling_the_network_registered(): void
    {
        $this->tenant();
        $setting = Setting::getSettings();
        $setting->update(['sms_sender_id_requested' => 'VISIONSPACE', 'sms_sender_id_status' => 'pending']);
        app(TenantContext::class)->clear();
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));

        Livewire::test(SmsMessagingComponent::class)->set("approveAs.{$setting->id}", 'Vision!')->call('approve', $setting->id)
            ->assertHasErrors("approveAs.{$setting->id}");
        Livewire::test(SmsMessagingComponent::class)->set("approveAs.{$setting->id}", 'VisionSpace')->call('approve', $setting->id)->assertHasNoErrors();

        $this->assertSame(['VisionSpace', 'approved'], [$setting->fresh()->sms_sender_id, $setting->fresh()->sms_sender_id_status]);
    }

    public function test_platform_approves_and_rejects_sender_id_requests(): void
    {
        $tenant = $this->tenant();
        $setting = Setting::getSettings();
        $setting->update(['sms_sender_id_requested' => 'CLEARSIGHT', 'sms_sender_id_status' => 'pending']);
        app(TenantContext::class)->clear();

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin);

        Livewire::test(SmsMessagingComponent::class)->call('approve', $setting->id)->assertHasNoErrors();
        $setting->refresh();
        $this->assertSame(['CLEARSIGHT', 'approved'], [$setting->sms_sender_id, $setting->sms_sender_id_status]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'SMS_SENDER_ID_APPROVED', 'clinic_id' => $tenant['clinic']->id]);

        // A rejected change request leaves the approved sender in place.
        $setting->update(['sms_sender_id_requested' => 'FREEMONEY', 'sms_sender_id_status' => 'pending']);
        Livewire::test(SmsMessagingComponent::class)
            ->call('reject', $setting->id)->assertHasErrors('rejectNotes.'.$setting->id)
            ->set('rejectNotes.'.$setting->id, 'Misleading sender name')->call('reject', $setting->id)->assertHasNoErrors();
        $setting->refresh();
        $this->assertSame(['CLEARSIGHT', 'rejected', 'Misleading sender name'], [$setting->sms_sender_id, $setting->sms_sender_id_status, $setting->sms_sender_id_note]);
    }

    public function test_platform_sets_or_resets_a_clinic_sender_id_without_a_request(): void
    {
        $tenant = $this->tenant();
        $setting = Setting::getSettings();
        $setting->update(['sms_sender_id' => 'OLDNAME', 'sms_sender_id_requested' => 'WANTED', 'sms_sender_id_status' => 'pending']);
        app(TenantContext::class)->clear();
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));
        $clinicId = $tenant['clinic']->id;

        Livewire::test(SmsMessagingComponent::class)->call('setSection', 'senders')->assertSee('Clinic sender IDs')
            ->call('editSender', $clinicId)->assertSet('senderOverride', 'OLDNAME')
            ->set('senderOverride', 'Bad!')->call('saveSender')->assertHasErrors('senderOverride')
            ->set('senderOverride', ' EyeCare GH ')->call('saveSender')->assertHasNoErrors()->assertSet('editingSenderClinicId', null);

        // The override replaces the pending request and is used for sending straight away.
        $setting->refresh();
        $this->assertSame(['EyeCare GH', 'EyeCare GH', 'approved'], [$setting->sms_sender_id, $setting->sms_sender_id_requested, $setting->sms_sender_id_status]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'SMS_SENDER_ID_OVERRIDDEN', 'clinic_id' => $clinicId]);

        Livewire::test(SmsMessagingComponent::class)->call('resetSender', $clinicId)->assertHasNoErrors();
        $setting->refresh();
        $this->assertSame([null, 'none'], [$setting->sms_sender_id, $setting->sms_sender_id_status]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'SMS_SENDER_ID_RESET', 'clinic_id' => $clinicId]);

        // Offline clinics use their own gateway: the platform cannot set their sender.
        $offline = Clinic::create(['name' => 'Offline Clinic', 'slug' => 'offline-clinic', 'deployment_mode' => 'local']);
        Livewire::test(SmsMessagingComponent::class)->call('editSender', $offline->id)->assertStatus(404);
    }

    public function test_whatsapp_links_use_international_numbers_and_automatic_channels_fall_back_to_sms(): void
    {
        $this->assertSame('https://wa.me/233241234567?text=Hi%20Ama%20%26%20co', WhatsAppLink::to('024 123 4567', 'Hi Ama & co'));
        $this->assertSame('https://wa.me/233241234567', WhatsAppLink::to('+233241234567'));
        $this->assertNull(WhatsAppLink::to(''));
        $this->assertNull(WhatsAppLink::to('n/a'));

        $noMeta = new Setting(['whatsapp_enabled' => false]);
        $meta = new Setting(['whatsapp_enabled' => true, 'whatsapp_phone_number_id' => '1', 'whatsapp_access_token' => 'x']);
        $this->assertSame(['sms' => true, 'whatsapp' => false], AutomaticChannels::pick('whatsapp', $noMeta, 'tpl'));
        $this->assertSame(['sms' => true, 'whatsapp' => false], AutomaticChannels::pick('whatsapp', $meta, null));
        $this->assertSame(['sms' => false, 'whatsapp' => true], AutomaticChannels::pick('whatsapp', $meta, 'tpl'));
        $this->assertSame(['sms' => true, 'whatsapp' => true], AutomaticChannels::pick('both', $meta, 'tpl'));
        $this->assertSame(['sms' => true, 'whatsapp' => false], AutomaticChannels::pick(null, $meta, 'tpl'));
        $this->assertSame(['sms' => false, 'whatsapp' => false], AutomaticChannels::pick('none', $meta, 'tpl'));
    }

    public function test_whatsapp_reminders_are_sent_by_sms_when_meta_is_not_set_up(): void
    {
        $tenant = $this->tenant();
        $patient = Patient::factory()->create(['user_id' => $tenant['user']->id, 'contact' => '0241234567']);
        $appointment = Appointments::factory()->create(['patient_id' => $patient->id, 'user_id' => $tenant['user']->id,
            'scheduled_at' => now()->addDay(), 'status' => 'Pending', 'reminder_channel' => 'whatsapp']);

        $this->artisan('sms:appointment-reminders')->assertSuccessful();

        $this->assertCount(1, $this->driver->sent);
        $this->assertDatabaseHas('sms_logs', ['template_key' => 'appointment_auto_reminder', 'channel' => 'sms', 'status' => 'sent']);
        $this->assertNotNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_whatsapp_booking_confirmation_skips_sms_and_returns_message_for_link(): void
    {
        $tenant = $this->tenant(name: 'Clear Sight');
        $patient = Patient::factory()->create(['user_id' => $tenant['user']->id, 'name' => 'Ama', 'contact' => '0241234567', 'email' => null]);
        $appointment = Appointments::factory()->create(['patient_id' => $patient->id, 'user_id' => $tenant['user']->id,
            'scheduled_at' => now()->addDays(3), 'title' => 'Eye exam']);

        $message = app(AppointmentNotifier::class)->confirmed($appointment, sendSms: false);

        $this->assertStringContainsString('Hello Ama, your appointment at Clear Sight is confirmed', $message);
        $this->assertCount(0, $this->driver->sent);

        app(AppointmentNotifier::class)->confirmed($appointment);
        $this->assertCount(1, $this->driver->sent);
    }

    public function test_appointments_page_renders_template_based_whatsapp_links(): void
    {
        $tenant = $this->tenant();
        foreach (['Secretary', 'Doctor'] as $role) \Spatie\Permission\Models\Role::findOrCreate($role, 'web');
        $tenant['user']->assignRole('Secretary');
        SmsTemplate::create(['key' => 'appointment_reminder', 'label' => 'Reminder', 'placeholders' => [], 'message' => 'Hi [NAME], see you [DATE] at [BRANCH]']);
        $patient = Patient::factory()->create(['user_id' => $tenant['user']->id, 'name' => 'Ama', 'contact' => '0241234567']);
        $appointment = Appointments::factory()->create(['patient_id' => $patient->id, 'user_id' => $tenant['user']->id,
            'scheduled_at' => now()->addHour(), 'status' => 'Pending']);

        $component = Livewire::test(\App\Livewire\Secretary\AppointmentsComponent::class)->assertOk();

        $expected = 'https://wa.me/233241234567?text=' . rawurlencode('Hi Ama, see you ' . $appointment->scheduled_at->format('M d, Y') . ' at Main');
        $this->assertSame($expected, $component->instance()->reminderWhatsAppUrl($appointment->fresh()));
        $component->assertSeeHtml('https://wa.me/233241234567?text=');
    }

    public function test_bundles_start_at_0_20_to_0_12_per_sms_and_the_platform_edits_the_range(): void
    {
        // The starting catalogue runs from 0.20 per SMS (small packs) down to 0.12 (large).
        $this->assertSame(['Starter', 'Basic', 'Standard', 'Professional', 'Enterprise'], SmsBundle::active()->pluck('name')->all());
        $this->assertEqualsWithDelta([0.20, 0.18, 0.16, 0.14, 0.12], SmsBundle::active()->get()->map->perCredit()->all(), 0.0001);
        $this->assertTrue(SmsBundle::all()->every->withinRange());

        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));
        $component = Livewire::test(SmsMessagingComponent::class)->call('setSection', 'bundles')
            ->assertSet('minPerCredit', '0.120')->assertSet('maxPerCredit', '0.200');

        // Outside the range: 1,000 credits for GHS 100 is 0.10 per SMS, for GHS 250 is 0.25.
        $component->set('bundleName', 'Cheap')->set('bundleCredits', 1000)->set('bundlePrice', '100')->call('saveBundle')->assertHasErrors('bundlePrice');
        $component->set('bundlePrice', '250')->call('saveBundle')->assertHasErrors('bundlePrice');
        $this->assertFalse(SmsBundle::where('name', 'Cheap')->exists());
        $component->set('bundlePrice', '150')->call('saveBundle')->assertHasNoErrors();
        $this->assertTrue(SmsBundle::where('name', 'Cheap')->exists());

        // Editing a default bundle's price is checked the same way.
        $basic = SmsBundle::where('name', 'Basic')->first();
        $component->call('editBundle', $basic->id)->set('bundlePrice', '190')->call('saveBundle')->assertHasNoErrors();
        $this->assertSame('190.00', $basic->fresh()->price);

        // The range itself is editable; a narrower one flags bundles now outside it.
        $component->set('minPerCredit', '0.20')->set('maxPerCredit', '0.10')->call('savePriceRange')->assertHasErrors('maxPerCredit');
        $component->set('minPerCredit', '0.15')->set('maxPerCredit', '0.25')->call('savePriceRange')->assertHasNoErrors();
        $this->assertSame([0.15, 0.25], SmsBundle::priceRange());
        $this->assertFalse(SmsBundle::where('name', 'Enterprise')->first()->withinRange());
        $component->assertSee('Outside range');
        $component->set('bundleName', 'Cheap')->set('bundleCredits', 1000)->set('bundlePrice', '250')->call('saveBundle')->assertHasNoErrors();
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'SMS_PRICE_RANGE_UPDATED']);
    }

    public function test_a_custom_amount_buys_credits_at_the_best_rate_it_can_afford(): void
    {
        // Below the smallest bundle the smallest bundle's rate applies; each bundle price unlocks its rate.
        $credits = fn (float $amount) => SmsBundle::quoteFor($amount)['credits'];
        $this->assertSame(250, $credits(50));        // 0.20
        $this->assertSame(899, $credits(179.99));    // still 0.20, rounded down
        $this->assertSame(1000, $credits(180));      // Basic, 0.18
        $this->assertSame(1388, $credits(250));      // 0.18
        $this->assertSame(2500, $credits(400));      // Standard, 0.16
        $this->assertSame(10000, $credits(1200));    // Enterprise, 0.12
        // A pricier bundle with a worse rate never lowers what an amount buys.
        SmsBundle::create(['name' => 'Odd', 'credits' => 1000, 'price' => 300]);
        $this->assertSame(1777, $credits(320));    // Basic, not Odd

        \Illuminate\Support\Facades\Mail::fake();
        $tenant = $this->tenant(credits: 0);
        \Spatie\Permission\Models\Role::findOrCreate('Super Admin', 'web');
        $tenant['user']->assignRole('Super Admin');

        $component = Livewire::test(SmsSettingsComponent::class)->assertSee('OR ENTER AN AMOUNT')
            ->call('requestTopUp', 49)->assertHasErrors('topUpAmount')
            ->call('requestTopUp', 1201)->assertHasErrors('topUpAmount')
            ->call('requestTopUp', 'abc')->assertHasErrors('topUpAmount');
        $this->assertSame(0, \App\Models\PlatformInvoice::count());

        // The server works the credits out itself; the browser only sends the amount.
        $component->call('requestTopUp', '250')->assertHasNoErrors();
        $invoice = \App\Models\PlatformInvoice::where('source', 'sms_bundle')->sole();
        $this->assertSame(['250.00', 1388, null], [$invoice->subtotal, $invoice->sms_credits, $invoice->sms_bundle_id]);
        $this->assertStringContainsString('0.180 per SMS', $invoice->notes);

        // Paying exactly a bundle's price is that bundle.
        $component->call('requestTopUp', 400)->assertHasNoErrors();
        $bundleInvoice = \App\Models\PlatformInvoice::latest('id')->first();
        $this->assertSame([2500, SmsBundle::where('name', 'Standard')->value('id')], [$bundleInvoice->sms_credits, $bundleInvoice->sms_bundle_id]);

        // Once paid, the custom invoice adds its credits like any bundle.
        $invoice->update(['amount_paid' => $invoice->total, 'status' => 'paid']);
        app(SmsCreditService::class)->creditFromInvoice($invoice);
        $this->assertSame(1388, $this->credits($tenant));
    }

    public function test_clinic_admin_sees_credits_and_requests_a_bundle(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $tenant = $this->tenant(credits: 40);
        \Spatie\Permission\Models\Role::findOrCreate('Super Admin', 'web');
        $tenant['user']->assignRole('Super Admin');
        $bundle = SmsBundle::create(['name' => 'Starter', 'credits' => 500, 'price' => 50]);
        SmsBundle::create(['name' => 'Retired', 'credits' => 5, 'price' => 1, 'is_active' => false]);

        $component = Livewire::test(SmsSettingsComponent::class)
            ->assertSee('SMS credits left')->assertSee('40')->assertSee('Starter')->assertDontSee('Retired')
            ->call('buyBundle', $bundle->id)->assertHasNoErrors()
            ->assertSee('500 credits</strong> awaiting payment', false);

        $invoice = \App\Models\PlatformInvoice::where('source', 'sms_bundle')->sole();
        $this->assertSame([$tenant['clinic']->id, 500, 'unpaid'], [$invoice->clinic_id, $invoice->sms_credits, $invoice->status]);
        $this->assertSame(40, $this->credits($tenant));

        $component->call('cancelBundleRequest', $invoice->id)->assertHasNoErrors();
        $this->assertSame('void', $invoice->fresh()->status);
    }

    public function test_hosted_clinic_cannot_save_gateway_credentials_or_sender_directly(): void
    {
        $tenant = $this->tenant();
        \Spatie\Permission\Models\Role::findOrCreate('Super Admin');
        $tenant['user']->assignRole('Super Admin');

        Livewire::test(SmsSettingsComponent::class)
            ->assertSet('platformManaged', true)
            ->set('smsApiUrl', 'https://evil.test')->set('smsSenderId', 'BANK')->call('save')->assertForbidden();

        Livewire::test(SmsSettingsComponent::class)
            ->set('senderIdRequest', 'Clear Sight')->call('requestSenderId')->assertHasNoErrors();
        $this->assertSame(['Clear Sight', 'pending', null], [Setting::getSettings()->sms_sender_id_requested,
            Setting::getSettings()->sms_sender_id_status, Setting::getSettings()->sms_sender_id]);
    }

    private function readyOpticalOrder(User $user, string $name = 'Ama Optical', string $phone = '0241112222'): \App\Models\LensOrder
    {
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => $name, 'contact' => $phone, 'gender' => 'Other']);
        $order = app(\App\Services\OpticalOrderService::class)->create([
            'patient_id' => $patient->id,
            'measurements' => ['od' => ['sph' => '+1.00'], 'os' => ['sph' => '+0.75']],
            'frame_model_number' => 'Frame A', 'frame_price' => 200, 'lens_price' => 100,
            'glazing_fee' => 0, 'discount_amount' => 0, 'paid_amount' => 100, 'payment_method' => 'cash',
            'pickup_date' => now()->addWeek()->toDateString(),
        ]);
        app(\App\Services\OpticalOrderWorkflowService::class)->transition($order->id, 'In Production');
        return $order;
    }

    public function test_optical_ready_sms_is_sent_on_ready_and_collections_page_offers_sms_and_whatsapp(): void
    {
        $tenant = $this->tenant(10);
        $order = $this->readyOpticalOrder($tenant['user']);
        $workflow = app(\App\Services\OpticalOrderWorkflowService::class);

        $workflow->transition($order->id, 'Ready for Collection');
        $this->assertTrue($workflow->lastReadySms['success']);
        $log = SmsLog::where('template_key', 'spectacles_ready')->latest('id')->firstOrFail();
        $this->assertSame('233241112222', $log->recipient);
        $this->assertStringContainsString($order->order_id, $log->message);
        $order->refresh();
        $this->assertNotNull($order->ready_at);
        $this->assertNotNull($order->ready_notified_at);

        Livewire::test(\App\Livewire\Optical\OpticalCollectionsComponent::class)
            ->assertSee($order->order_id)->assertSee('SMS active')->assertSee('GH₵ 200.00')
            ->assertSee('https://wa.me/233241112222', false)
            ->assertSeeHtml("sendSms({$order->id}, 'spectacles_reminder')")
            ->call('sendSms', $order->id, 'spectacles_reminder')->assertHasNoErrors()
            ->call('recordWhatsApp', $order->id, 'spectacles_reminder');
        $this->assertSame(2, $order->fresh()->collection_reminders_sent);
        $this->assertSame(1, SmsLog::where('template_key', 'spectacles_reminder')->count());
    }

    public function test_optical_sms_button_hidden_without_credits_but_whatsapp_still_offered(): void
    {
        $tenant = $this->tenant(0);
        $order = $this->readyOpticalOrder($tenant['user']);
        $workflow = app(\App\Services\OpticalOrderWorkflowService::class);
        $workflow->transition($order->id, 'Ready for Collection');

        $this->assertFalse($workflow->lastReadySms['success']);
        $this->assertSame('Ready for Collection', $order->fresh()->status, 'A failed SMS never blocks the status change.');
        $this->assertNull($order->fresh()->ready_notified_at);
        Livewire::test(\App\Livewire\Optical\OpticalCollectionsComponent::class)
            ->assertSee('SMS not available')->assertSee('No SMS credits left')
            ->assertSee('https://wa.me/233241112222', false)
            ->assertDontSeeHtml("sendSms({$order->id}");
        Livewire::test(\App\Livewire\Optical\OpticalOrdersComponent::class)
            ->assertSee('https://wa.me/233241112222', false)
            ->assertDontSeeHtml("sendCollectionSms({$order->id}");
    }

    public function test_optical_orders_panel_filters_and_takes_the_order_to_collection(): void
    {
        $tenant = $this->tenant(0);
        $order = $this->readyOpticalOrder($tenant['user']);
        $other = $this->readyOpticalOrder($tenant['user'], 'Kofi Lens', '0241113333');
        app(\App\Services\OpticalOrderWorkflowService::class)->transition($order->id, 'Ready for Collection');
        $order->update(['notes' => json_encode(['lens_details' => ['type' => 'Single Vision', 'index' => '1.56', 'coatings' => ['BlueCut']], 'fitting' => ['pd_right' => '31', 'pd_left' => '30', 'fitting_height' => ''], 'lab' => ['instructions' => 'Handle with care']])]);

        $page = Livewire::test(\App\Livewire\Optical\OpticalOrdersComponent::class)
            ->assertSee($order->order_id)->assertSee($other->order_id)->assertSee('Take payment')
            ->call('setFilter', 'ready')->assertSee($order->order_id)->assertDontSee($other->order_id)
            ->call('setFilter', 'lab')->assertSee($other->order_id)->assertDontSee($order->order_id)
            ->call('clearFilters')->set('searchTerm', 'Kofi')->assertSee($other->order_id)->assertDontSee($order->order_id)
            ->call('clearFilters')->call('openOrder', $order->id)->assertSet('viewOrderId', $order->id)
            ->assertSee('Take the remaining GH₵ 200.00')->assertSee('Single Vision · index 1.56')->assertSee('PD right 31 · PD left 30')->assertSee('Handle with care')->assertDontSee('lens_details')->assertSee('Lab docket')->assertSee('https://wa.me/233241112222', false)
            ->call('openPaymentModal', $order->id)->assertSet('paymentAmount', '200.00')->call('recordPayment')->assertHasNoErrors()
            ->assertDontSee('Take the remaining')->assertSee('Mark collected')
            ->call('updateStatus', $order->id, 'Collected')->assertHasNoErrors();

        $this->assertSame('Collected', $order->fresh()->status);
        $page->call('closeOrder')->assertSet('viewOrderId', null);
    }

    public function test_clinic_spectacle_order_is_paid_on_the_clinic_bill_not_the_optical_page(): void
    {
        $tenant = $this->tenant(0);
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $tenant['user']->id, 'name' => 'Clinic Patient', 'contact' => '0241115555', 'gender' => 'Other']);
        $clearance = \App\Models\CashierPatientClearance::create(['user_id' => $tenant['user']->id, 'patient_id' => $patient->id, 'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = \App\Models\Consultations::create(['user_id' => $tenant['user']->id, 'patient_id' => $patient->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Blurred vision']);
        $refraction = \App\Models\Refractions::create(['user_id' => $tenant['user']->id, 'consultation_id' => $consultation->id, 'refractionOD' => '-2.00', 'refractionOS' => '-1.50', 'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6']);
        $order = \App\Models\LensOrder::create(['user_id' => $tenant['user']->id, 'refraction_id' => $refraction->id, 'order_id' => 'ORD-CLINIC01',
            'frame_model_number' => "Patient's own frame", 'own_frame' => true, 'frame_price' => 0, 'lens_price' => 1200, 'status' => 'Ready', 'pickUpDate' => now()->addDay()->toDateString()]);

        Livewire::test(\App\Livewire\Optical\OpticalOrdersComponent::class)
            ->assertSee('ORD-CLINIC01')->assertSee('Not on clinic bill')->assertDontSee('Take payment')
            ->call('openPaymentModal', $order->id)->assertHasErrors('order')->assertSet('showPaymentModal', false)
            ->call('openOrder', $order->id)->assertSee('paid on the patient')->call('closeOrder')
            ->call('setFilter', 'due')->assertDontSee('ORD-CLINIC01')
            ->call('setFilter', '')->call('updateStatus', $order->id, 'Collected')->assertHasNoErrors();
        $this->assertSame('Collected', $order->fresh()->status);
    }

    public function test_lab_workbench_stages_bench_steps_and_panel(): void
    {
        $tenant = $this->tenant(0);
        $onBench = $this->readyOpticalOrder($tenant['user']);
        $queued = app(\App\Services\OpticalOrderService::class)->create([
            'patient_id' => Patient::createWithGeneratedPxNumber(['user_id' => $tenant['user']->id, 'name' => 'Queue Person', 'contact' => '0241116666', 'gender' => 'Other'])->id,
            'measurements' => ['od' => ['sph' => '+1.00'], 'os' => ['sph' => '+0.75']],
            'frame_model_number' => 'Frame Q', 'frame_price' => 100, 'lens_price' => 50, 'glazing_fee' => 0, 'discount_amount' => 0,
            'paid_amount' => 150, 'payment_method' => 'cash', 'pickup_date' => now()->subDay()->toDateString(),
        ]);

        $page = Livewire::test(\App\Livewire\Optical\OpticalLabWorkbenchComponent::class)
            ->assertSee($onBench->order_id)->assertSee($queued->order_id)->assertSee('Late by 1d')->assertSee('past the pickup date')
            ->call('setStage', 'queue')->assertSee($queued->order_id)->assertDontSee($onBench->order_id)->assertSee('Start edging')
            ->call('updateStatus', $queued->id, 'In Production')->assertHasNoErrors()
            ->call('setStage', 'bench')->assertSee($queued->order_id)->assertSee('QC pass · ready')
            ->call('openOrder', $queued->id)->assertSee('Prescription')->assertSee('Print this panel');
        $this->assertSame('In Production', $queued->fresh()->status);
        $page->call('closeOrder')->call('setStage', 'ready')->assertDontSee($queued->order_id);
    }

    public function test_awaiting_collection_hands_over_from_the_row_and_panel(): void
    {
        $tenant = $this->tenant(0);
        $order = $this->readyOpticalOrder($tenant['user']);
        app(\App\Services\OpticalOrderWorkflowService::class)->transition($order->id, 'Ready for Collection');

        $page = Livewire::test(\App\Livewire\Optical\OpticalCollectionsComponent::class)
            ->assertSee('Take payment')->assertSee('GH₵ 200.00')
            ->call('openOrder', $order->id)->assertSee('Take the remaining GH₵ 200.00')
            ->call('openPaymentModal', $order->id)->call('recordPayment')->assertHasNoErrors()
            ->call('closeOrder')->assertSee('Mark collected')
            ->call('updateStatus', $order->id, 'Collected')->assertHasNoErrors()
            ->assertSee('No glasses are waiting for collection.');
        $this->assertSame('Collected', $order->fresh()->status);
    }

    public function test_optical_orders_filter_by_dates_source_and_partner_clinic(): void
    {
        $tenant = $this->tenant(0);
        $clinicOrder = $this->readyOpticalOrder($tenant['user']);
        $partnerOrder = $this->readyOpticalOrder($tenant['user'], 'Kofi Lens', '0241113333');
        $otherPartnerOrder = $this->readyOpticalOrder($tenant['user'], 'Efua Frame', '0241114444');
        $north = \App\Models\OpticalPartnerClinic::create(['name' => 'North Eye', 'billing_terms' => 'on_account', 'is_active' => true]);
        $south = \App\Models\OpticalPartnerClinic::create(['name' => 'South Eye', 'billing_terms' => 'on_account', 'is_active' => true]);
        $partnerOrder->forceFill(['order_source' => 'partner', 'partner_clinic_id' => $north->id])->save();
        $otherPartnerOrder->forceFill(['order_source' => 'partner', 'partner_clinic_id' => $south->id])->save();
        $clinicOrder->forceFill(['created_at' => now()->subMonths(2)])->save();

        Livewire::test(\App\Livewire\Optical\OpticalOrdersComponent::class)
            ->set('sourceFilter', 'partner')->assertSee('North Eye')->assertSee($partnerOrder->order_id)->assertSee($otherPartnerOrder->order_id)->assertDontSee($clinicOrder->order_id)
            ->set('partnerFilter', (string) $north->id)->assertSee($partnerOrder->order_id)->assertDontSee($otherPartnerOrder->order_id)
            ->set('sourceFilter', 'in_clinic')->assertSet('partnerFilter', '')->assertSee($clinicOrder->order_id)->assertDontSee($partnerOrder->order_id)
            ->call('clearFilters')->call('datePreset', 'month')->assertSee($partnerOrder->order_id)->assertDontSee($clinicOrder->order_id)
            ->set('dateFrom', now()->subMonths(3)->toDateString())->set('dateTo', now()->subMonth()->toDateString())->assertSee($clinicOrder->order_id)->assertDontSee($partnerOrder->order_id)
            ->set('dateField', 'pickup')->assertDontSee($clinicOrder->order_id)
            ->call('clearFilters')->assertSee($clinicOrder->order_id)->assertSee($partnerOrder->order_id);
    }

    public function test_optical_collection_reminders_follow_the_clinic_day_list(): void
    {
        $tenant = $this->tenant(10);
        \App\Models\OpticalSetting::create(['ready_sms_auto' => false, 'collection_reminder_schedule' => [7, 14]]);
        $order = $this->readyOpticalOrder($tenant['user']);
        $workflow = app(\App\Services\OpticalOrderWorkflowService::class);
        $workflow->transition($order->id, 'Ready for Collection');
        $this->assertNull($workflow->lastReadySms, 'Automatic ready SMS is switched off.');
        $this->assertSame(0, SmsLog::where('template_key', 'spectacles_ready')->count());

        $this->artisan('sms:optical-collection-reminders')->assertSuccessful();
        $this->assertSame(0, $order->fresh()->collection_reminders_sent, 'Nothing before day 7.');

        $order->forceFill(['ready_at' => now()->subDays(8)])->save();
        $this->artisan('sms:optical-collection-reminders')->assertSuccessful();
        $this->artisan('sms:optical-collection-reminders')->assertSuccessful();
        $this->assertSame(1, $order->fresh()->collection_reminders_sent, 'One reminder for day 7, not repeated.');

        $order->forceFill(['ready_at' => now()->subDays(20)])->save();
        $this->artisan('sms:optical-collection-reminders')->assertSuccessful();
        $this->artisan('sms:optical-collection-reminders')->assertSuccessful();
        $this->assertSame(2, $order->fresh()->collection_reminders_sent, 'Day 14 reminder, then the list is used up.');
        $this->assertSame(2, SmsLog::where('template_key', 'spectacles_reminder')->count());

        // A job that was missed for a while catches up with a single message.
        $late = $this->readyOpticalOrder($tenant['user'], 'Kofi Late', '0243334444');
        $workflow->transition($late->id, 'Ready for Collection');
        $late->forceFill(['ready_at' => now()->subDays(30)])->save();
        $this->artisan('sms:optical-collection-reminders')->assertSuccessful();
        $this->assertSame(2, $late->fresh()->collection_reminders_sent);
        $this->assertSame(1, SmsLog::where('template_key', 'spectacles_reminder')->where('recipient', '233243334444')->count());

        Livewire::test(\App\Livewire\Optical\OpticalSettingsComponent::class)
            ->assertSet('reminder_schedule', '7, 14')
            ->set('reminder_schedule', '30, 3, 10')->call('saveSettings')->assertHasNoErrors()
            ->assertSet('reminder_schedule', '3, 10, 30')
            ->set('reminder_schedule', '1, 2, 3, 4, 5, 6')->call('saveSettings')->assertHasErrors(['reminder_schedule'])
            ->set('reminder_schedule', '3, 3')->call('saveSettings')->assertHasErrors(['reminder_schedule'])
            ->set('reminder_schedule', '')->call('saveSettings')->assertHasNoErrors();
        $this->assertSame([], \App\Models\OpticalSetting::first()->reminderSchedule());
    }

    public function test_partner_jobs_notify_the_partner_with_one_combined_reminder(): void
    {
        $tenant = $this->tenant(20);
        \App\Models\OpticalSetting::create(['ready_sms_auto' => true, 'collection_reminder_schedule' => [7]]);
        $partner = \App\Models\OpticalPartnerClinic::create(['name' => 'North Eye Clinic', 'phone' => '0302000000',
            'notification_phone' => '0209990000', 'billing_terms' => 'on_account', 'is_active' => true]);
        $service = app(\App\Services\OpticalOrderService::class);
        $workflow = app(\App\Services\OpticalOrderWorkflowService::class);
        $job = fn (string $wearer, string $ref) => $service->create([
            'order_source' => 'partner', 'partner_id' => $partner->id, 'bill_to' => 'partner',
            'customer_name' => $wearer, 'customer_phone' => '0241113333',
            'measurements' => ['od' => ['sph' => '+1.00'], 'os' => ['sph' => '+0.75']],
            'frame_model_number' => 'Frame', 'frame_price' => 0, 'lens_price' => 50, 'glazing_fee' => 0,
            'discount_amount' => 0, 'paid_amount' => 0, 'payment_method' => 'cash',
            'pickup_date' => now()->addWeek()->toDateString(), 'docket' => ['reference' => $ref],
        ]);
        $first = $job('Esi Wearer', 'NORTH-1');
        $second = $job('Yaw Wearer', 'NORTH-2');
        foreach ([$first, $second] as $order) {
            $workflow->transition($order->id, 'In Production');
            $workflow->transition($order->id, 'Ready for Collection');
        }

        $ready = SmsLog::where('template_key', 'spectacles_ready')->get();
        $this->assertCount(2, $ready, 'One ready message per job.');
        $this->assertSame(['233209990000'], $ready->pluck('recipient')->unique()->values()->all(), 'Sent to the partner notification phone.');
        $this->assertStringContainsString('NORTH-1', $ready->first()->message);
        $this->assertStringContainsString('Esi Wearer', $ready->first()->message);
        $this->assertSame(0, SmsLog::where('recipient', '233241113333')->count(), 'The wearer is never messaged.');
        $this->assertNull($first->fresh()->renewalRecipient(), 'No renewal reminders to a partner patient.');

        foreach ([$first, $second] as $order) $order->fresh()->forceFill(['ready_at' => now()->subDays(8)])->save();
        $this->artisan('sms:optical-collection-reminders')->assertSuccessful();
        $digest = SmsLog::where('template_key', 'partner_jobs_awaiting')->get();
        $this->assertCount(1, $digest, 'One reminder for all of the partner jobs.');
        $this->assertStringContainsString($first->order_id, $digest->first()->message);
        $this->assertStringContainsString($second->order_id, $digest->first()->message);
        $this->assertSame([1, 1], [$first->fresh()->collection_reminders_sent, $second->fresh()->collection_reminders_sent]);

        Livewire::test(\App\Livewire\Optical\OpticalCollectionsComponent::class)
            ->assertSee('North Eye Clinic')->assertSee('2 jobs')
            ->assertSee('https://wa.me/233209990000', false)
            ->assertSeeHtml("sendPartnerSms({$partner->id})");

        $partner->update(['notify_via' => 'whatsapp']);
        Livewire::test(\App\Livewire\Optical\OpticalCollectionsComponent::class)
            ->assertSee('https://wa.me/233209990000', false)
            ->assertDontSeeHtml("sendPartnerSms({$partner->id})")
            ->assertDontSeeHtml("sendSms({$first->id}");
        $partner->update(['notify_via' => 'none']);
        $this->assertFalse(app(\App\Services\OpticalCollectionNotifier::class)->sendSms($first->fresh(), 'spectacles_reminder')['success']);
        Livewire::test(\App\Livewire\Optical\OpticalCollectionsComponent::class)
            ->assertSee('Asked not to be notified')->assertDontSee('https://wa.me/233209990000', false);
    }
}

class FakeSmsDriver implements SmsDriver
{
    public array $sent = [];
    public ?array $result = null;

    public function send(SmsCredentials $credentials, string $to, string $message): array
    {
        if ($this->result) {
            return $this->result;
        }

        $this->sent[] = compact('credentials', 'to', 'message');

        return ['success' => true, 'message_id' => 'msg-'.count($this->sent)];
    }

    public function balance(SmsCredentials $credentials): array
    {
        return ['success' => true, 'response' => ['balance' => 42]];
    }
}
