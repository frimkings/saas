<?php

namespace Tests\Feature;

use App\Models\{Appointments, Clinic, ClinicSubscription, Patient, SubscriptionPlan, User};
use App\Livewire\Doctor\ReferralComponent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Converted reception screens use the Tailwind clinic layout; unconverted ones keep AdminLTE. */
class ClinicLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $secretary;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        foreach (['Super Admin', 'Secretary', 'Doctor', 'Manager', 'Cashier'] as $roleName) Role::findOrCreate($roleName, 'web');

        $clinic = Clinic::create(['name' => 'Clear Sight', 'slug' => 'clear-sight', 'deployment_mode' => 'hosted', 'status' => 'active']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Standard', 'code' => 'standard', 'included_branches' => 1, 'included_users' => 10,
            'features' => ['*'], 'base_price' => 100, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);

        $this->secretary = User::factory()->create(['name' => 'Ama Mensah']);
        $role = Role::findOrCreate('Secretary', 'web');
        $this->secretary->assignRole($role);
        $clinic->users()->attach($this->secretary->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Secretary']);
        $branch->users()->attach($this->secretary->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $this->secretary->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);

        app(TenantContext::class)->set($this->secretary, $clinic, $branch, [$branch->id]);
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $this->secretary->id, 'name' => 'Kwame Boateng', 'contact' => '0241112222', 'gender' => 'Male']);
        Appointments::create(['patient_id' => $patient->id, 'user_id' => $this->secretary->id, 'title' => 'Eye Exam',
            'scheduled_at' => now()->addHour(), 'status' => 'Confirmed', 'duration_minutes' => 30]);
        app(TenantContext::class)->clear();
    }

    public function test_reception_screens_use_the_tailwind_clinic_layout(): void
    {
        foreach ([
            'secretary.dashboard' => ['Reception', 'New patient registrations'],
            'secretary.patients' => ['Registry Hub', 'Kwame Boateng'],
            'secretary.appointments' => ['Appointments', 'Kwame Boateng'],
            'secretary.patient-clearance' => ['Patient Clearance', 'Kwame Boateng'],
            'secretary.spectacles' => ['Spectacle Orders'],
            'cashier.outstanding-balances' => ['Outstanding balances'],
            'cashier.seller-desk' => ['Point of Sale'],
            'cashier.sales-records' => ['Clinic sales records'],
        ] as $route => $texts) {
            $this->actingAs($this->secretary)->get(route($route))->assertOk()
                ->assertSee('aria-label="Main menu"', false)
                ->assertSee('aria-current="page"', false)
                ->assertSee($texts, false)
                ->assertDontSee('adminlte.min.css', false)
                ->assertDontSee('bootstrap.bundle.min.js', false);
        }
    }

    public function test_the_sidebar_shows_the_reception_menu_with_the_open_group(): void
    {
        $this->actingAs($this->secretary)->get(route('secretary.appointments'))->assertOk()
            ->assertSeeInOrder(['Dashboard', 'Needs Attention', 'Patients', 'Registry Hub', 'Appointments', 'Clearance &amp; Spectacles', 'Point of Sale'], false)
            ->assertSee('<div x-data="{ open: true }">', false); // the active group starts open
    }

    public function test_doctor_screens_use_the_clinic_layout_with_the_doctor_menu(): void
    {
        $clinic = Clinic::where('slug', 'clear-sight')->firstOrFail();
        $branch = $clinic->branches()->firstOrFail();
        $doctor = User::factory()->create(['name' => 'Dr Kofi']);
        $role = Role::findOrCreate('Doctor', 'web');
        $doctor->assignRole($role);
        $clinic->users()->attach($doctor->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Doctor']);
        $branch->users()->attach($doctor->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $doctor->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);

        foreach (['doctor.dashboard' => 'Doctor dashboard', 'doctor.patient-awaiting' => 'Queue manager', 'doctor.all-records' => 'Patient records', 'doctor.referrals' => 'Clinical Letters'] as $route => $heading) {
            app(TenantContext::class)->clear();
            $this->actingAs($doctor)->get(route($route))->assertOk()
                ->assertSee($heading)
                ->assertSeeInOrder(['Dashboard', 'Needs Attention', 'Clinical', 'Patient Queue', 'All Records', 'Referrals'])
                ->assertSee('id="clearance-notif-ping"', false)
                ->assertDontSee('Registry Hub')
                ->assertDontSee('adminlte.min.css', false);
        }
    }

    public function test_letter_dialog_renders_for_each_letter_type(): void
    {
        $clinic = Clinic::where('slug', 'clear-sight')->firstOrFail();
        $branch = $clinic->branches()->firstOrFail();
        $doctor = User::factory()->create(['name' => 'Dr Ama']);
        $role = Role::findOrCreate('Doctor', 'web');
        $doctor->assignRole($role);
        $clinic->users()->attach($doctor->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Doctor']);
        $branch->users()->attach($doctor->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $doctor->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        app(TenantContext::class)->set($doctor, $clinic, $branch, [$branch->id]);
        $this->actingAs($doctor);

        foreach (['referral' => ['New Referral Letter', 'Referral to', 'Clinical findings'],
                  'medical_report' => ['New Medical Report', 'Clinical details'],
                  'excuse_duty' => ['New Excuse Duty Letter', 'Excuse period']] as $type => $texts) {
            Livewire::test(ReferralComponent::class)
                ->call('openCreate', $type)
                ->assertSeeInOrder([...$texts, 'Record status', 'Save &amp; create letter'], false)
                ->assertSee('id="letter-patient-search"', false);
        }
    }

    public function test_shared_pages_use_the_clinic_layout_for_reception(): void
    {
        foreach (['user.profile', 'staff.messages', 'attention', 'refunds.logs'] as $route) {
            app(TenantContext::class)->clear();
            $this->actingAs($this->secretary)->get(route($route))->assertOk()
                ->assertSee('aria-label="Main menu"', false)
                ->assertSee('Registry Hub')
                ->assertDontSee('adminlte.min.css', false);
        }
    }

    public function test_admins_get_the_admin_menu_everywhere(): void
    {
        $clinic = Clinic::where('slug', 'clear-sight')->firstOrFail();
        $branch = $clinic->branches()->firstOrFail();
        $admin = User::factory()->create();
        $role = Role::findOrCreate('Manager', 'web');
        $admin->assignRole($role);
        $clinic->users()->attach($admin->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Manager']);
        $branch->users()->attach($admin->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        app(TenantContext::class)->clear();
        foreach (['user.profile', 'staff.messages', 'attention'] as $route) {
            app(TenantContext::class)->clear();
            $this->actingAs($admin)->get(route($route))->assertOk()
                ->assertSee('aria-label="Main menu"', false)
                ->assertSee('Business Overview')
                ->assertDontSee('Registry Hub')
                ->assertDontSee('adminlte.min.css', false);
        }
    }
}
