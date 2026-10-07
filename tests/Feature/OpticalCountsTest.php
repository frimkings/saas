<?php

namespace Tests\Feature;

use App\Livewire\Optical\OpticalDashboardComponent;
use App\Livewire\Optical\OpticalOrdersComponent;
use App\Livewire\Secretary\SecretaryDashboardComponent;
use App\Models\{CashierPatientClearance, Clinic, ClinicSubscription, Consultations, LensOrder, Patient, Refractions, SubscriptionPlan, User};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Dashboard and Orders page counts agree, whichever page set the order's status. */
class OpticalCountsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->user = User::factory()->create();
        $clinic = Clinic::create(['name' => 'Count Optical', 'slug' => 'count-optical', 'deployment_mode' => 'hosted']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Count plan', 'code' => 'count-plan', 'included_branches' => 1, 'included_users' => 5,
            'storage_limit_mb' => 100, 'features' => ['*'], 'base_price' => 10, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        app(TenantContext::class)->set($this->user, $clinic, $branch, [$branch->id]);
        $role = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $this->user->assignRole($role);
        \Illuminate\Support\Facades\DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $this->user->id, 'role_id' => $role->id]);
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($this->user, $branch);
        $this->actingAs($this->user);
    }

    private function walkIn(string $orderId, string $status, array $extra = []): LensOrder
    {
        return LensOrder::create(array_merge(['order_id' => $orderId, 'status' => $status, 'order_source' => 'walk_in',
            'customer_name' => 'Walk In', 'frame_model_number' => 'Frame', 'frame_price' => 0, 'lens_price' => 0,
            'pickUpDate' => now()->toDateString(), 'user_id' => $this->user->id], $extra));
    }

    /** A spectacle order made from a clinic refraction; its money is on the consultation bill. */
    private function clinicOrder(string $orderId, string $status, float $lensPrice = 0): LensOrder
    {
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $this->user->id, 'name' => 'Clinic '.$orderId, 'contact' => '0241115555', 'gender' => 'Other']);
        $clearance = CashierPatientClearance::create(['user_id' => $this->user->id, 'patient_id' => $patient->id, 'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = Consultations::create(['user_id' => $this->user->id, 'patient_id' => $patient->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Blurred vision']);
        $refraction = Refractions::create(['user_id' => $this->user->id, 'consultation_id' => $consultation->id, 'refractionOD' => '-2.00', 'refractionOS' => '-1.50',
            'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6']);

        return LensOrder::create(['user_id' => $this->user->id, 'refraction_id' => $refraction->id, 'order_id' => $orderId,
            'frame_model_number' => "Patient's own frame", 'own_frame' => true, 'frame_price' => 0, 'lens_price' => $lensPrice,
            'status' => $status, 'pickUpDate' => now()->addDay()->toDateString()]);
    }

    public function test_dashboard_counts_every_ready_order_under_both_status_names(): void
    {
        foreach (range(1, 7) as $i) $this->walkIn("OPT-RFC{$i}", 'Ready for Collection');
        foreach (range(1, 5) as $i) $this->walkIn("OPT-RDY{$i}", 'Ready');
        $this->walkIn('OPT-LAB', 'Sent to Lab');

        Livewire::test(OpticalDashboardComponent::class)
            ->assertViewHas('readyCount', 12)
            ->assertViewHas('readyOrders', fn ($orders) => $orders->count() === 10);
    }

    public function test_dashboard_outstanding_matches_the_orders_page_balance_due(): void
    {
        $this->walkIn('OPT-OWES', 'Pending', ['frame_price' => 200, 'lens_price' => 100, 'paid_amount' => 100]);
        $this->walkIn('OPT-PAID', 'Collected', ['frame_price' => 150, 'paid_amount' => 150]);
        $this->walkIn('OPT-QUOTE', 'Quotation', ['frame_price' => 500]);
        $this->clinicOrder('ORD-CLINIC', 'Pending', 1200);

        Livewire::test(OpticalDashboardComponent::class)
            ->assertViewHas('outstandingTotal', 200.0)
            ->assertViewHas('outstandingCount', 1);

        Livewire::test(OpticalOrdersComponent::class)
            ->assertViewHas('filterCounts', fn ($counts) => $counts['due'] === 1);
    }

    public function test_orders_page_at_the_lab_chip_includes_clinic_in_lab_jobs(): void
    {
        $this->walkIn('OPT-SENT', 'Sent to Lab');
        $this->walkIn('OPT-PROD', 'In Production');
        $this->clinicOrder('ORD-INLAB', 'In Lab');
        $this->walkIn('OPT-PEND', 'Pending');

        Livewire::test(OpticalOrdersComponent::class)
            ->assertViewHas('filterCounts', fn ($counts) => $counts['lab'] === 3)
            ->call('setFilter', 'lab')->assertSee('ORD-INLAB')->assertDontSee('OPT-PEND');
    }

    public function test_orders_show_who_created_them_and_who_changed_each_status(): void
    {
        $this->user->update(['name' => 'Ama Owusu']);
        $kofi = User::factory()->create(['name' => 'Kofi Mensah']);
        $mine = $this->walkIn('OPT-AMA', 'Pending');
        $this->walkIn('OPT-KOFI', 'Pending', ['user_id' => $kofi->id]);

        Livewire::test(OpticalOrdersComponent::class)
            ->assertSee('by Ama Owusu')->assertSee('by Kofi Mensah')
            ->assertViewHas('creators', fn ($creators) => $creators->pluck('name')->all() === ['Ama Owusu', 'Kofi Mensah'])
            ->set('creatorFilter', (string) $kofi->id)->assertSee('OPT-KOFI')->assertDontSee('OPT-AMA')
            ->assertViewHas('filterCounts', fn ($counts) => $counts[''] === 1)
            ->call('clearFilters')->assertSet('creatorFilter', '');

        // Kofi sends Ama's order to the lab; the history names him, not the creator.
        $this->actingAs($kofi);
        app(\App\Services\OpticalOrderWorkflowService::class)->transition($mine->id, 'Sent to Lab');
        $this->actingAs($this->user);
        $mine->refresh()->update(['status' => 'Cancelled', 'cancellation_reason' => 'Customer changed their mind']);
        $mine->update(['notes' => 'No status change']);

        $events = $mine->events()->get();
        $this->assertSame([['Pending', 'Sent to Lab', $kofi->id, null], ['Sent to Lab', 'Cancelled', $this->user->id, 'Customer changed their mind']],
            $events->map(fn ($e) => [$e->from_status, $e->to_status, $e->user_id, $e->note])->all());
        $this->assertSame($mine->clinic_id, $events->first()->clinic_id);

        Livewire::test(OpticalOrdersComponent::class)->call('openOrder', $mine->id)
            ->assertSeeInOrder(['History', 'Created', 'by Ama Owusu', 'Sent to Lab', 'Kofi Mensah', 'Cancelled', 'Ama Owusu', 'Customer changed their mind']);
    }

    public function test_secretary_dashboard_counts_clinic_spectacles_ready_under_both_names_only(): void
    {
        $this->clinicOrder('ORD-READY', 'Ready');
        $this->clinicOrder('ORD-RFC', 'Ready for Collection');
        $this->walkIn('OPT-WALKIN', 'Ready for Collection');

        Livewire::test(SecretaryDashboardComponent::class)->assertSet('spectaclesReady', 2);
    }
}
