<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminDashboardComponent;
use App\Livewire\Optical\OpticalDashboardComponent;
use App\Models\{CashierPatientClearance, Consultations, Diagnosis, Patient};
use App\Support\ClinicStarterData;
use App\Support\Guides\ClinicAdded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** 20 Nov 2026: an optical shop that adds the clinic gets a short setup list, not a surprise redirect to Settings. */
class ClinicAddedTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\ClinicOnPlan;

    /** An optical shop (it has frames in stock) whose plan now has the clinic too. */
    private function opticalShopThatAddedTheClinic()
    {
        $owner = $this->ownerOn('clinic-plus');
        $subscription = $this->clinic->subscriptions()->latest()->firstOrFail();
        $subscription->update(['feature_snapshot' => array_values(array_unique([...(array) $subscription->feature_snapshot, 'optical']))]);
        $category = \App\Models\OpticalCategory::create(['code' => 'FRAMES', 'name' => 'Frames', 'is_active' => true]);
        \App\Models\OpticalProduct::create(['optical_category_id' => $category->id, 'name' => 'Shop Frame', 'type' => 'frame', 'sku' => 'SF-1', 'selling_price' => 250, 'cost_price' => 100]);

        return $owner;
    }

    public function test_the_shop_gets_the_clinic_setup_list_on_both_dashboards_and_can_add_the_starter_lists(): void
    {
        $owner = $this->opticalShopThatAddedTheClinic();
        $this->assertTrue(ClinicAdded::pending());
        $this->assertSame(['lists', 'details', 'doctor', 'patient'], array_column(ClinicAdded::for()['steps'], 'key'));

        // Clinic dashboard: this list instead of the general one.
        Livewire::test(AdminDashboardComponent::class)->assertSee('The clinic is now part of your account')
            ->assertDontSee('Getting started with the Clinic Suite')->assertSeeHtml('wire:click="addClinicStarterLists"');
        // Optical dashboard too.
        Livewire::test(OpticalDashboardComponent::class)->assertSee('The clinic is now part of your account');

        // The starter lists, skipping any the shop already has.
        Diagnosis::create(['name' => ClinicStarterData::DIAGNOSES[0]]);
        Livewire::test(AdminDashboardComponent::class)->call('addClinicStarterLists')->assertDispatched('notify');
        $this->assertSame(count(ClinicStarterData::DIAGNOSES), Diagnosis::count());
        $categories = DB::table('categories')->where('clinic_id', $this->clinic->id)->count();
        $this->assertSame(count(ClinicStarterData::CATEGORIES), $categories);
        Livewire::test(AdminDashboardComponent::class)->call('addClinicStarterLists');
        $this->assertSame(count(ClinicStarterData::DIAGNOSES), Diagnosis::count());
        $this->assertSame($categories, DB::table('categories')->where('clinic_id', $this->clinic->id)->count());
        $this->assertTrue(collect(ClinicAdded::for()['steps'])->firstWhere('key', 'lists')['done']);

        // A doctor ticks the doctor step.
        $this->staff('Doctor');
        $this->assertTrue(collect(ClinicAdded::for()['steps'])->firstWhere('key', 'doctor')['done']);

        // Hidden by the owner: gone for them.
        Livewire::test(OpticalDashboardComponent::class)->call('hideClinicAdded');
        $this->assertNull(ClinicAdded::for());
        Livewire::test(AdminDashboardComponent::class)->assertDontSee('The clinic is now part of your account');
    }

    public function test_it_ends_once_the_clinic_is_in_use_and_never_shows_for_a_clinic_or_a_shop_alone(): void
    {
        $owner = $this->opticalShopThatAddedTheClinic();
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $owner->id, 'name' => 'First Patient', 'contact' => '0240001111', 'gender' => 'Male']);
        $clearance = CashierPatientClearance::create(['user_id' => $owner->id, 'patient_id' => $patient->id, 'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        Consultations::create(['user_id' => $owner->id, 'patient_id' => $patient->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Blur']);
        $this->assertFalse(ClinicAdded::pending());
        $this->assertNull(ClinicAdded::for());
        Livewire::test(AdminDashboardComponent::class)->assertDontSee('The clinic is now part of your account');

        // A clinic with no optical history (a plain clinic plan) gets the usual list, not this one.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->ownerOn('clinic-standard');
        $this->assertFalse(ClinicAdded::pending());
        Livewire::test(AdminDashboardComponent::class)->assertDontSee('The clinic is now part of your account');
    }
}
