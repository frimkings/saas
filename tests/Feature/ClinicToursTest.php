<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminDashboardComponent;
use App\Livewire\Admin\CashBookComponent;
use App\Livewire\Admin\DayCloseComponent;
use App\Livewire\Cashier\CashierPatientClearanceComponent;
use App\Livewire\Cashier\SalesRecordsComponent;
use App\Livewire\Doctor\PatientAwaitingComponent;
use App\Livewire\POSComponent;
use App\Livewire\PosProductGrid;
use App\Livewire\Secretary\PatientsComponent;
use App\Models\CashMovement;
use App\Models\Product;
use App\Models\User;
use App\Support\Guides\TourCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClinicToursTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        $this->actingAs($user);

        return $user;
    }

    public function test_the_owner_gets_a_getting_started_checklist_that_ticks_itself(): void
    {
        $this->userWith('Super Admin');

        $dashboard = Livewire::test(AdminDashboardComponent::class)
            ->assertSee('Getting started with the Clinic Suite')->assertSee('Start the cash book')
            ->assertSeeHtml('data-tour="setup-checklist"')->assertSeeHtml('data-tour="clinic-kpis"')->assertSeeHtml('data-tour="tour-replay"');
        $ticked = fn () => collect(Livewire::test(AdminDashboardComponent::class)->viewData('setup')['steps'])->where('done', true)->pluck('key')->all();
        $this->assertNotContains('products', $ticked());
        $this->assertNotContains('cash-book', $ticked());

        Product::factory()->create();
        CashMovement::create(['type' => CashMovement::OPENING, 'payment_method' => 'cash', 'amount' => 50, 'moved_on' => today()->toDateString()]);
        $this->assertContains('products', $ticked());
        $this->assertContains('cash-book', $ticked());

        // Put away, it stays away for this person.
        $dashboard->call('hideSetup')->assertDontSee('Getting started with the Clinic Suite');
        Livewire::test(AdminDashboardComponent::class)->assertDontSee('Getting started with the Clinic Suite')->assertSeeHtml('data-tour="tour-replay"');

        // The sidebar groups the dashboard tour points at.
        $this->get(route('admin.dashboard'))->assertOk()
            ->assertSee('data-tour="nav-workspace"', false)->assertSee('data-tour="nav-finances"', false)->assertSee('data-tour="nav-staff-security"', false);
    }

    public function test_every_main_clinic_page_has_its_tour_and_the_parts_it_points_at(): void
    {
        $this->userWith('Super Admin');

        foreach ([
            PatientsComponent::class => ['reg-form', 'reg-import', 'reg-search', 'reg-list'],
            CashierPatientClearanceComponent::class => ['clr-stats', 'clr-lists', 'clr-tab-pending', 'clr-tab-cleared'],
            POSComponent::class => ['pos-doctor-carts', 'pos-patient', 'pos-cart'],
            PatientAwaitingComponent::class => ['queue-filters', 'queue-list'],
            SalesRecordsComponent::class => ['sales-totals', 'sales-filters', 'sales-list'],
            DayCloseComponent::class => ['day-close-count', 'day-close-cashiers', 'day-close-payments', 'day-close-submit'],
            CashBookComponent::class => ['cash-book-start', 'cash-book-balances', 'cash-book-record'],
        ] as $page => $parts) {
            $test = Livewire::test($page)->assertSeeHtml('data-tour="tour-replay"');
            foreach ($parts as $part) $test->assertSeeHtml('data-tour="'.$part.'"');
        }
        Livewire::test(PosProductGrid::class)->assertSeeHtml('data-tour="pos-products"');

        // Every step points at a part that exists somewhere, and each tour has a version.
        foreach (['clinic.dashboard', 'clinic.registry', 'clinic.clearance', 'clinic.pos', 'clinic.queue', 'clinic.sales', 'clinic.day-close', 'clinic.cash-book'] as $page) {
            $tour = TourCatalog::tour($page, ['owner' => true]);
            $this->assertNotNull($tour, $page);
            $this->assertSame(1, $tour['version']);
            $this->assertNotEmpty($tour['steps']);
        }
    }

    public function test_tours_tell_staff_that_the_owner_decides_and_the_owner_that_it_is_theirs(): void
    {
        $text = fn (array $context) => collect(TourCatalog::tour('clinic.day-close', $context)['steps'])->pluck('body')->implode(' ');
        $this->assertStringContainsString('Only you can reopen', $text(['owner' => true]));
        $this->assertStringContainsString('Only the owner can reopen', $text(['owner' => false]));
        $this->assertStringNotContainsString('yours alone', collect(TourCatalog::tour('clinic.dashboard', ['owner' => false])['steps'])->pluck('body')->implode(' '));
    }

    public function test_staff_get_page_tours_but_not_the_owner_checklist(): void
    {
        $this->userWith('Cashier');

        Livewire::test(DayCloseComponent::class)->assertSeeHtml('data-tour="tour-replay"')->assertDontSee('Cash book</a>', false);
        Livewire::test(AdminDashboardComponent::class)->assertDontSee('Getting started with the Clinic Suite')->call('hideSetup')->assertForbidden();
    }
}
