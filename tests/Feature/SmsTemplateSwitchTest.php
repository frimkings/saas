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
        \App\Models\Patient::createWithGeneratedPxNumber(['user_id' => $user->id, 'name' => 'Ama', 'contact' => '0241112222', 'gender' => 'Female']);

        Livewire::test(BroadcastComponent::class)
            ->set('message', 'Happy holidays [NAME] from [CLINIC]!')->call('prepareBroadcast')
            ->assertSet('broadcastConfirmStep', true)->assertSet('broadcastRecipientCount', 1);
        $this->assertSame('Happy holidays [NAME] from [CLINIC]!', SmsTemplate::where('key', 'custom_broadcast')->value('message'));
    }
}
