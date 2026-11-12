<?php

namespace Tests\Feature;

use App\Livewire\Scans\DiagnosticScansComponent;
use App\Livewire\Scans\ScanCatalogueComponent;
use App\Livewire\Scans\ScanPartnersComponent;
use App\Models\{Clinic, ClinicAddon, ClinicSubscription, DiagnosticScan, ScanPartnerClinic, ScanPartnerDoctor, ScanPartnerRate, SubscriptionPlan, User};
use App\Services\ClinicAccessService;
use App\Support\Feature;
use App\Support\PlanProduct;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Diagnostic scans, phase 1: who gets the feature, the scan catalogue and the partner clinics. */
class DiagnosticScansSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Clinic $clinic;
    private ClinicSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        [$this->clinic, $this->user, $this->subscription] = $this->clinicWith(['clinical', Feature::DIAGNOSTIC_SCANS, Feature::CLINIC_TIERS_CHOSEN], 'Scan Centre', 'Manager');
    }

    /** @return array{Clinic, User, ClinicSubscription} */
    private function clinicWith(array $features, string $name, string $roleName): array
    {
        $user = User::factory()->create();
        $clinic = Clinic::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'deployment_mode' => 'hosted']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $plan = SubscriptionPlan::create(['name' => $name.' plan', 'code' => \Illuminate\Support\Str::slug($name).'-plan', 'included_branches' => 1, 'included_users' => 5,
            'storage_limit_mb' => 100, 'features' => $features, 'base_price' => 10, 'billing_interval' => 'monthly']);
        $subscription = ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth(), 'feature_snapshot' => $features]);
        $this->actAs($user, $clinic, $branch, $roleName);

        return [$clinic, $user, $subscription];
    }

    private function actAs(User $user, Clinic $clinic, $branch, string $roleName): void
    {
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user->assignRole($role);
        \Illuminate\Support\Facades\DB::table('branch_user_role')->insertOrIgnore(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $role->id]);
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($user, $branch);
        $this->actingAs($user);
    }

    private function allowed(): bool
    {
        app(\App\Services\ClinicAddonService::class)->forget($this->clinic->id);

        return app(ClinicAccessService::class)->access(Feature::DIAGNOSTIC_SCANS)['allowed'];
    }

    // --- Licensing ---------------------------------------------------------------------------

    public function test_everything_plans_leave_diagnostic_scans_out_and_only_a_named_plan_or_add_on_brings_it(): void
    {
        $this->assertFalse(PlanProduct::allows(['*'], Feature::DIAGNOSTIC_SCANS));
        $this->assertFalse(PlanProduct::allows([], Feature::DIAGNOSTIC_SCANS));
        $this->assertFalse(PlanProduct::allows(['clinical', '*'], Feature::DIAGNOSTIC_SCANS));
        $this->assertFalse(PlanProduct::allows(['optical', Feature::DIAGNOSTIC_SCANS], Feature::DIAGNOSTIC_SCANS), 'An optical-only plan has no clinic to scan in.');
        $this->assertTrue(PlanProduct::allows(['clinical', Feature::DIAGNOSTIC_SCANS], Feature::DIAGNOSTIC_SCANS));
        $this->assertTrue(PlanProduct::allows(['clinical', '*', Feature::DIAGNOSTIC_SCANS], Feature::DIAGNOSTIC_SCANS));
        // Other features keep working as before on "everything".
        $this->assertTrue(PlanProduct::allows(['clinical', '*'], Feature::APPOINTMENTS));

        // Ticked on an "every extra" plan, it is stored by name; unticked, it isn't there.
        $this->assertContains(Feature::DIAGNOSTIC_SCANS, PlanProduct::features(PlanProduct::CLINIC, [Feature::DIAGNOSTIC_SCANS], true));
        $this->assertNotContains(Feature::DIAGNOSTIC_SCANS, PlanProduct::features(PlanProduct::CLINIC, [], true));
        $this->assertNotContains(Feature::DIAGNOSTIC_SCANS, PlanProduct::extrasOf(['clinical', '*']));
        $this->assertContains(Feature::DIAGNOSTIC_SCANS, PlanProduct::extrasOf(['clinical', '*', Feature::DIAGNOSTIC_SCANS]));

        $this->assertTrue($this->allowed());

        // An "everything" subscription: not included until the add-on is bought.
        $this->subscription->update(['feature_snapshot' => ['clinical', '*']]);
        $this->assertFalse($this->allowed());
        ClinicAddon::create(['clinic_id' => $this->clinic->id, 'feature' => Feature::DIAGNOSTIC_SCANS, 'monthly_price' => 50, 'currency' => 'GHS', 'starts_at' => now()->subMinute()]);
        $this->assertTrue($this->allowed());
    }

    public function test_the_page_and_menu_follow_the_feature(): void
    {
        $this->get(route('frontdesk.scans'))->assertOk()->assertSee('Diagnostic Scans');
        $this->assertStringContainsString('frontdesk.scans', json_encode(\App\Support\ClinicNavigation::reception()));

        $this->subscription->update(['feature_snapshot' => ['clinical', '*']]);
        app(\App\Services\ClinicAddonService::class)->forget($this->clinic->id);
        $this->get(route('frontdesk.scans'))->assertForbidden();
        $this->assertStringNotContainsString('frontdesk.scans', json_encode(\App\Support\ClinicNavigation::reception()));
    }

    public function test_tabs_switch_and_unknown_tabs_fall_back_to_the_scan_desk(): void
    {
        Livewire::test(DiagnosticScansComponent::class)->assertSet('tab', 'desk')->assertSee('Scan Catalogue')
            ->call('setTab', 'partners')->assertSet('tab', 'partners')->assertSee('Add partner clinic')
            ->call('setTab', 'catalogue')->assertSet('tab', 'catalogue')->assertSee('Add scan');
        Livewire::withQueryParams(['tab' => 'nope'])->test(DiagnosticScansComponent::class)->assertSet('tab', 'desk');
    }

    // --- Catalogue ---------------------------------------------------------------------------

    public function test_a_manager_adds_edits_and_archives_scans_with_commission(): void
    {
        Livewire::test(ScanCatalogueComponent::class)
            ->call('add')->assertSet('showForm', true)
            ->set('name', ' OCT Macula ')->set('code', 'oct-m')->set('price', '300')->set('perEye', true)
            ->set('commissionType', 'percent')->set('commissionValue', '10')->set('turnaround', 'Same day')
            ->assertSee('30.00')
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false)
            ->assertSee('OCT Macula')->assertSee('OCT-M')->assertSee('10%');

        $scan = DiagnosticScan::firstOrFail();
        $this->assertSame('OCT Macula', $scan->name);
        $this->assertSame('OCT-M', $scan->code);
        $this->assertTrue($scan->per_eye);
        $this->assertSame($this->clinic->id, $scan->clinic_id);
        $this->assertSame(30.0, DiagnosticScan::commissionOn((float) $scan->price, $scan->commission_type, (float) $scan->commission_value));

        Livewire::test(ScanCatalogueComponent::class)
            ->call('edit', $scan->id)->set('commissionType', 'fixed')->set('commissionValue', '25')->call('save')->assertHasNoErrors()
            ->call('archive', $scan->id)->assertDontSee('OCT Macula')
            ->call('setStatus', 'archived')->assertSee('OCT Macula')
            ->call('restore', $scan->id);

        $scan->refresh();
        $this->assertSame('fixed', $scan->commission_type);
        $this->assertEquals(25, $scan->commission_value);
        $this->assertTrue($scan->is_active);
    }

    public function test_catalogue_validation(): void
    {
        DiagnosticScan::create(['name' => 'VFT 24-2', 'code' => 'VFT', 'price' => 200, 'commission_type' => 'none']);

        Livewire::test(ScanCatalogueComponent::class)->call('add')
            ->set('name', 'VFT 24-2')->set('code', 'vft')->set('price', '100')->set('commissionType', 'percent')->set('commissionValue', '120')
            ->call('save')->assertHasErrors(['name' => 'unique', 'code' => 'unique', 'commissionValue' => 'max']);

        Livewire::test(ScanCatalogueComponent::class)->call('add')
            ->set('name', 'Fundus Photo')->set('price', '100')->set('commissionType', 'fixed')->set('commissionValue', '150')
            ->call('save')->assertHasErrors(['commissionValue' => 'lte']);

        Livewire::test(ScanCatalogueComponent::class)->call('add')
            ->set('name', 'Fundus Photo')->set('price', '100')->set('commissionType', 'none')->set('commissionValue', '')
            ->call('save')->assertHasNoErrors();
        $this->assertEquals(0, DiagnosticScan::where('name', 'Fundus Photo')->value('commission_value'));
    }

    public function test_only_managers_change_the_catalogue_and_partners(): void
    {
        $scan = DiagnosticScan::create(['name' => 'OCT RNFL', 'price' => 250, 'commission_type' => 'percent', 'commission_value' => 10]);
        $partner = ScanPartnerClinic::create(['code' => 'RC-001', 'name' => 'Hilltop Clinic', 'payout_method' => 'mobile_money', 'payout_account' => '0241234567']);

        $this->user->syncRoles([Role::firstOrCreate(['name' => 'Secretary', 'guard_name' => 'web'])]);
        \Illuminate\Support\Facades\DB::table('branch_user_role')->where('user_id', $this->user->id)->delete();
        $this->actAs($this->user->fresh(), $this->clinic, $this->clinic->branches()->first(), 'Secretary');

        Livewire::test(ScanCatalogueComponent::class)->assertSee('OCT RNFL')->assertSee('A manager sets scans and prices.')
            ->call('add')->assertForbidden();
        Livewire::test(ScanCatalogueComponent::class)->call('archive', $scan->id)->assertForbidden();
        Livewire::test(ScanPartnersComponent::class)->call('openPartner', $partner->id)->assertSee('Hilltop Clinic')
            ->assertDontSee('0241234567');
        Livewire::test(ScanPartnersComponent::class)->call('edit', $partner->id)->assertForbidden();
        $this->assertTrue($scan->fresh()->is_active);
    }

    // --- Partner clinics ---------------------------------------------------------------------

    public function test_a_manager_registers_a_partner_with_doctors_and_an_agreed_rate(): void
    {
        $oct = DiagnosticScan::create(['name' => 'OCT Macula', 'price' => 300, 'commission_type' => 'percent', 'commission_value' => 10]);

        $page = Livewire::test(ScanPartnersComponent::class)
            ->call('add')->assertSet('code', 'RC-001')
            ->set('name', 'Hilltop Eye Clinic')->set('phone', '0241112222')->set('notifyVia', 'whatsapp')
            ->set('payoutMethod', 'mobile_money')->set('payoutAccount', '0249990000')
            ->call('save')->assertHasNoErrors();

        $partner = ScanPartnerClinic::firstOrFail();
        $page->assertSet('viewPartnerId', $partner->id)->assertSee('RC-001')->assertSee('0249990000')
            ->set('doctorName', 'Dr Mensah')->set('doctorPhone', '0207770000')->call('addDoctor')->assertHasNoErrors()->assertSee('Dr Mensah')
            ->set('doctorName', 'Dr Mensah')->call('addDoctor')->assertHasErrors(['doctorName' => 'unique'])
            ->set('rateScanId', $oct->id)->set('rateType', 'percent')->set('rateValue', '15')->call('saveRate')->assertHasNoErrors()
            ->assertSee('15%');

        // The account number is stored encrypted, never as typed.
        $raw = \Illuminate\Support\Facades\DB::table('scan_partner_clinics')->where('id', $partner->id)->value('payout_account');
        $this->assertNotSame('0249990000', $raw);
        $this->assertSame('0249990000', $partner->fresh()->payout_account);

        $rate = ScanPartnerRate::firstOrFail();
        $this->assertSame($oct->id, $rate->diagnostic_scan_id);
        $this->assertEquals(15, $rate->commission_value);

        $doctor = ScanPartnerDoctor::firstOrFail();
        $page->call('toggleDoctor', $doctor->id)->assertSee('Left')
            ->call('removeRate', $rate->id)->assertSee("the catalogue's commission applies to every scan", false);
        $this->assertFalse($doctor->fresh()->is_active);
        $this->assertSame(0, ScanPartnerRate::count());

        // A second partner gets the next code; codes and names stay unique.
        Livewire::test(ScanPartnersComponent::class)->call('add')->assertSet('code', 'RC-002')
            ->set('name', 'Hilltop Eye Clinic')->set('code', 'rc-001')->call('save')->assertHasErrors(['name' => 'unique', 'code' => 'unique']);
        Livewire::test(ScanPartnersComponent::class)->call('add')->set('name', 'Riverside')->set('notifyVia', 'email')->call('save')->assertHasErrors(['email']);
    }

    public function test_partners_and_scans_belong_to_their_own_clinic(): void
    {
        DiagnosticScan::create(['name' => 'OCT Macula', 'price' => 300, 'commission_type' => 'none']);
        $partner = ScanPartnerClinic::create(['code' => 'RC-001', 'name' => 'Hilltop Clinic']);

        $this->clinicWith(['clinical', Feature::DIAGNOSTIC_SCANS], 'Other Centre', 'Manager');
        Livewire::test(ScanCatalogueComponent::class)->assertDontSee('OCT Macula');
        Livewire::test(ScanPartnersComponent::class)->assertDontSee('Hilltop Clinic')->call('add')->assertSet('code', 'RC-001');
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(ScanPartnersComponent::class)->call('openPartner', $partner->id);
    }
}
