<?php

namespace Tests\Feature;

use App\Livewire\Doctor\PatientRecordsComponent;
use App\Livewire\ReportsComponent;
use App\Models\{CashierPatientClearance, Clinic, ClinicAddon, ClinicSubscription, Patient, SubscriptionPlan, User};
use App\Services\ClinicAddonService;
use App\Services\LicenseService;
use App\Services\SubscriptionQuotaService;
use App\Support\Feature;
use App\Support\PlanProduct;
use App\Support\Pricing\ClinicalSuitePlans;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every Clinical Suite plan against every priced add-on: closed without it (unless the plan has it), open once
 * bought, closed again once cancelled, and offered on the clinic's Add-ons page only when the plan lacks it.
 * Checks use the real page or behaviour where there is one; the owner emails are checked through the licence.
 */
class ClinicAddonMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $owner;
    protected Patient $patient;
    protected CashierPatientClearance $clearance;

    protected function clinicOn(string $family): void
    {
        config(['tenancy.enabled' => true]);
        foreach (['Super Admin', 'Manager', 'Cashier', 'Doctor', 'Secretary'] as $name) Role::findOrCreate($name, 'web');
        $definition = ClinicalSuitePlans::plans()[$family];
        $plan = SubscriptionPlan::create(['name' => $definition['name'], 'code' => $family.'-'.uniqid(), 'family_code' => $family.'-'.uniqid(), 'version' => 1,
            'product' => PlanProduct::CLINIC, 'features' => ClinicalSuitePlans::features($definition), 'included_branches' => 1,
            'included_users' => $definition['users'], 'storage_limit_mb' => $definition['storage_mb'], 'base_price' => $definition['price'],
            'annual_price' => $definition['price'] * 10, 'currency' => 'GHS', 'billing_interval' => 'monthly', 'is_active' => true]);
        $this->clinic = Clinic::create(['name' => 'Matrix '.$family, 'slug' => 'matrix-'.$family, 'status' => 'active', 'deployment_mode' => 'hosted']);
        $branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'feature_snapshot' => $plan->features,
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(['Super Admin', 'Doctor']);
        $this->clinic->users()->attach($this->owner->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Super Admin']);
        $branch->users()->attach($this->owner->id, ['status' => 'active', 'is_default' => true]);
        foreach (['Super Admin', 'Doctor'] as $role) {
            DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $this->owner->id, 'role_id' => Role::findByName($role)->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(TenantContext::class)->set($this->owner, $this->clinic, $branch, [$branch->id]);
        $this->actingAs($this->owner);
        $this->patient = Patient::createWithGeneratedPxNumber(['user_id' => $this->owner->id, 'name' => 'Ama Serwaa', 'contact' => '0241112222', 'gender' => 'Female']);
        $this->clearance = CashierPatientClearance::create(['user_id' => $this->owner->id, 'patient_id' => $this->patient->id, 'payment_status' => 'Paid', 'doctor_status' => false, 'clearance_date' => now()->toDateString()]);
    }

    /** @var array<string, int> last status per route, for the findings */
    private array $statuses = [];

    private function opens(string $route, array $params = []): bool
    {
        // PDFs come back as streamed responses, which only the base response can report on.
        $status = $this->get(route($route, $params))->baseResponse->getStatusCode();
        $this->statuses[$route] = $status;

        return $status === 200;
    }

    /** feature => fn (): bool, true when the clinic can use it. */
    private function checks(): array
    {
        $record = fn () => Livewire::test(PatientRecordsComponent::class, ['clearance' => $this->clearance]);
        $flag = fn (string $feature) => fn () => LicenseService::has($feature);

        return [
            Feature::APPOINTMENTS => fn () => $this->opens('secretary.appointments'),
            Feature::REFERRALS => fn () => $this->opens('doctor.referrals'),
            Feature::CLINICAL_DOCUMENTS => fn () => str_contains($record()->html(), 'Clinical Documents'),
            Feature::SALES_EXPORT => fn () => str_contains($this->get(route('cashier.sales-records'))->getContent(), 'wire:click="exportCSV"'),
            Feature::DISCOUNTS => $flag(Feature::DISCOUNTS),
            Feature::SALES_REPORTS_MONTH => fn () => $this->opens('admin.reports'),
            Feature::INVENTORY_ALERTS => fn () => $this->opens('admin.inventory-alerts'),
            Feature::PRODUCT_IMPORT => fn () => str_contains($this->get(route('admin.product'))->getContent(), 'Import / export'),
            Feature::PATIENT_MESSAGES => fn () => $this->opens('admin.sms-settings') && $this->opens('admin.sms-logs'),
            Feature::SMS_CAMPAIGNS => fn () => $this->opens('admin.broadcast'),
            Feature::STAFF_MESSAGES => fn () => $this->opens('staff.messages'),
            Feature::NEEDS_ATTENTION => fn () => $this->opens('attention'),
            Feature::DAILY_SUMMARY => $flag(Feature::DAILY_SUMMARY),
            Feature::CUSTOM_ROLES => fn () => $this->opens('admin.roles-permissions'),
            Feature::HISTORY_CHARTS => fn () => str_contains($record()->html(), 'Visual Acuity Trend'),
            Feature::CLINICAL_PDFS => fn () => $this->opens('doctor.medical-record.preview', ['patient' => $this->patient, 'clearance' => $this->clearance]),
            Feature::OUTSTANDING_BALANCES => fn () => $this->opens('cashier.outstanding-balances'),
            Feature::QUOTATIONS => fn () => $this->opens('admin.quotations'),
            Feature::SPECTACLE_ORDERS => fn () => $this->opens('secretary.spectacles'),
            Feature::SALES_REPORTS => fn () => $this->opens('admin.reports') && Livewire::test(ReportsComponent::class)->get('monthOnly') === false,
            Feature::EXPENSE_TRACKING => fn () => $this->opens('admin.expenses'),
            Feature::CASH_BOOK => fn () => $this->opens('admin.cash-book') && $this->opens('admin.day-close'),
            Feature::PURCHASING => fn () => $this->opens('admin.suppliers') && $this->opens('admin.purchase-orders') && $this->opens('admin.stock-transfers'),
            Feature::BUSINESS_OVERVIEW => fn () => str_contains($this->get(route('admin.dashboard'))->getContent(), 'Revenue, last 7 days'),
            Feature::WEEKLY_SUMMARY => $flag(Feature::WEEKLY_SUMMARY),
            Feature::MONTHLY_SUMMARY => $flag(Feature::MONTHLY_SUMMARY),
            Feature::MORNING_ALERTS => $flag(Feature::MORNING_ALERTS),
            Feature::SPECTACLES_PRO => fn () => $this->opens('admin.lens-outstanding-report'),
            Feature::PATIENT_LEDGER => fn () => $this->opens('admin.patient-ledger'),
            Feature::ADVANCED_REPORTS => fn () => $this->opens('admin.income-statement'),
            Feature::APPROVALS => fn () => str_contains($this->get(route('admin.approvals'))->getContent(), "switchType('discount')"),
            Feature::INSURANCE => fn () => $this->opens('admin.insurance.claims'),
            Feature::AUDIT_TRAIL => fn () => $this->opens('admin.audit-trail') && $this->opens('admin.login-history'),
        ];
    }

    public function test_every_plan_with_every_add_on(): void
    {
        Artisan::call('pricing:clinical-suite'); // prices the add-ons
        $rows = [];
        $problems = [];
        foreach (array_keys(ClinicalSuitePlans::plans()) as $family) {
            $this->clinicOn($family);
            $addons = app(ClinicAddonService::class);
            $catalogue = $addons->catalogue($this->clinic);
            foreach ($this->checks() as $feature => $check) {
                $addons->forget($this->clinic->id);
                $included = $addons->planIncludes($this->clinic, $feature);
                $offered = (bool) ($catalogue[$feature]['offered'] ?? false);
                try {
                    $before = $check();
                    if ($included) {
                        $row = [$before ? 'in plan' : 'IN PLAN BUT CLOSED', '', ''];
                        if (! $before) $problems[] = "$family / $feature: included in the plan but closed (".json_encode($this->statuses).')';
                    } else {
                        $addon = $addons->add($this->clinic, $feature, (float) ($catalogue[$feature]['price'] ?? 0), 'matrix', $this->owner);
                        $addons->forget($this->clinic->id);
                        $with = $check();
                        ClinicAddon::whereKey($addon->id)->delete();
                        // Clinical documents on a plan with no storage brings a storage pack; it goes with it here.
                        if ($feature === Feature::CLINICAL_DOCUMENTS) ClinicAddon::where('clinic_id', $this->clinic->id)->where('feature', Feature::SCAN_STORAGE_PACK)->delete();
                        $addons->forget($this->clinic->id);
                        $after = $check();
                        $row = [$before ? 'OPEN WITHOUT' : 'closed', $with ? 'opens' : 'STAYS CLOSED', $after ? 'STILL OPEN' : 'closed again'];
                        if ($before) $problems[] = "$family / $feature: open without the add-on";
                        if (! $with) $problems[] = "$family / $feature: the add-on doesn't open it";
                        if ($after) $problems[] = "$family / $feature: still open after the add-on is removed";
                        if (! $offered) $problems[] = "$family / $feature: not offered to the clinic";
                    }
                } catch (\Throwable $e) {
                    $row = ['ERROR', mb_substr($e->getMessage(), 0, 80), ''];
                    $problems[] = "$family / $feature: ".mb_substr($e->getMessage(), 0, 160);
                }
                $rows[] = [$family, $feature, (float) ($catalogue[$feature]['price'] ?? 0), $offered && ! $included ? 'yes' : 'no', ...$row];
            }

            // Storage packs add to the plan's own allowance.
            $quota = app(SubscriptionQuotaService::class);
            $base = $quota->storageLimitMb($this->clinic);
            $pack = $addons->add($this->clinic, Feature::SCAN_STORAGE_PACK, 20, 'matrix', $this->owner, null, 1);
            $addons->forget($this->clinic->id);
            $withPack = $quota->storageLimitMb($this->clinic);
            ClinicAddon::whereKey($pack->id)->delete();
            $rows[] = [$family, Feature::SCAN_STORAGE_PACK, 20.0, 'yes', "{$base} MB", "{$withPack} MB", ''];
            if ($base !== null && $withPack !== $base + 1024) $problems[] = "$family / storage pack: {$base} MB became {$withPack} MB";
        }

        $out = "| Plan | Add-on | GHS | On Add-ons page | Without | With add-on | Removed |\n|---|---|---|---|---|---|---|\n";
        foreach ($rows as $r) $out .= '| '.implode(' | ', $r)." |\n";
        file_put_contents(storage_path('app/addon-matrix.md'), $out."\n## Problems\n".($problems ? '- '.implode("\n- ", $problems) : 'None')."\n");

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
