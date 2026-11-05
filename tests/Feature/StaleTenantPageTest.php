<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** One owner, two businesses, one session: a tab left on the first must not save into the second. */
class StaleTenantPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Clinic $shop;
    private Clinic $hospital;
    private $shopBranch;
    private $hospitalMain;
    private $hospitalEast;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->owner = User::factory()->create();
        $this->shop = Clinic::create(['name' => 'Vision Shop', 'slug' => 'vision-shop']);
        $this->hospital = Clinic::create(['name' => 'Mission Hospital', 'slug' => 'mission-hospital']);
        $this->shopBranch = $this->shop->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $this->hospitalMain = $this->hospital->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $this->hospitalEast = $this->hospital->branches()->create(['code' => 'EAST', 'name' => 'East']);
        $this->shop->users()->attach($this->owner, ['status' => 'active', 'is_default' => true]);
        $this->hospital->users()->attach($this->owner, ['status' => 'active']);
        $plan = \App\Models\SubscriptionPlan::create(['name' => 'All', 'code' => 'stale-all', 'features' => ['*'], 'base_price' => 0, 'billing_interval' => 'monthly']);
        foreach ([$this->shop, $this->hospital] as $clinic) {
            \App\Models\ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
                'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        }
        foreach ([$this->shopBranch, $this->hospitalMain, $this->hospitalEast] as $branch) {
            $branch->users()->attach($this->owner, ['status' => 'active', 'is_default' => $branch->is($this->hospitalMain)]);
        }

        // Stands in for any save: it records which business the request ran in.
        Route::middleware('web')->post('/_stale-check', fn () => response()->json(['clinic' => app(\App\Support\Tenancy\TenantContext::class)->clinicId()]));
    }

    /** The owner is now working in the hospital (switched in another tab). */
    private function inHospital(?int $branchId = null): self
    {
        return $this->actingAs($this->owner)->withSession([
            config('tenancy.session_keys.clinic') => $this->hospital->id,
            config('tenancy.session_keys.branch') => $branchId ?? $this->hospitalMain->id,
        ]);
    }

    public function test_a_livewire_call_from_a_page_opened_in_the_other_business_is_refused(): void
    {
        $this->inHospital()->postJson('/_stale-check', [], ['X-Livewire' => '1', 'X-Tenant-Context' => $this->shop->id.':'.$this->shopBranch->id])
            ->assertStatus(409)
            ->assertJsonPath('stale_tenant', true)
            ->assertJsonPath('message', 'This page was opened in Vision Shop, but you have since switched to Mission Hospital in another tab. Nothing was saved. Reload to carry on in Mission Hospital, or switch back first.');
    }

    public function test_a_page_from_another_branch_of_the_same_business_is_refused_too(): void
    {
        $this->inHospital($this->hospitalEast->id)->postJson('/_stale-check', [], ['X-Tenant-Context' => $this->hospital->id.':'.$this->hospitalMain->id])
            ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'opened in the Main branch, but you have since switched to the East branch'));
    }

    public function test_a_plain_form_goes_back_with_what_was_typed_and_saves_nothing(): void
    {
        $this->inHospital()->from('/optical/expenses')->post('/_stale-check', ['amount' => '150', '_tenant_context' => $this->shop->id.':'.$this->shopBranch->id])
            ->assertRedirect('/optical/expenses')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'opened in Vision Shop'))
            ->assertSessionHasInput('amount', '150');
    }

    public function test_a_matching_or_missing_stamp_and_the_switcher_still_work(): void
    {
        $this->inHospital()->postJson('/_stale-check', [], ['X-Tenant-Context' => $this->hospital->id.':'.$this->hospitalMain->id])
            ->assertOk()->assertJsonPath('clinic', $this->hospital->id);
        $this->inHospital()->postJson('/_stale-check')->assertOk();

        // Switching back from the old tab is how the owner fixes it.
        $this->inHospital()->post(route('tenant.clinic.switch'), ['clinic_id' => $this->shop->id, '_tenant_context' => $this->shop->id.':'.$this->shopBranch->id])
            ->assertSessionHas(config('tenancy.session_keys.clinic'), $this->shop->id);
    }

    public function test_a_business_the_user_does_not_belong_to_is_never_named(): void
    {
        $stranger = Clinic::create(['name' => 'Someone Else Optical', 'slug' => 'someone-else']);
        $this->inHospital()->postJson('/_stale-check', [], ['X-Tenant-Context' => $stranger->id.':1'])
            ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'opened in another business') && ! str_contains($m, 'Someone Else'));
    }
}
