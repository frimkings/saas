<?php

namespace Tests\Feature;

use App\Livewire\Admin\LegacyMedicationNamesComponent;
use App\Livewire\Admin\LegacyPaymentsComponent;
use App\Models\{Clinic, ClinicSubscription, ExternalImportBatch, Patient, Product, SubscriptionPlan, User};
use App\Support\LegacyMedicationProducts;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Expenses from a clinic's previous software are history: their own read-only page, never the Expense Tracker. */
class LegacyExpensesTest extends TestCase
{
    use RefreshDatabase;

    protected Clinic $clinic;
    protected int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Standard', 'code' => 'standard', 'included_branches' => 1, 'included_users' => 10,
            'features' => ['*'], 'base_price' => 100, 'billing_interval' => 'monthly']);
        $this->clinic = $this->clinic('Clear Sight', $plan);
        $this->branchId = (int) $this->clinic->branches()->value('id');
    }

    private function clinic(string $name, SubscriptionPlan $plan): Clinic
    {
        $clinic = Clinic::create(['name' => $name, 'slug' => Str::slug($name), 'deployment_mode' => 'hosted', 'status' => 'active']);
        $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);

        return $clinic;
    }

    protected function member(string $roleName, ?Clinic $clinic = null): User
    {
        $clinic ??= $this->clinic;
        $branch = $clinic->branches()->first();
        $user = User::factory()->create();
        $role = Role::findOrCreate($roleName, 'web');
        $user->assignRole($role);
        $clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => $roleName]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    protected function legacy(array $row, ?Clinic $clinic = null): void
    {
        DB::table('legacy_expenses')->insert($row + ['clinic_id' => ($clinic ?? $this->clinic)->id, 'source' => 'cloudify', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_owners_read_previous_software_expenses_on_their_own_page(): void
    {
        $owner = $this->member('Super Admin');
        $this->legacy(['expense_date' => '2024-03-08', 'category' => 'Drinking water', 'description' => 'Dispenser bottle', 'amount' => 100, 'reference' => 'CLD-7', 'recorded_by' => 'Ama']);
        $this->legacy(['expense_date' => '2025-06-01', 'category' => 'Rent', 'description' => 'Shop rent', 'amount' => 1500]);
        $other = $this->clinic('Other Eyes', SubscriptionPlan::first());
        $this->legacy(['expense_date' => '2025-01-01', 'description' => 'Not ours', 'amount' => 999], $other);

        $this->actingAs($owner)->get(route('admin.expenses.previous'))->assertOk()
            ->assertSee('Previous software expenses')
            ->assertSee(['Dispenser bottle', 'Ref CLD-7', 'Drinking water', 'Shop rent'])
            ->assertSee('1,600.00')
            ->assertSee('Mar 2024 – Jun 2025')
            ->assertDontSee('Not ours')
            ->assertDontSee('Cloudify');

        $this->actingAs($owner)->get(route('admin.expenses'))->assertOk()
            ->assertSee(route('admin.expenses.previous'), false)
            ->assertDontSee('Dispenser bottle');
    }

    public function test_the_link_only_shows_when_there_is_history_and_cashiers_cannot_open_it(): void
    {
        $owner = $this->member('Super Admin');
        $this->actingAs($owner)->get(route('admin.expenses'))->assertOk()->assertDontSee(route('admin.expenses.previous'), false);

        $this->legacy(['expense_date' => '2024-03-08', 'description' => 'Dispenser bottle', 'amount' => 100]);
        $this->actingAs($owner)->get(route('admin.expenses'))->assertOk()->assertSee(route('admin.expenses.previous'), false);
        $cashier = $this->member('Cashier');
        $response = $this->actingAs($cashier)->get(route('admin.expenses.previous'));
        $this->assertContains($response->status(), [302, 403], 'cashiers are kept out');
        $response->assertDontSee('Dispenser bottle');
    }

    public function test_owners_read_previous_software_payments_for_the_whole_clinic(): void
    {
        $owner = $this->member('Super Admin');
        $this->actingAs($owner)->get(route('admin.sales-records'))->assertOk()->assertDontSee(route('admin.sales-records.previous'), false);

        app(TenantContext::class)->set($owner, $this->clinic, $this->clinic->branches()->first(), [$this->branchId]);
        $ama = Patient::createWithGeneratedPxNumber(['user_id' => $owner->id, 'name' => 'Ama Serwaa', 'contact' => '0241112222', 'gender' => 'Female']);
        $kofi = Patient::createWithGeneratedPxNumber(['user_id' => $owner->id, 'name' => 'Kofi Boateng', 'contact' => '0241113333', 'gender' => 'Male']);
        app(TenantContext::class)->clear();
        $pay = fn (Patient $p, string $at, float $amount, ?string $method, array $more = []) => DB::table('patient_legacy_payments')->insert($more + ['clinic_id' => $this->clinic->id,
            'patient_id' => $p->id, 'source' => 'cloudify', 'paid_at' => $at, 'amount' => $amount, 'payment_method' => $method, 'created_at' => now(), 'updated_at' => now()]);
        $pay($ama, '2024-02-01 10:00:00', 200, 'Cash', ['reference' => 'R-1', 'invoice_number' => 'INV-9']);
        $pay($ama, '2025-05-05 09:30:00', 300, 'MTN MoMo');
        $pay($kofi, '2026-07-01 12:00:00', 150, 'Cash');

        $this->actingAs($owner)->get(route('admin.sales-records'))->assertOk()->assertSee(route('admin.sales-records.previous'), false)->assertSee('Before you moved here');
        $this->actingAs($owner)->get(route('admin.sales-records.previous'))->assertOk()
            ->assertSee(['Previous software payments', 'Ama Serwaa', $ama->pxnumber, 'Kofi Boateng', 'Invoice INV-9', 'Feb 2024 – Jul 2026'])
            ->assertSee('650.00')->assertSee('350.00')
            ->assertDontSee('Cloudify');

        Livewire::actingAs($owner)->test(LegacyPaymentsComponent::class)
            ->set('method', 'Cash')->assertSee('Kofi Boateng')->assertViewHas('total', 350.0)
            ->set('method', '')->set('search', 'Kofi')->assertDontSee('Ama Serwaa')->assertViewHas('total', 150.0)
            ->call('resetFilters')->set('fromDate', '2025-01-01')->set('toDate', '2025-12-31')->assertViewHas('total', 300.0)->assertViewHas('patients', 1);

        // Each patient links to their ledger, which says what they paid before and links back, filtered to them.
        $this->actingAs($owner)->get(route('admin.sales-records.previous'))->assertSee(route('admin.patient-ledger', ['patient' => $ama->id]), false);
        $this->actingAs($owner)->get(route('admin.patient-ledger', ['patient' => $ama->id]))->assertOk()
            ->assertSee('Ama Serwaa')->assertSee('500.00 in the previous software (2 payments)')
            ->assertSee(route('admin.sales-records.previous', ['search' => $ama->pxnumber]), false);
        $this->actingAs($owner)->get(route('admin.sales-records.previous', ['search' => $ama->pxnumber]))->assertSee('Ama Serwaa')->assertDontSee('Kofi Boateng');

        $cashier = $this->member('Cashier');
        $this->assertContains($this->actingAs($cashier)->get(route('admin.sales-records.previous'))->status(), [302, 403], 'cashiers are kept out');
    }

    public function test_owners_match_previous_software_drug_names_to_products(): void
    {
        $owner = $this->member('Super Admin');
        app(TenantContext::class)->set($owner, $this->clinic, $this->clinic->branches()->first(), [$this->branchId]);
        $optilex = Product::create(['user_id' => $owner->id, 'name' => 'Optilex eye drops', 'batch_number' => 'OP-001', 'quantity' => 5, 'selling_price' => 40, 'cost_price' => 20, 'manufacture_date' => '2026-01-01', 'expiry_date' => '2028-01-01']);
        Product::create(['user_id' => $owner->id, 'name' => 'Lubricant drops', 'batch_number' => 'LU-001', 'quantity' => 5, 'selling_price' => 30, 'cost_price' => 10, 'manufacture_date' => '2026-01-01', 'expiry_date' => '2028-01-01']);
        $ama = Patient::createWithGeneratedPxNumber(['user_id' => $owner->id, 'name' => 'Ama Serwaa', 'contact' => '0241112222', 'gender' => 'Female']);
        $kofi = Patient::createWithGeneratedPxNumber(['user_id' => $owner->id, 'name' => 'Kofi Boateng', 'contact' => '0241113333', 'gender' => 'Male']);
        foreach ([[$ama, 'OPTILEX', '2025-01-10'], [$kofi, 'Optilex ', '2025-03-02'], [$ama, 'LUBRICANT DROPS', '2024-06-01'], [$kofi, 'Tonometry', '2025-03-02']] as [$patient, $item, $day])
            DB::table('patient_legacy_medications')->insert(['clinic_id' => $this->clinic->id, 'patient_id' => $patient->id, 'source' => 'cloudify', 'prescribed_on' => $day, 'item' => $item, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->get(route('admin.product'))->assertOk()->assertSee(route('admin.legacy-medication-names'), false);
        $this->actingAs($owner)->get(route('admin.legacy-medication-names'))->assertOk()
            ->assertSee(['Previous software drug names', 'OPTILEX', 'Tonometry', '2 patients'])
            ->assertDontSee('LUBRICANT DROPS', false)  // same name as a product, so already matched
            ->assertDontSee('Cloudify');
        $this->assertSame(['lubricant drops' => $this->productId('Lubricant drops')], LegacyMedicationProducts::forNames(['OPTILEX', 'Lubricant Drops', 'Tonometry']));

        $page = Livewire::actingAs($owner)->test(LegacyMedicationNamesComponent::class)->assertViewHas('unmatchedCount', 2)
            ->call('match', 'optilex', (string) $optilex->id)->assertViewHas('unmatchedCount', 1)->assertDontSee('OPTILEX');
        $this->assertDatabaseHas('legacy_medication_matches', ['clinic_id' => $this->clinic->id, 'name_key' => 'optilex', 'product_id' => $optilex->id, 'matched_by' => $owner->id]);
        $this->assertSame($optilex->id, LegacyMedicationProducts::forNames(['Optilex'])['optilex'], 'both spellings use the match');

        $page->set('unmatchedOnly', false)->assertSee('Remove match')->call('clear', 'optilex')->assertViewHas('unmatchedCount', 2);
        $this->assertSame(0, DB::table('legacy_medication_matches')->count());
        $page->call('match', 'not a drug we had', (string) $optilex->id);
        $this->assertSame(0, DB::table('legacy_medication_matches')->count(), 'only names from the previous software');

        $cashier = $this->member('Cashier');
        $this->assertContains($this->actingAs($cashier)->get(route('admin.legacy-medication-names'))->status(), [302, 403]);
    }

    private function productId(string $name): int
    {
        return (int) DB::table('products')->where('clinic_id', $this->clinic->id)->where('name', $name)->value('id');
    }

    public function test_expenses_an_earlier_import_put_in_the_books_move_to_history(): void
    {
        $owner = $this->member('Super Admin');
        $batch = ExternalImportBatch::create(['uuid' => (string) Str::uuid(), 'clinic_id' => $this->clinic->id, 'source' => 'cloudify', 'status' => 'completed']);
        $track = fn (string $table, int $id) => DB::table('external_import_rows')->insert(['external_import_batch_id' => $batch->id, 'table_name' => $table, 'record_id' => $id]);
        $now = now();

        $imported = DB::table('expense_categories')->insertGetId(['clinic_id' => $this->clinic->id, 'name' => 'Drinking water', 'section' => 'operating_expense', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $track('expense_categories', $imported);
        $shared = DB::table('expense_categories')->insertGetId(['clinic_id' => $this->clinic->id, 'name' => 'Rent', 'section' => 'operating_expense', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $track('expense_categories', $shared);
        $expense = fn (array $row) => DB::table('expenses')->insertGetId($row + ['clinic_id' => $this->clinic->id, 'branch_id' => $this->branchId, 'recorded_by' => $owner->id, 'created_at' => $now, 'updated_at' => $now]);
        $old = $expense(['expense_category_id' => $imported, 'expense_date' => '2024-03-08', 'description' => 'Dispenser bottle', 'amount' => 100, 'reference' => 'CLD-7', 'notes' => "Big one\nImported from Previous Software."]);
        $track('expenses', $old);
        // The clinic has since recorded its own rent under the imported category, so that category stays.
        $own = $expense(['expense_category_id' => $shared, 'expense_date' => today()->toDateString(), 'description' => 'October rent', 'amount' => 1500]);
        // More than one pass of 500, all from the same import.
        $bulk = collect(range(1, 600))->map(fn ($n) => ['clinic_id' => $this->clinic->id, 'branch_id' => $this->branchId, 'expense_category_id' => $imported, 'expense_date' => '2024-01-01',
            'description' => "Water $n", 'amount' => 1, 'recorded_by' => $owner->id, 'created_at' => $now, 'updated_at' => $now]);
        foreach ($bulk->chunk(200) as $chunk) DB::table('expenses')->insert($chunk->values()->all());
        $bulkIds = DB::table('expenses')->where('description', 'like', 'Water %')->pluck('id');
        foreach ($bulkIds->chunk(200) as $chunk) DB::table('external_import_rows')->insert($chunk->map(fn ($id) => ['external_import_batch_id' => $batch->id, 'table_name' => 'expenses', 'record_id' => $id])->values()->all());

        (require database_path('migrations/2026_10_23_000002_create_legacy_expenses_table.php'))->moveImportedExpenses();

        $this->assertSame([$own], DB::table('expenses')->pluck('id')->map(fn ($id) => (int) $id)->all(), 'only the clinic\'s own expense is left in the books');
        $this->assertSame(601, DB::table('legacy_expenses')->count());
        $this->assertEquals(700, DB::table('legacy_expenses')->sum('amount'));
        $moved = DB::table('legacy_expenses')->where('reference', 'CLD-7')->sole();
        $this->assertSame(['2024-03-08', 'Drinking water', 'Dispenser bottle', '100.00', 'CLD-7', 'Big one', $owner->name, 'cloudify', $batch->id],
            [$moved->expense_date, $moved->category, $moved->description, $moved->amount, $moved->reference, $moved->notes, $moved->recorded_by, $moved->source, (int) $moved->external_import_batch_id]);
        $this->assertDatabaseMissing('expense_categories', ['id' => $imported]);
        $this->assertDatabaseHas('expense_categories', ['id' => $shared]);
        $this->assertSame(['expense_categories' => 1, 'legacy_expenses' => 601],
            DB::table('external_import_rows')->selectRaw('table_name, count(*) n')->groupBy('table_name')->orderBy('table_name')->pluck('n', 'table_name')->map(fn ($n) => (int) $n)->all(), 'undo now points at the moved rows');
        $this->assertDatabaseHas('external_import_rows', ['table_name' => 'legacy_expenses', 'record_id' => $moved->id]);
        $this->assertDatabaseHas('external_import_rows', ['table_name' => 'expense_categories', 'record_id' => $shared]);
    }
}
