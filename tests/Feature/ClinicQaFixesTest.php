<?php

namespace Tests\Feature;

use App\Livewire\Admin\ProductsComponent;
use App\Livewire\Doctor\PatientRecordsComponent;
use App\Models\{Category, Diagnosis, PaymentTransaction, Product};
use App\Support\Guides\ClinicSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** What the page-by-page check of the Clinical Suite found (2026-10-25), kept fixed. */
class ClinicQaFixesTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    public function test_the_pos_pop_ups_listen_for_their_events(): void
    {
        $this->ownerOn('clinic-pro');
        // Blade read "@show" as its own directive, leaving the listeners broken and the pop-ups shut.
        $this->get(route('cashier.seller-desk'))->assertOk()
            ->assertSee('x-on:show-pending-carts.window', false)
            ->assertSee('x-on:show-approved-discounts.window', false)
            ->assertDontSee(' -pending-carts.window', false);
    }

    public function test_a_service_is_added_with_just_a_name_and_price(): void
    {
        $this->ownerOn('clinic-pro');
        $services = Category::create(['name' => 'Services', 'type' => 'service', 'user_id' => auth()->id()]);

        Livewire::test(ProductsComponent::class)
            ->set('state.category_id', $services->id)
            ->assertDontSee('Batch Number')
            ->set('state.name', 'General Consultation')
            ->set('state.selling_price', 100)
            ->call('createProduct')
            ->assertHasNoErrors();

        $service = Product::where('name', 'General Consultation')->sole();
        $this->assertTrue($service->made_to_order);
        $this->assertSame(0, (int) $service->quantity);
        $this->assertStringStartsWith('SVC-', $service->batch_number);
        $this->assertSame(0.0, (float) $service->cost_price);

        // A drug still needs its stock details.
        $drugs = Category::create(['name' => 'Drugs', 'type' => 'drug', 'user_id' => auth()->id()]);
        Livewire::test(ProductsComponent::class)
            ->set('state.category_id', $drugs->id)->set('state.name', 'Timolol')->set('state.selling_price', 35)
            ->call('createProduct')
            ->assertHasErrors(['batch_number', 'quantity', 'expiry_date']);
    }

    public function test_diagnosis_search_matches_any_word_of_the_name(): void
    {
        $this->ownerOn('clinic-pro');
        foreach (['Chronic Angle Closure Glaucoma', 'Glaucoma Suspect', 'Cataract'] as $name) Diagnosis::create(['name' => $name]);

        $records = new PatientRecordsComponent();
        $records->activeTab = 'consultation';
        $records->diagnosisSearch = 'glau';

        // Names starting with the word come first.
        $this->assertSame(['Glaucoma Suspect', 'Chronic Angle Closure Glaucoma'], $records->getDiagnosisResultsProperty()->pluck('name')->all());
    }

    public function test_menus_only_link_pages_the_person_can_open(): void
    {
        $this->ownerOn('clinic-pro');

        // A cashier has the till, not registration, clearance or appointments.
        $this->signIn($this->staff('Cashier'));
        $this->get(route('cashier.sales-records'))->assertOk()
            ->assertSee(route('cashier.seller-desk'), false)
            ->assertDontSee(route('secretary.patients'), false)
            ->assertDontSee(route('secretary.patient-clearance'), false)
            ->assertDontSee(route('secretary.dashboard'), false);

        // A manager without "manage billing" sees no reports or income statement, and can open the patient registry.
        $this->signIn($this->staff('Manager'));
        $this->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee(route('admin.reports'), false)
            ->assertDontSee(route('admin.income-statement'), false)
            ->assertDontSee(route('admin.settings'), false)
            ->assertDontSee(route('doctor.all-records'), false);
        $this->get(route('secretary.patients'))->assertOk();
    }

    public function test_starter_links_and_checklist_leave_out_what_the_plan_has_not(): void
    {
        $this->ownerOn('clinic-starter');
        $keys = fn () => array_merge(array_column(ClinicSetup::for()['steps'], 'key'), array_column(ClinicSetup::for()['extras'], 'key'));
        $this->assertNotContains('cash-book', $keys());
        $this->assertNotContains('insurers', $keys());
        $this->get(route('user.profile'))->assertOk()->assertDontSee(route('admin.audit-trail'), false);

        $this->signIn($this->staff('Doctor'));
        $this->get(route('doctor.dashboard'))->assertOk()->assertDontSee(route('doctor.referrals'), false);

        $this->ownerOn('clinic-pro');
        $this->assertContains('cash-book', $keys());
    }

    public function test_a_cashier_reprints_a_receipt_they_took_payment_on(): void
    {
        $this->ownerOn('clinic-pro');
        $desk = $this->staff('Secretary');
        $cashier = $this->staff('Cashier');
        $theirs = $this->paidSale($desk);
        PaymentTransaction::create(['sale_id' => $theirs->id, 'amount' => 10, 'payment_method' => 'cash', 'collected_by' => $cashier->id]);
        $other = $this->paidSale($desk);

        $this->signIn($cashier);
        $this->get(route('cashier.receipt.show', $theirs->id))->assertOk();
        $this->get(route('cashier.receipt.show', $other->id))->assertNotFound();
    }
}
