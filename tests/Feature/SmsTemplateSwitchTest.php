<?php

namespace Tests\Feature;

use App\Livewire\Admin\BroadcastComponent;
use App\Livewire\Admin\SmsTemplatesComponent;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\CommunicationsNavigation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Communications → Messages: clinics choose which SMS go out (all start off) and word them. */
class SmsTemplateSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function clinicAdmin(array $features, string $role = 'Super Admin'): User
    {
        config()->set('tenancy.enabled', true);
        $clinic = Clinic::create(['name' => 'Bright Eyes', 'slug' => 'bright-eyes-'.uniqid(), 'status' => 'active', 'deployment_mode' => 'hosted']);
        $plan = SubscriptionPlan::create(['name' => 'Plan', 'code' => 'plan-'.uniqid(), 'features' => $features, 'base_price' => 0, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => 'monthly',
            'feature_snapshot' => $features, 'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $user = User::factory()->create();
        $roleModel = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($roleModel);
        $clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        // Requests load roles per branch (BranchRoleManager).
        \Illuminate\Support\Facades\DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $roleModel->id]);
        $this->actingAs($user);
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);

        return $user;
    }

    public function test_messages_start_off_and_a_switched_on_message_is_sent_with_its_text_kept_when_off(): void
    {
        $this->clinicAdmin(['clinical', '*']);
        $screen = Livewire::test(SmsTemplatesComponent::class)->assertSee('0 of')->assertSee('Turn on recommended');
        $this->assertSame('', SmsTemplate::render('payment_receipt', ['[NAME]' => 'Ama']));

        $screen->call('toggleEnabled', 'payment_receipt')->assertSet('templates.payment_receipt.is_enabled', true);
        $this->assertStringContainsString('Ama', SmsTemplate::render('payment_receipt', ['[NAME]' => 'Ama']));

        $screen->call('toggleEnabled', 'payment_receipt')->assertSet('templates.payment_receipt.is_enabled', false);
        $this->assertSame('', SmsTemplate::render('payment_receipt', ['[NAME]' => 'Ama']));
        // Text staff send themselves (a WhatsApp link) still has the message.
        $this->assertStringContainsString('Ama', SmsTemplate::render('payment_receipt', ['[NAME]' => 'Ama'], evenIfOff: true));
    }

    public function test_turn_on_recommended_and_one_switch_for_scheduled_recall(): void
    {
        $this->clinicAdmin(['clinical', '*']);
        $screen = Livewire::test(SmsTemplatesComponent::class)->call('turnOnRecommended');

        foreach (['appointment_booking', 'appointment_auto_reminder', 'appointment_rescheduled', 'appointment_cancelled', 'spectacles_ready', 'payment_receipt'] as $key) {
            $this->assertTrue((bool) SmsTemplate::where('key', $key)->value('is_enabled'), "$key switched on");
        }
        $this->assertFalse((bool) SmsTemplate::where('key', 'birthday_wishes')->value('is_enabled'));

        // The recall message's switch also drives its scheduled job: one switch, not two.
        $screen->call('toggleEnabled', 'patient_recall');
        $this->assertTrue((bool) Setting::getSettings()->recall_sms_enabled);
        $screen->call('toggleEnabled', 'spectacle_renewal');
        $this->assertTrue((bool) Setting::getSettings()->spectacle_renewal_enabled);
    }

    public function test_messages_staff_send_have_wording_but_no_switch(): void
    {
        $this->clinicAdmin(['clinical', '*']);

        Livewire::test(SmsTemplatesComponent::class)->assertSee('Sent by staff')
            ->call('toggleEnabled', 'appointment_reminder')->assertStatus(422);
    }

    public function test_search_and_filter_narrow_the_list(): void
    {
        $this->clinicAdmin(['clinical', '*']);

        $listed = fn ($screen) => collect($screen->viewData('groups'))->flatMap(fn ($messages) => array_keys($messages))->all();

        $screen = Livewire::test(SmsTemplatesComponent::class)->set('search', 'birthday');
        $this->assertSame(['birthday_wishes'], $listed($screen));

        $screen->set('search', '')->call('toggleEnabled', 'payment_receipt')->set('show', 'on');
        $this->assertSame(['payment_receipt'], $listed($screen));
    }

    public function test_clinics_without_sms_campaigns_can_switch_everyday_messages_but_not_campaigns(): void
    {
        $this->clinicAdmin(['clinical']);

        $screen = Livewire::test(SmsTemplatesComponent::class)
            ->assertSee('Payment Receipt')->assertSee('come with')->assertDontSee('Birthday Wishes');
        $this->assertArrayNotHasKey('birthday_wishes', $screen->get('templates'));

        $screen->call('toggleEnabled', 'payment_receipt')->assertSet('templates.payment_receipt.is_enabled', true);
        $screen->call('saveRecallSettings')->assertForbidden();
        $this->assertSame(['admin.messages', 'admin.sms-settings', 'admin.whatsapp-settings', 'admin.sms-logs'],
            array_column(CommunicationsNavigation::links(), 0));
    }

    public function test_every_plan_can_buy_credits_and_read_sms_logs_but_campaign_pages_stay_pro(): void
    {
        $this->clinicAdmin(['clinical']);

        $this->get(route('admin.sms-settings'))->assertOk()->assertSee('SMS Credits');
        $this->get(route('admin.sms-logs'))->assertOk();
        $this->get(route('admin.broadcast'))->assertForbidden();
        $this->get(route('admin.patient-recall'))->assertForbidden();
    }

    public function test_communications_pages_are_for_the_super_admin(): void
    {
        $this->clinicAdmin(['clinical', '*'], 'Manager');

        Livewire::test(SmsTemplatesComponent::class)->assertForbidden();
        $this->get(route('admin.messages'))->assertForbidden();
        $this->assertSame(['admin.patient-recall', 'admin.sms-logs'], array_column(CommunicationsNavigation::links(), 0));
    }

    public function test_old_settings_tabs_lead_to_the_new_pages(): void
    {
        $this->clinicAdmin(['clinical', '*']);

        $this->get(route('admin.settings', ['tab' => 'templates']))->assertRedirect(route('admin.messages'));
        $this->get(route('admin.settings', ['tab' => 'sms']))->assertRedirect(route('admin.sms-settings'));
        $this->get(route('admin.settings', ['tab' => 'whatsapp']))->assertRedirect(route('admin.whatsapp-settings'));
    }

    public function test_broadcast_page_saves_its_message_and_counts_recipients_before_sending(): void
    {
        $user = $this->clinicAdmin(['clinical', '*']);
        $this->withCredits();
        \App\Models\Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Ama', 'contact' => '0241112222', 'gender' => 'Female']);

        Livewire::test(BroadcastComponent::class)
            ->set('message', 'Happy holidays [NAME] from [CLINIC]!')->call('prepareBroadcast')
            ->assertSet('broadcastConfirmStep', true)->assertSet('broadcastRecipientCount', 1)->assertSet('broadcastCreditsNeeded', 1);
        $this->assertSame('Happy holidays [NAME] from [CLINIC]!', SmsTemplate::where('key', 'custom_broadcast')->value('message'));
    }

    /** The platform gateway (faked) and 100 credits in the clinic's wallet. */
    private function withCredits(int $credits = 100): void
    {
        config(['services.eazisms' => ['url' => 'https://platform.test/sms', 'key' => 'platform-key', 'default_sender' => 'EYEPLATFORM']]);
        $this->app->instance(\App\Services\Messaging\SmsDriver::class, new class implements \App\Services\Messaging\SmsDriver {
            public function send(\App\Services\Messaging\SmsCredentials $credentials, string $to, string $message): array { return ['success' => true, 'message_id' => 'm1']; }
            public function balance(\App\Services\Messaging\SmsCredentials $credentials): array { return ['success' => true, 'response' => ['balance' => 1]]; }
        });
        Setting::getSettings()->update(['sms_enabled' => true]);
        if ($credits) app(\App\Services\Messaging\SmsCreditService::class)->add(app(TenantContext::class)->clinicId(), $credits, 'grant', ['note' => 'Test credits']);
    }

    public function test_a_broadcast_fills_the_occasion_leaves_out_opt_outs_and_refuses_unknown_placeholders(): void
    {
        $user = $this->clinicAdmin(['clinical', '*']);
        $this->withCredits();
        $patient = fn (string $name, array $extra = []) => \App\Models\Patient::createWithGeneratedPxNumber(
            ['user_id' => $user->id, 'name' => $name, 'contact' => '02411' . random_int(10000, 99999), 'gender' => 'Female'] + $extra);
        $patient('Ama');
        $patient('Kofi', ['marketing_opt_out' => true]);

        $page = Livewire::test(BroadcastComponent::class)
            ->set('message', 'Happy [OCCASION], [NAME]! Bring [FOO].')->call('prepareBroadcast')->assertHasErrors('message')
            ->set('message', 'Happy [OCCASION], [NAME], from [BRANCH]!')->call('prepareBroadcast')->assertHasErrors('occasion')
            ->set('occasion', 'Christmas')->call('prepareBroadcast')->assertHasNoErrors()
            ->assertSet('broadcastRecipientCount', 1);
        $page->call('sendCustomBroadcast');

        $this->assertSame(['Happy Christmas, Ama, from Main!'], \App\Models\SmsLog::where('template_key', 'custom_broadcast')->pluck('message')->all());
    }

    public function test_a_message_cannot_be_saved_with_a_placeholder_it_cannot_fill(): void
    {
        $this->clinicAdmin(['clinical', '*']);
        Livewire::test(SmsTemplatesComponent::class)
            ->set('templates.appointment_booking.message', 'Hi [NAME], your glasses [ORDER_ID] are ready.')->call('save', 'appointment_booking')
            ->assertHasErrors('templates.appointment_booking.message')
            ->set('templates.appointment_booking.message', 'Hi [NAME], see you on [DATE] at [TIME] at [BRANCH].')->call('save', 'appointment_booking')
            ->assertHasNoErrors();
    }

    public function test_manual_recall_sends_while_the_automatic_recall_is_off(): void
    {
        $user = $this->clinicAdmin(['clinical', '*']);
        $this->withCredits();
        $ama = \App\Models\Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Ama', 'contact' => '0241112222', 'gender' => 'Female']);
        SmsTemplate::ensureDefaults();
        $this->assertFalse((bool) SmsTemplate::where('key', 'patient_recall')->value('is_enabled'));

        Livewire::test(\App\Livewire\Admin\PatientRecallComponent::class)->call('sendRecall', $ama->id)->assertDispatched('notify', type: 'success');
        $this->assertSame(1, \App\Models\SmsLog::where('template_key', 'patient_recall')->count());
    }

    public function test_refused_sms_are_logged_with_the_reason(): void
    {
        $this->clinicAdmin(['clinical', '*']);
        $this->withCredits(0);

        $result = app(\App\Services\SmsService::class)->send('0241112222', 'Hello', null, 'appointment_booking');
        $this->assertFalse($result['success']);
        $this->assertSame('failed', \App\Models\SmsLog::sole()->status);

        Setting::getSettings()->update(['sms_enabled' => false]);
        app(\App\Services\SmsService::class)->send('0241112222', 'Hello again', null, 'appointment_booking');
        $this->assertSame('skipped', \App\Models\SmsLog::latest('id')->first()->status);
    }

    public function test_clinic_plans_hide_partner_messages_and_set_pickup_days_and_birthday_months(): void
    {
        $this->clinicAdmin(['clinical', 'patient_messages', 'sms_campaigns', 'spectacle_orders']);
        $page = Livewire::test(SmsTemplatesComponent::class)
            ->assertDontSee('Partner Job Ready')->assertSee('Spectacles Pickup Reminder')
            ->set('pickupSchedule', '3, 10, 400')->call('savePickupSchedule')->assertHasErrors('pickupSchedule')
            ->set('pickupSchedule', '10, 3')->call('savePickupSchedule')->assertHasNoErrors();
        $this->assertSame([3, 10], \App\Models\OpticalSetting::currentReminderSchedule());

        $page->set('birthdayFilter', 'custom')->set('birthdayCustomMonths', null)->call('saveBirthdaySettings')->assertHasErrors('birthdayCustomMonths');
    }

    public function test_whatsapp_is_not_switched_on_without_credentials(): void
    {
        $this->clinicAdmin(['clinical', '*']);
        Livewire::test(\App\Livewire\Admin\WhatsAppSettingsComponent::class)->call('toggleWhatsApp')->assertSet('whatsappEnabled', false);
        $this->assertFalse((bool) Setting::getSettings()->whatsapp_enabled);
    }

    public function test_a_branch_sms_limit_has_a_ceiling(): void
    {
        $this->clinicAdmin(['clinical', '*']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\Messaging\BranchSmsLimits::class)->set(app(TenantContext::class)->branch(), 99999999999);
    }
}
