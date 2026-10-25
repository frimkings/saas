<?php

namespace Tests\Concerns;

use App\Models\{Branch, Category, Clinic, ClinicSubscription, Patient, PaymentTransaction, Product, SaleItem, Sales, SubscriptionPlan, User};
use App\Support\PlanProduct;
use App\Support\Pricing\ClinicalSuitePlans;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/** A hosted clinic on one of the four Clinical Suite plans, with staff by role. */
trait ClinicOnPlan
{
    protected Clinic $clinic;
    protected Branch $branch;

    /** The owner of a clinic on one of the four plans, signed in. */
    protected function ownerOn(string $family): User
    {
        config(['tenancy.enabled' => true]);
        foreach (['Super Admin', 'Manager', 'Cashier', 'Doctor', 'Secretary'] as $name) Role::findOrCreate($name, 'web');
        $definition = ClinicalSuitePlans::plans()[$family];
        $plan = SubscriptionPlan::create(['name' => $definition['name'], 'code' => $family, 'family_code' => $family, 'version' => 1, 'product' => PlanProduct::CLINIC,
            'features' => ClinicalSuitePlans::features($definition), 'included_branches' => 1, 'included_users' => $definition['users'],
            'storage_limit_mb' => $definition['storage_mb'], 'base_price' => $definition['price'], 'annual_price' => $definition['price'] * 10,
            'currency' => 'GHS', 'billing_interval' => 'monthly', 'is_active' => true]);
        $this->clinic = Clinic::create(['name' => 'Bright Eyes', 'slug' => 'bright-'.$family, 'status' => 'active', 'deployment_mode' => 'hosted']);
        $this->branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'feature_snapshot' => $plan->features,
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);

        return $this->signIn($this->staff('Super Admin'));
    }

    /** A member of the clinic with this role (not signed in). */
    protected function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => $role]);
        $this->branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $this->branch->id, 'user_id' => $user->id, 'role_id' => Role::findByName($role)->id, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    protected function signIn(User $user): User
    {
        // Switching people in one test: start a fresh session, as signing out and in would.
        if (auth()->check() && auth()->id() !== $user->id) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();
        }
        app(TenantContext::class)->set($user, $this->clinic, $this->branch, [$this->branch->id]);
        $this->actingAs($user);

        return $user;
    }

    protected function paidSale(?User $by = null): Sales
    {
        $by ??= auth()->user();
        $category = Category::firstOrCreate(['name' => 'Drops'], ['user_id' => $by->id]);
        $product = Product::factory()->create(['user_id' => $by->id, 'category_id' => $category->id, 'quantity' => 3]);
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $by->id, 'name' => 'Esi Mensah', 'contact' => '0241114444', 'gender' => 'Female']);
        $sale = Sales::create(['user_id' => $by->id, 'patient_id' => $patient->id, 'transaction_id' => 'TXN-TIER-'.uniqid(), 'business_line' => 'clinic',
            'total_amount' => 100, 'amount_paid' => 100, 'payment_status' => 'paid']);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'prescribed_quantity' => 2, 'dispensed_quantity' => 2, 'selling_price' => 50, 'subtotal' => 100]);
        PaymentTransaction::create(['sale_id' => $sale->id, 'amount' => 100, 'payment_method' => 'cash', 'collected_by' => $by->id]);

        return $sale->fresh('items');
    }
}
