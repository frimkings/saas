<?php

namespace Tests\Feature;

use App\Livewire\Admin\ClinicSubscriptionPortalComponent;
use App\Livewire\Platform\SupportSettingsComponent;
use App\Models\{Clinic, ClinicSubscription, PlatformSetting, SubscriptionPlan, User};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** "Dial *713*62# and follow the prompt to make payment", set by the platform admin. */
class PaymentPromptTest extends TestCase
{
    use RefreshDatabase;

    private function platformAdmin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    private function clinicOwner(): User
    {
        config(['tenancy.enabled' => true]);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);
        $clinic = Clinic::create(['name' => 'Pay Clinic', 'slug' => 'pay']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        $plan = SubscriptionPlan::create(['name' => 'Basic', 'code' => 'basic-pay', 'included_branches' => 1, 'included_users' => 5, 'base_price' => 99, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth()]);
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);

        return $user;
    }

    public function test_clinics_see_the_default_code_on_subscription_and_billing(): void
    {
        $this->assertSame('*713*62#', PlatformSetting::paymentCode());

        Livewire::actingAs($this->clinicOwner())->test(ClinicSubscriptionPortalComponent::class)
            ->assertSee('*713*62#')->assertSee('and follow the prompt to make payment.');
    }

    public function test_platform_admin_changes_the_code_and_clinics_see_the_new_one(): void
    {
        Livewire::actingAs($this->platformAdmin())->test(SupportSettingsComponent::class)
            ->assertSet('paymentCode', '*713*62#')
            ->set('name', 'Help Desk')->set('paymentCode', '*170*55#')->assertSee('Dial *170*55#')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('*170*55#', PlatformSetting::paymentCode());
        $this->assertSame('Dial *170*55# and follow the prompt to make payment.', PlatformSetting::paymentPrompt());

        Livewire::actingAs($this->clinicOwner())->test(ClinicSubscriptionPortalComponent::class)
            ->assertSee('*170*55#')->assertDontSee('*713*62#');
    }

    public function test_clearing_the_code_hides_the_prompt_and_bad_codes_are_refused(): void
    {
        $page = Livewire::actingAs($this->platformAdmin())->test(SupportSettingsComponent::class)->set('name', 'Help Desk');
        $page->set('paymentCode', 'dial 713')->call('save')->assertHasErrors(['paymentCode' => 'regex']);
        $page->set('paymentCode', '')->call('save')->assertHasNoErrors();

        $this->assertNull(PlatformSetting::paymentCode());
        Livewire::actingAs($this->clinicOwner())->test(ClinicSubscriptionPortalComponent::class)
            ->assertDontSee('follow the prompt to make payment');
    }
}
