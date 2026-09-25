<?php

namespace Tests\Feature;

use App\Livewire\Platform\PlatformDashboardComponent;
use App\Models\{Clinic, ClinicSubscription, SubscriptionPlan, User};
use App\Support\PlanProduct;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Plans cover the clinic, the optical shop, or both. */
class SubscriptionProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_rules_for_feature_lists(): void
    {
        $optical = PlanProduct::features('optical', ['sms_campaigns', 'appointments']);
        $this->assertSame(['optical', 'sms_campaigns', 'appointments'], $optical);
        $this->assertSame('optical', PlanProduct::productOf($optical));
        $this->assertFalse(PlanProduct::allows($optical, 'clinical'));
        $this->assertTrue(PlanProduct::allows($optical, 'sms_campaigns'));
        $this->assertFalse(PlanProduct::allows($optical, 'audit_trail'));

        // '*' adds every extra, never the other product.
        $all = PlanProduct::features('optical', [], true);
        $this->assertTrue(PlanProduct::allows($all, 'audit_trail'));
        $this->assertFalse(PlanProduct::allows($all, 'clinical'));

        // Legacy lists (no product key) keep meaning "everything".
        foreach ([[], ['*'], null] as $legacy) {
            $this->assertSame('both', PlanProduct::productOf($legacy));
            $this->assertTrue(PlanProduct::allows($legacy, 'clinical'));
            $this->assertTrue(PlanProduct::allows($legacy, 'optical'));
        }
        $this->assertSame('clinic', PlanProduct::productOf(['clinical', 'appointments']));
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    private function plan(string $code, string $product, array $extras = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(['name' => ucfirst($code), 'code' => $code, 'family_code' => $code, 'version' => 1, 'product' => $product,
            'features' => PlanProduct::features($product, $extras), 'currency' => 'GHS', 'base_price' => 100, 'annual_price' => 1000,
            'included_branches' => 1, 'trial_days' => 14, 'billing_interval' => 'monthly', 'is_active' => true]);
    }

    public function test_optical_plan_is_created_with_checkbox_extras(): void
    {
        Livewire::withQueryParams(['tab' => 'plans'])->actingAs($this->platformAdmin())->test(PlatformDashboardComponent::class)
            ->call('openNewPlan')->assertSet('planProduct', 'both')->assertSee('Optical shop')
            ->set('planName', 'Optical Basic')->set('planCode', 'optical-basic')->set('basePrice', '80')->set('annualPrice', '800')
            ->set('planProduct', 'optical')->assertDontSee('Referral letters')
            // A clinic-only extra left ticked from before is dropped for an optical plan.
            ->set('planExtras', ['sms_campaigns', 'referrals'])->call('savePlan')->assertHasNoErrors();

        $plan = SubscriptionPlan::where('code', 'optical-basic')->firstOrFail();
        $this->assertSame('optical', $plan->product);
        $this->assertSame(['optical', 'sms_campaigns'], $plan->features);
    }

    public function test_directory_and_onboarding_follow_the_product(): void
    {
        $opticalPlan = $this->plan('optical-basic', 'optical');
        $clinicPlan = $this->plan('clinic-basic', 'clinic');
        foreach ([['Shop One', 'shop-one', $opticalPlan], ['Clinic One', 'clinic-one', $clinicPlan]] as [$name, $slug, $plan]) {
            $clinic = Clinic::create(['name' => $name, 'slug' => $slug, 'status' => 'active', 'deployment_mode' => 'hosted']);
            ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
                'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth()]);
        }

        Livewire::withQueryParams(['tab' => 'clinics'])->actingAs($this->platformAdmin())->test(PlatformDashboardComponent::class)
            ->assertSee('Shop One')->assertSee('Clinic One')
            ->set('productFilter', 'optical')->assertSee('Shop One')->assertDontSee('Clinic One')
            ->set('productFilter', 'clinic')->assertSee('Clinic One')->assertDontSee('Shop One')
            // Onboarding only accepts a plan for the chosen product.
            ->call('openOnboarding')->set('newClinicProduct', 'optical')->set('newPlanId', $clinicPlan->id)
            ->set('newClinicName', 'New Shop')->set('newClinicSlug', 'new-shop')->set('newBranchCode', 'MAIN')->set('newBranchName', 'Main')
            ->set('newAdminName', 'Shop Owner')->set('newAdminEmail', 'owner@newshop.test')
            ->call('onboardClinic')->assertHasErrors('newPlanId')
            ->set('newPlanId', $opticalPlan->id)->call('onboardClinic')->assertHasNoErrors();

        $shop = Clinic::where('slug', 'new-shop')->firstOrFail();
        $this->assertSame(['optical'], $shop->subscriptions()->latest('id')->first()->feature_snapshot);
    }

    private function tenantUser(string $product): User
    {
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $user->assignRole($role);
        $clinic = Clinic::create(['name' => 'Tenant', 'slug' => 'tenant-'.$product, 'status' => 'active', 'deployment_mode' => 'hosted']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'role_id' => $role->id]);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $this->plan('plan-'.$product, $product)->id, 'status' => 'active',
            'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth()]);
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);

        return $user;
    }

    public function test_optical_shop_is_sent_from_clinic_pages_to_its_dashboard(): void
    {
        $user = $this->tenantUser('optical');

        $this->actingAs($user)->get(route('admin.diagnoses'))->assertRedirect(route('optical.dashboard'))
            ->assertSessionHas('product_notice', 'Clinic pages are not part of your optical shop subscription.');
        $this->get(route('optical.dashboard'))->assertOk()->assertSee('Clinic pages are not part of your optical shop subscription.');
    }

    public function test_clinic_only_subscriber_is_sent_from_optical_pages(): void
    {
        $user = $this->tenantUser('clinic');

        $this->actingAs($user)->get(route('optical.orders'))->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('product_notice', 'The optical shop module is not part of your subscription.');
    }
}
