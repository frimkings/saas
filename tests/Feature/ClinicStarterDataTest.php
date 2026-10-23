<?php

namespace Tests\Feature;

use App\Livewire\Platform\PlatformDashboardComponent;
use App\Models\Clinic;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\ClinicStarterData;
use App\Support\PlanProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ClinicStarterDataTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->developer = User::factory()->create();
        $this->developer->forceFill(['is_platform_admin' => true])->save();
    }

    private function plan(string $product): SubscriptionPlan
    {
        return SubscriptionPlan::create(['name' => ucfirst($product), 'code' => $product.'-plan', 'family_code' => $product.'-plan', 'version' => 1, 'product' => $product,
            'features' => PlanProduct::features($product, []), 'currency' => 'GHS', 'base_price' => 100, 'annual_price' => 1000,
            'included_branches' => 1, 'trial_days' => 14, 'billing_interval' => 'monthly', 'is_active' => true]);
    }

    private function onboard(string $product, string $name)
    {
        return Livewire::actingAs($this->developer)->test(PlatformDashboardComponent::class)
            ->call('openOnboarding')->set('newClinicProduct', $product)->set('newClinicName', $name)->set('newBranchTimezone', 'Africa/Accra')
            ->set('onboardingStep', 3)->set('newAdminName', 'Owner')->set('newAdminEmail', $product.'@example.test')->set('newPlanId', $this->plan($product)->id);
    }

    public function test_a_new_clinic_starts_with_diagnoses_and_typed_categories_by_default(): void
    {
        $this->onboard(PlanProduct::CLINIC, 'Starter Eye Clinic')->assertSee('Starter data')->call('onboardClinic')->assertHasNoErrors();
        $clinic = Clinic::where('slug', 'starter-eye-clinic')->firstOrFail();

        $this->assertSame(count(ClinicStarterData::DIAGNOSES), DB::table('diagnoses')->where('clinic_id', $clinic->id)->count());
        $this->assertDatabaseHas('diagnoses', ['clinic_id' => $clinic->id, 'name' => 'Interstitial Keratitis']);
        $this->assertDatabaseMissing('diagnoses', ['name' => 'Interstitual Keratitis']);
        $this->assertSame(['Drugs' => 'drug', 'Frames' => 'frame', 'Lenses' => 'lens', 'Others' => 'product', 'Services' => 'service'],
            DB::table('categories')->where('clinic_id', $clinic->id)->orderBy('name')->pluck('type', 'name')->all());
        $this->assertSame(['diagnoses' => count(ClinicStarterData::DIAGNOSES), 'categories' => 5],
            PlatformAuditLog::where('action', 'CLINIC_ONBOARDED')->where('clinic_id', $clinic->id)->firstOrFail()->new_values['starter_data']);
    }

    public function test_each_list_can_be_left_out(): void
    {
        $this->onboard(PlanProduct::BOTH, 'Bare Clinic')->set('seedDiagnoses', false)->call('onboardClinic')->assertHasNoErrors()
            ->assertSet('seedDiagnoses', true);
        $clinic = Clinic::where('slug', 'bare-clinic')->firstOrFail();

        $this->assertSame(0, DB::table('diagnoses')->where('clinic_id', $clinic->id)->count());
        $this->assertSame(5, DB::table('categories')->where('clinic_id', $clinic->id)->count());
    }

    public function test_optical_and_attendance_businesses_are_not_offered_the_lists(): void
    {
        foreach ([PlanProduct::OPTICAL => 'Lens Shop', PlanProduct::ATTENDANCE => 'Staff Only'] as $product => $name) {
            $this->onboard($product, $name)->assertDontSee('Starter data')->call('onboardClinic')->assertHasNoErrors();
            $clinic = Clinic::where('name', $name)->firstOrFail();
            $this->assertSame(0, DB::table('diagnoses')->where('clinic_id', $clinic->id)->count());
            $this->assertSame(0, DB::table('categories')->where('clinic_id', $clinic->id)->count());
        }
    }
}
