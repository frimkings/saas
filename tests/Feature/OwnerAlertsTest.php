<?php

namespace Tests\Feature;

use App\Mail\OwnerAlertsMail;
use App\Mail\OwnerNoticeMail;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\OwnerAlertItem;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\OwnerAlertDigestService;
use App\Services\OwnerAlerts;
use App\Support\Feature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The morning alerts email and the immediate alerts, all to the clinic owner. */
class OwnerAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'owner@brighteyes.test';

    private function clinic(array $features = ['*']): array
    {
        config()->set('tenancy.enabled', true);
        $clinic = Clinic::create(['name' => 'Bright Eyes', 'slug' => 'bright-eyes', 'status' => 'active', 'deployment_mode' => 'hosted',
            'billing_email' => self::OWNER, 'default_timezone' => 'Africa/Accra', 'default_currency' => 'GHS']);
        $plan = SubscriptionPlan::create(['name' => 'Plan', 'code' => 'plan-' . uniqid(), 'features' => $features, 'base_price' => 0, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => 'monthly',
            'feature_snapshot' => $features, 'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addYear()]);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Osu', 'is_default' => true, 'is_active' => true]);
        $admin = User::factory()->create(['email' => 'admin@brighteyes.test', 'name' => 'Kofi Admin']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $clinic->users()->attach($admin->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($admin->id, ['status' => 'active', 'is_default' => true]);

        return [$clinic, $branch, $admin];
    }

    /** A clinic product with stock at the branch; returns [stock row id, lot id]. */
    private function product(Clinic $clinic, Branch $branch, string $name, int $quantity, int $reorderLevel, ?string $expiry = null): array
    {
        $product = DB::table('products')->insertGetId(['clinic_id' => $clinic->id, 'user_id' => $clinic->users()->value('users.id'), 'name' => $name, 'quantity' => $quantity, 'cost_price' => 5, 'selling_price' => 10,
            'created_at' => now(), 'updated_at' => now()]);
        $stock = DB::table('branch_inventory_items')->insertGetId(['clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'product_id' => $product,
            'quantity' => $quantity, 'reorder_level' => $reorderLevel, 'is_active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $lot = $expiry ? DB::table('inventory_lots')->insertGetId(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'clinic_id' => $clinic->id, 'branch_id' => $branch->id,
            'product_id' => $product, 'batch_number' => 'B-' . $product, 'expiry_date' => $expiry, 'unit_cost' => 5, 'opening_quantity' => $quantity,
            'quantity' => $quantity, 'created_at' => now(), 'updated_at' => now()]) : null;

        return [$stock, $lot];
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse($time, 'Africa/Accra');
    }

    public function test_low_stock_is_emailed_once_again_when_it_runs_out_and_cleared_when_restocked(): void
    {
        Mail::fake();
        [$clinic, $branch] = $this->clinic();
        [$stock] = $this->product($clinic, $branch, 'Timolol drops', 3, 10);
        $this->product($clinic, $branch, 'Plenty drops', 50, 10);
        $alerts = app(OwnerAlertDigestService::class);

        $this->assertNull($alerts->sendDue($clinic, $this->at('2026-09-29 09:30')));
        $this->assertSame('sent', $alerts->sendDue($clinic, $this->at('2026-09-29 10:00')));
        $this->assertSame('sent', $alerts->sendDue($clinic, $this->at('2026-09-29 12:00')));
        // Next day, nothing new: no email.
        $this->assertNull($alerts->sendDue($clinic, $this->at('2026-09-30 10:00')));
        Mail::assertSent(OwnerAlertsMail::class, 1);
        Mail::assertSent(OwnerAlertsMail::class, fn (OwnerAlertsMail $mail) => $mail->hasTo(self::OWNER)
            && $mail->alerts['sections']['low_stock']['rows'][0]['title'] === 'Timolol drops'
            && $mail->alerts['sections']['low_stock']['rows'][0]['detail'] === '3 left (reorder at 10)'
            && count($mail->alerts['sections']['low_stock']['rows']) === 1);

        // It runs out: reported again, as out of stock.
        DB::table('branch_inventory_items')->where('id', $stock)->update(['quantity' => 0]);
        $this->assertSame('sent', $alerts->sendDue($clinic, $this->at('2026-10-01 10:00')));
        Mail::assertSent(OwnerAlertsMail::class, fn (OwnerAlertsMail $mail) => ($mail->alerts['sections']['low_stock']['rows'][0]['detail'] ?? null) === 'Out of stock'
            && $mail->alerts['sections']['low_stock']['rows'][0]['urgent']);

        // Restocked: the alert is closed, so running low later alerts again.
        DB::table('branch_inventory_items')->where('id', $stock)->update(['quantity' => 40]);
        $this->assertNull($alerts->sendDue($clinic, $this->at('2026-10-02 10:00')));
        $this->assertSame(0, OwnerAlertItem::whereNull('resolved_at')->count());
    }

    public function test_expiring_stock_is_flagged_at_ninety_days_thirty_days_and_expiry(): void
    {
        Mail::fake();
        [$clinic, $branch] = $this->clinic();
        $this->product($clinic, $branch, 'Cyclopentolate', 20, 0, '2026-11-15');
        $alerts = app(OwnerAlertDigestService::class);

        $alerts->sendDue($clinic, $this->at('2026-09-29 10:00'));   // 47 days: within 90
        $alerts->sendDue($clinic, $this->at('2026-10-05 10:00'));   // 41 days: same stage, nothing new
        $alerts->sendDue($clinic, $this->at('2026-10-20 10:00'));   // 26 days: within 30
        $alerts->sendDue($clinic, $this->at('2026-11-16 10:00'));   // expired, still on the shelf

        $details = Mail::sent(OwnerAlertsMail::class)->map(fn ($mail) => $mail->alerts['sections']['expiry']['rows'][0]['detail'])->all();
        $this->assertCount(3, $details);
        $this->assertStringContainsString('in 47 days', $details[0]);
        $this->assertStringContainsString('in 26 days', $details[1]);
        $this->assertStringContainsString('expired 15 Nov 2026', $details[2]);
    }

    public function test_morning_alerts_need_the_plan_tick(): void
    {
        Mail::fake();
        [$clinic, $branch] = $this->clinic(['clinical', Feature::INVENTORY, Feature::DAILY_SUMMARY]);
        $this->product($clinic, $branch, 'Timolol drops', 3, 10);

        $this->assertNull(app(OwnerAlertDigestService::class)->sendDue($clinic, $this->at('2026-09-29 10:00')));
        Mail::assertNotSent(OwnerAlertsMail::class);
    }

    public function test_supplier_bills_and_regular_expenses_that_are_due_are_flagged(): void
    {
        Mail::fake();
        [$clinic, $branch, $admin] = $this->clinic();
        $supplier = DB::table('suppliers')->insertGetId(['clinic_id' => $clinic->id, 'name' => 'Lens World', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchase_orders')->insert(['clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'po_number' => 'PO-7', 'supplier_id' => $supplier, 'status' => 'received', 'order_date' => '2026-09-01',
            'invoice_status' => 'invoiced', 'invoice_amount' => 900, 'paid_amount' => 400, 'invoice_due_date' => '2026-09-25', 'total_amount' => 900,
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('recurring_expenses')->insert(['clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'business_line' => 'clinic', 'description' => 'Shop rent',
            'payee' => 'Landlord', 'amount' => 1500, 'payment_method' => 'bank_transfer', 'frequency' => 'monthly', 'next_due_date' => '2026-09-28', 'is_active' => true,
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);

        app(OwnerAlertDigestService::class)->sendDue($clinic, $this->at('2026-09-29 10:00'));

        Mail::assertSent(OwnerAlertsMail::class, fn (OwnerAlertsMail $mail) => $mail->alerts['sections']['bills']['rows'][0]['title'] === 'Lens World · PO-7'
            && $mail->alerts['sections']['bills']['rows'][0]['detail'] === 'GHS 500.00 overdue since 25 Sep 2026'
            && $mail->alerts['sections']['recurring']['rows'][0]['detail'] === 'GHS 1,500.00 to Landlord · due 28 Sep 2026');
    }

    private function lensOrder(Clinic $clinic, Branch $branch, User $user, string $orderId, array $values): void
    {
        DB::table('lens_orders')->insert($values + ['clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'order_id' => $orderId,
            'frame_price' => 300, 'lens_price' => 200, 'pickUpDate' => '2026-09-30', 'created_at' => '2026-08-01 10:00:00', 'updated_at' => now()]);
    }

    public function test_late_lab_jobs_and_uncollected_glasses_are_flagged_with_what_is_still_owed(): void
    {
        Mail::fake();
        [$clinic, $branch, $admin] = $this->clinic();
        $this->lensOrder($clinic, $branch, $admin, 'OPT-1', ['status' => 'In Lab', 'customer_name' => 'Kwame Asante', 'expected_back_at' => '2026-09-21']);
        $this->lensOrder($clinic, $branch, $admin, 'OPT-2', ['status' => 'In Lab', 'customer_name' => 'On Time', 'expected_back_at' => '2026-10-02']);
        $this->lensOrder($clinic, $branch, $admin, 'OPT-3', ['status' => 'Ready', 'customer_name' => 'Abena Owusu', 'ready_at' => '2026-07-25 10:00:00', 'paid_amount' => 350]);
        $this->lensOrder($clinic, $branch, $admin, 'OPT-4', ['status' => 'Ready', 'customer_name' => 'Recently Ready', 'ready_at' => '2026-09-20 10:00:00', 'paid_amount' => 500]);

        app(OwnerAlertDigestService::class)->sendDue($clinic, $this->at('2026-09-29 10:00'));

        Mail::assertSent(OwnerAlertsMail::class, function (OwnerAlertsMail $mail) {
            $lab = $mail->alerts['sections']['lab']['rows'];
            $ready = $mail->alerts['sections']['uncollected']['rows'];
            return count($lab) === 1 && $lab[0]['title'] === 'OPT-1 · Kwame Asante' && $lab[0]['detail'] === 'Expected back 21 Sep (8 days late)'
                && count($ready) === 1 && $ready[0]['title'] === 'OPT-3 · Abena Owusu'
                && $ready[0]['detail'] === 'Ready for 66 days · GHS 150.00 still to pay' && $ready[0]['urgent'] === false;
        });
    }

    public function test_approved_stock_count_with_missing_stock_emails_the_owner_what_it_cost(): void
    {
        Mail::fake();
        [$clinic, $branch, $admin] = $this->clinic();
        $this->actingAs($admin);
        app(TenantContext::class)->set($admin, $clinic, $branch, [$branch->id]);
        $category = DB::table('optical_categories')->insertGetId(['clinic_id' => $clinic->id, 'code' => 'FRM', 'name' => 'Frames', 'created_at' => now(), 'updated_at' => now()]);
        $product = fn (string $name) => DB::table('optical_products')->insertGetId(['clinic_id' => $clinic->id, 'optical_category_id' => $category, 'sku' => $name,
            'name' => $name, 'cost_price' => 50, 'selling_price' => 120, 'created_at' => now(), 'updated_at' => now()]);
        $count = \App\Models\OpticalStockCount::findOrFail(DB::table('optical_stock_counts')->insertGetId(['clinic_id' => $clinic->id, 'branch_id' => $branch->id,
            'count_number' => 'SC-9', 'scope' => 'all', 'title' => 'Month end', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]));
        foreach ([['Ray frame', 10, 7, 50], ['Gold frame', 4, 4, 80], ['Case', 20, 22, 5]] as [$name, $expected, $counted, $cost]) {
            DB::table('optical_stock_count_lines')->insert(['clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'optical_stock_count_id' => $count->id,
                'optical_product_id' => $product($name), 'expected_quantity' => $expected, 'counted_quantity' => $counted, 'moved_before_count' => 0, 'unit_cost' => $cost,
                'created_at' => now(), 'updated_at' => now()]);
        }

        app(OwnerAlerts::class)->stockCountApproved($count->fresh());

        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo(self::OWNER) && $mail->heading === 'Stock count found missing stock'
            && $mail->details['Items short'] === '3 units · GHS 150.00 at cost' && $mail->details['Items over'] === '2 units · GHS 10.00 at cost'
            && $mail->details['Biggest shortfalls'] === 'Ray frame (-3)');
    }

    public function test_clearance_revoke_request_emails_the_owner(): void
    {
        Mail::fake();
        [$clinic, $branch, $admin] = $this->clinic();
        $this->actingAs($admin);
        app(TenantContext::class)->set($admin, $clinic, $branch, [$branch->id]);
        $log = (new \App\Models\ClearanceRevokeLog(['reason' => 'Patient left before the consultation']))->forceFill(['id' => 71]);

        app(OwnerAlerts::class)->revokeRequested($log, 'Esi Owusu');

        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo(self::OWNER) && $mail->heading === 'Clearance revoke waiting for approval'
            && $mail->details['Patient'] === 'Esi Owusu' && $mail->details['Reason'] === 'Patient left before the consultation'
            && str_contains($mail->buttonUrl, 'type=revoke'));
    }

    public function test_staff_imported_from_a_spreadsheet_send_one_email_naming_anyone_given_admin_access(): void
    {
        Mail::fake();
        [$clinic, $branch, $admin] = $this->clinic();
        Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Secretary', 'guard_name' => 'web']);
        $this->actingAs($admin);
        app(TenantContext::class)->set($admin, $clinic, $branch, [$branch->id]);

        $csv = "name,email,password,role,phone,staff_id,gender,date_of_birth,department,hire_date\n"
            . "Ama Mensah,ama@brighteyes.test,secret123,Secretary,,,,,,\n"
            . "Yaw Boateng,yaw@brighteyes.test,secret123,Manager,,,,,,\n";
        \Livewire\Livewire::test(\App\Livewire\Admin\UserRoleManagerComponent::class)
            ->set('importFile', \Illuminate\Http\UploadedFile::fake()->createWithContent('staff.csv', $csv))
            ->call('importCsv');

        $this->assertSame(2, User::whereIn('email', ['ama@brighteyes.test', 'yaw@brighteyes.test'])->count());
        Mail::assertSent(OwnerNoticeMail::class, 1);
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo(self::OWNER)
            && $mail->heading === '2 staff members imported'
            && str_contains($mail->intro, '1 of them has admin access: Yaw Boateng')
            && $mail->details['Ama Mensah'] === 'Secretary · ama@brighteyes.test');
    }

    public function test_immediate_alerts_for_sms_credits_and_staff_changes(): void
    {
        Mail::fake();
        [$clinic, $branch, $admin] = $this->clinic();
        $this->actingAs($admin);
        app(TenantContext::class)->set($admin, $clinic, $branch, [$branch->id]);
        $alerts = app(OwnerAlerts::class);
        $nurse = User::factory()->create(['name' => 'Ama Nurse']);

        $alerts->smsCreditsLow($clinic->id, 40);
        $alerts->smsCreditsLow($clinic->id, 35);      // once a day
        $alerts->staffAdded($nurse, ['Secretary']);
        $this->assertTrue(OwnerAlerts::grantsAdmin(['Secretary'], ['Secretary', 'Manager']));
        $this->assertFalse(OwnerAlerts::grantsAdmin(['Manager'], ['Manager', 'Cashier']));
        $alerts->staffPromoted($nurse, ['Secretary', 'Manager']);
        $alerts->staffRemoved($nurse);

        $headings = Mail::sent(OwnerNoticeMail::class)->map(fn ($mail) => $mail->heading)->all();
        $this->assertSame(['SMS credits are running low', 'New staff member added', 'Staff member given admin access', 'Staff member removed'], $headings);
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->heading === 'Staff member given admin access'
            && str_contains($mail->intro, 'Kofi Admin gave Ama Nurse Manager access') && $mail->hasTo(self::OWNER));
    }
}
