<?php

namespace Tests\Feature;

use App\Livewire\Admin\SmsTemplatesComponent;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\SmsTemplate;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use App\Support\Tenancy\TenantContext;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Clinics tick which messages their patients get by SMS. */
class SmsTemplateSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_message_switched_off_is_not_sent_but_keeps_its_text(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $this->actingAs($admin);

        $screen = Livewire::test(SmsTemplatesComponent::class)->assertSee('Sending');
        $this->assertStringContainsString('Ama', SmsTemplate::render('payment_receipt', ['[NAME]' => 'Ama']));

        $screen->call('toggleEnabled', 'payment_receipt')->assertSet('templates.payment_receipt.is_enabled', false);
        $this->assertSame('', SmsTemplate::render('payment_receipt', ['[NAME]' => 'Ama']));
        // Text staff send themselves (a WhatsApp link) still has the message.
        $this->assertStringContainsString('Ama', SmsTemplate::render('payment_receipt', ['[NAME]' => 'Ama'], evenIfOff: true));
        $this->assertNotSame('', SmsTemplate::where('key', 'payment_receipt')->value('message'));

        $screen->call('toggleEnabled', 'payment_receipt');
        $this->assertStringContainsString('Ama', SmsTemplate::render('payment_receipt', ['[NAME]' => 'Ama']));
    }

    public function test_clinics_without_sms_campaigns_can_still_switch_everyday_messages_but_not_run_campaigns(): void
    {
        config()->set('tenancy.enabled', true);
        $clinic = Clinic::create(['name' => 'Basic Clinic', 'slug' => 'basic', 'status' => 'active', 'deployment_mode' => 'hosted']);
        $plan = SubscriptionPlan::create(['name' => 'Basic', 'code' => 'basic', 'features' => ['clinical'], 'base_price' => 0, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => 'monthly',
            'feature_snapshot' => ['clinical'], 'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $clinic->users()->attach($admin->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($admin->id, ['status' => 'active', 'is_default' => true]);
        $this->actingAs($admin);
        app(TenantContext::class)->set($admin, $clinic, $branch, [$branch->id]);

        $screen = Livewire::test(SmsTemplatesComponent::class)
            ->assertSee('Payment Receipt')->assertSee('come with')
            ->assertDontSee('Patient Recall — Settings')->assertDontSee('Send Broadcast');
        $this->assertArrayNotHasKey('birthday_wishes', $screen->get('templates'));

        $screen->call('toggleEnabled', 'payment_receipt')->assertSet('templates.payment_receipt.is_enabled', false);
        $screen->call('saveRecallSettings')->assertForbidden();
    }
}
