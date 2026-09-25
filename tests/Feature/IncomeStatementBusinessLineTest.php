<?php

namespace Tests\Feature;

use App\Livewire\Admin\IncomeStatementComponent;
use App\Models\IncomeStatementEntry;
use App\Models\IncomeStatementPeriodLock;
use App\Models\IncomeStatementTemplate;
use App\Models\User;
use App\Services\Finance\PeriodLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IncomeStatementBusinessLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_clinic_income_statement_ignores_optical_lines_templates_and_locks(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $this->actingAs($user);
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();

        IncomeStatementEntry::create(['section' => IncomeStatementEntry::OPERATING_EXPENSE, 'name' => 'Optical shop rent', 'amount' => 700,
            'entry_date' => $from->toDateString(), 'is_active' => true, 'business_line' => 'optical']);
        IncomeStatementTemplate::create(['section' => IncomeStatementEntry::OPERATING_EXPENSE, 'name' => 'Optical template', 'amount' => 50,
            'is_active' => true, 'business_line' => 'optical']);
        app(PeriodLockService::class)->lock('optical', $from, $to);

        $page = Livewire::test(IncomeStatementComponent::class)
            ->set('fromDate', $from->toDateString())->set('toDate', $to->toDateString())
            ->assertSet('isLocked', false)
            ->assertDontSee('Optical shop rent')
            ->assertDontSee('Optical template');
        $this->assertSame(0.0, (float) $page->instance()->statement['operating_expenses']);

        $page->set('selectedPreset', 'Rent')->set('amount', '3000')->call('saveEntry')->assertHasNoErrors();
        $this->assertSame('clinic', IncomeStatementEntry::where('name', 'Rent')->value('business_line'));
        $page->call('lockPeriod')->assertSet('isLocked', true);

        // One lock per line for the same period.
        $this->assertEqualsCanonicalizing(['clinic', 'optical'], IncomeStatementPeriodLock::pluck('business_line')->all());
        $page->call('unlockPeriod')->assertSet('isLocked', false);
        $this->assertNotNull(app(PeriodLockService::class)->find('optical', $from, $to), 'Unlocking the clinic leaves the optical lock alone.');
    }

    public function test_clinic_statement_exports_use_the_shared_layout_and_page_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $this->actingAs($user);
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();
        IncomeStatementEntry::create(['section' => IncomeStatementEntry::OPERATING_EXPENSE, 'name' => '=Rent', 'amount' => 1000,
            'entry_date' => $from->toDateString(), 'notes' => 'Paid by transfer', 'is_active' => true]);
        IncomeStatementEntry::create(['section' => IncomeStatementEntry::OPERATING_EXPENSE, 'name' => 'Optical only', 'amount' => 5,
            'entry_date' => $from->toDateString(), 'is_active' => true, 'business_line' => 'optical']);
        app(PeriodLockService::class)->lock('clinic', $from, $to);
        $period = ['from' => $from->toDateString(), 'to' => $to->toDateString()];

        $csv = $this->get(route('admin.income-statement.export.csv', $period))
            ->assertOk()->assertDownload('income-statement-'.$from->toDateString().'-to-'.$to->toDateString().'.csv')->streamedContent();
        $this->assertStringContainsString("'=Rent,1000.00,\"Paid by transfer\"", $csv, 'Formulas are neutralised; notes kept.');
        $this->assertStringContainsString('"Net profit",-1000.00', $csv);
        $this->assertStringContainsString('Period locked', $csv);
        $this->assertStringNotContainsString('Optical only', $csv);
        $this->get(route('admin.income-statement.export.pdf', $period))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('admin.income-statement.preview', $period))->assertOk()->assertSee('Income Statement')->assertSee('Print');
    }

    public function test_managers_without_billing_access_cannot_download_the_clinic_statement(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));

        $this->actingAs($manager)->get(route('admin.income-statement.export.csv'))->assertForbidden();
        $this->get(route('admin.income-statement.export.pdf'))->assertForbidden();
    }
}
