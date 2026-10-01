<?php

namespace Tests\Feature;

use App\Mail\OwnerNoticeMail;
use App\Mail\OwnerSummaryMail;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\DiscountApprovalRequest;
use App\Models\OwnerEmail;
use App\Models\PasswordResetRequest;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\OwnerAlerts;
use App\Services\OwnerSummaryService;
use App\Support\Feature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** Emails go to the clinic owner only (the billing email), through the platform sender. */
class OwnerEmailsTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private const OWNER = 'owner@brighteyes.test';

    /** A hosted clinic with two branches, a Super Admin who is not the owner, and the given plan features. */
    private function clinic(array $features = ['*'], string $timezone = 'Africa/Accra'): array
    {
        config()->set('tenancy.enabled', true);
        $clinic = Clinic::create(['name' => 'Bright Eyes', 'slug' => 'bright-eyes', 'status' => 'active', 'deployment_mode' => 'hosted',
            'billing_email' => self::OWNER, 'default_timezone' => $timezone, 'default_currency' => 'GHS']);
        $plan = SubscriptionPlan::create(['name' => 'Plan', 'code' => 'plan-' . uniqid(), 'features' => $features, 'base_price' => 0, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => 'monthly',
            'feature_snapshot' => $features, 'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        $main = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Osu', 'is_default' => true, 'is_active' => true]);
        $second = $clinic->branches()->create(['code' => 'KAS', 'name' => 'Kasoa', 'is_default' => false, 'is_active' => true]);
        $admin = User::factory()->create(['email' => 'admin@brighteyes.test']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        $clinic->users()->attach($admin->id, ['status' => 'active', 'is_default' => true, 'clinic_role' => 'Super Admin']);
        foreach ([$main, $second] as $branch) $branch->users()->attach($admin->id, ['status' => 'active', 'is_default' => $branch->is($main)]);

        return [$clinic, $main, $second, $admin];
    }

    private function sale(Clinic $clinic, Branch $branch, float $total, string $at, string $method = 'cash'): void
    {
        $id = DB::table('sales')->insertGetId(['clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'transaction_id' => 'TX-' . uniqid(),
            'total_amount' => $total, 'amount_paid' => $total, 'payment_status' => 'paid', 'business_line' => 'clinic', 'is_refunded' => false,
            'created_at' => $at, 'updated_at' => $at]);
        DB::table('payment_transactions')->insert(['clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'sale_id' => $id, 'amount' => $total,
            'payment_method' => $method, 'collected_by' => $clinic->users()->value('users.id'), 'created_at' => $at, 'updated_at' => $at]);
    }

    public function test_daily_summary_goes_to_the_owner_at_ten_clinic_time_once_and_counts_every_branch(): void
    {
        Mail::fake();
        [$clinic, $main, $second] = $this->clinic();
        $this->sale($clinic, $main, 300, '2026-09-28 10:00:00');
        $this->sale($clinic, $second, 200, '2026-09-28 15:00:00', 'momo');
        $this->sale($clinic, $main, 250, '2026-09-27 10:00:00');
        $summaries = app(OwnerSummaryService::class);

        $this->assertSame([], $summaries->sendDue($clinic, Carbon::parse('2026-09-29 09:59:00', 'Africa/Accra')));
        $this->assertSame(['daily' => 'sent'], $summaries->sendDue($clinic, Carbon::parse('2026-09-29 10:05:00', 'Africa/Accra')));
        $this->assertSame(['daily' => 'sent'], $summaries->sendDue($clinic, Carbon::parse('2026-09-29 11:05:00', 'Africa/Accra')));

        Mail::assertSent(OwnerSummaryMail::class, 1);
        Mail::assertSent(OwnerSummaryMail::class, function (OwnerSummaryMail $mail) {
            $total = $mail->summary['total'];
            return $mail->hasTo(self::OWNER) && ! $mail->hasTo('admin@brighteyes.test')
                && $total['sales'] == 500 && $total['received'] == 500
                && $total['byMethod'] == ['Cash' => 300.0, 'Mobile Money' => 200.0]
                && $mail->summary['change']['sales'] == 100.0
                && array_keys($mail->summary['branches']) === ['Osu', 'Kasoa'];
        });
        $this->assertStringContainsString('GHS 500.00', (new OwnerSummaryMail(Mail::sent(OwnerSummaryMail::class)->first()->summary))->render());
    }

    public function test_a_day_means_the_clinics_day_not_the_servers(): void
    {
        Mail::fake();
        [$clinic, $main] = $this->clinic(['*'], 'Asia/Tokyo');   // UTC+9
        $this->sale($clinic, $main, 100, '2026-09-28 14:00:00');     // 23:00 on the 28th in Tokyo
        $this->sale($clinic, $main, 250, '2026-09-28 16:00:00');     // 01:00 on the 29th in Tokyo
        $this->sale($clinic, $main, 40, '2026-09-29 14:30:00');      // 23:30 on the 29th in Tokyo

        app(OwnerSummaryService::class)->sendDue($clinic, Carbon::parse('2026-09-30 10:00:00', 'Asia/Tokyo'));

        Mail::assertSent(OwnerSummaryMail::class, fn (OwnerSummaryMail $mail) => $mail->summary['label'] === 'Tue 29 Sep 2026'
            && $mail->summary['total']['sales'] == 290 && $mail->summary['total']['received'] == 290);
    }

    public function test_weekly_summary_adds_old_debts_discounts_and_insurance_claims(): void
    {
        Mail::fake();
        [$clinic, $main, , $admin] = $this->clinic();
        // Owed since June, and a discounted sale last week.
        DB::table('sales')->insert(['clinic_id' => $clinic->id, 'branch_id' => $main->id, 'transaction_id' => 'OLD-1', 'total_amount' => 800, 'amount_paid' => 300,
            'payment_status' => 'partial', 'business_line' => 'clinic', 'is_refunded' => false, 'created_at' => '2026-06-10 10:00:00', 'updated_at' => '2026-06-10 10:00:00']);
        DB::table('sales')->insert(['clinic_id' => $clinic->id, 'branch_id' => $main->id, 'transaction_id' => 'DISC-1', 'total_amount' => 180, 'amount_paid' => 180,
            'discount_amount' => 20, 'payment_status' => 'paid', 'business_line' => 'clinic', 'is_refunded' => false, 'created_at' => '2026-09-23 10:00:00', 'updated_at' => '2026-09-23 10:00:00']);
        $patient = DB::table('patients')->insertGetId(['clinic_id' => $clinic->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $admin->id,
            'pxnumber' => 'PX-1', 'name' => 'Esi Owusu', 'gender' => 'Female', 'contact' => '0240000000', 'created_at' => now(), 'updated_at' => now()]);
        $insurer = DB::table('insurers')->insertGetId(['clinic_id' => $clinic->id, 'name' => 'NHIS', 'created_at' => now(), 'updated_at' => now()]);
        $claim = ['clinic_id' => $clinic->id, 'branch_id' => $main->id, 'patient_id' => $patient, 'insurer_id' => $insurer, 'created_by' => $admin->id];
        DB::table('insurance_claims')->insert($claim + ['claim_amount' => 120, 'status' => 'submitted', 'submission_date' => '2026-08-01', 'created_at' => '2026-08-01', 'updated_at' => '2026-08-01']);
        DB::table('insurance_claims')->insert($claim + ['claim_amount' => 75, 'status' => 'rejected', 'submission_date' => '2026-09-10', 'created_at' => '2026-09-10', 'updated_at' => '2026-09-24 09:00:00']);

        $this->travelTo(Carbon::parse('2026-09-28 10:00:00', 'Africa/Accra'));
        app(OwnerSummaryService::class)->sendDue($clinic);

        Mail::assertSent(OwnerSummaryMail::class, function (OwnerSummaryMail $mail) {
            if ($mail->summary['period'] !== 'weekly') return false;
            $t = $mail->summary['total'];
            $html = $mail->render();
            return $mail->summary['longView'] && $t['owed90'] == 500 && $t['discounts'] == 20
                && $t['claimsWaiting'] == 1 && $t['claimsWaitingAmount'] == 120 && $t['claimsRejected'] == 1
                && str_contains($html, 'Worth a look') && str_contains($html, 'Owed for more than 90 days');
        });
        // The daily one that goes the same morning keeps to the day.
        Mail::assertSent(OwnerSummaryMail::class, fn (OwnerSummaryMail $mail) => $mail->summary['period'] === 'daily' && ! $mail->summary['longView']);
    }

    public function test_weekly_summary_reports_what_insurers_paid_still_owe_and_wrote_off(): void
    {
        Mail::fake();
        [$clinic, $main, , $admin] = $this->clinic();
        $patient = DB::table('patients')->insertGetId(['clinic_id' => $clinic->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $admin->id,
            'pxnumber' => 'PX-2', 'name' => 'Kofi Mensah', 'gender' => 'Male', 'contact' => '0240000001', 'created_at' => now(), 'updated_at' => now()]);
        $insurer = DB::table('insurers')->insertGetId(['clinic_id' => $clinic->id, 'name' => 'NHIS', 'created_at' => now(), 'updated_at' => now()]);
        $ids = ['clinic_id' => $clinic->id, 'branch_id' => $main->id];
        // An insured bill from June: the insurer's share is 400, of which 100 has come in.
        $sale = DB::table('sales')->insertGetId($ids + ['transaction_id' => 'INS-1', 'patient_id' => $patient, 'insurer_id' => $insurer,
            'total_amount' => 500, 'insurer_amount' => 400, 'amount_paid' => 100, 'payment_status' => 'paid', 'business_line' => 'clinic',
            'is_refunded' => false, 'created_at' => '2026-06-10 10:00:00', 'updated_at' => '2026-06-10 10:00:00']);
        DB::table('insurance_claims')->insert($ids + ['patient_id' => $patient, 'insurer_id' => $insurer, 'sale_id' => $sale, 'created_by' => $admin->id,
            'claim_amount' => 400, 'amount_received' => 100, 'status' => 'submitted', 'submission_date' => '2026-06-12', 'created_at' => '2026-06-12', 'updated_at' => '2026-09-24']);
        DB::table('insurer_payments')->insert($ids + ['insurer_id' => $insurer, 'receipt_number' => 'IP-TEST-1', 'amount' => 100, 'payment_method' => 'bank_transfer',
            'paid_on' => '2026-09-24', 'received_by' => $admin->id, 'created_at' => '2026-09-24 09:00:00', 'updated_at' => '2026-09-24 09:00:00']);
        DB::table('sale_adjustments')->insert($ids + ['sale_id' => $sale, 'type' => 'insurance_write_off', 'amount' => 30, 'method' => 'fixed',
            'created_by' => $admin->id, 'reason' => 'Short payment', 'created_at' => '2026-09-23 10:00:00', 'updated_at' => '2026-09-23 10:00:00']);

        $this->travelTo(Carbon::parse('2026-09-28 10:00:00', 'Africa/Accra'));
        app(OwnerSummaryService::class)->sendDue($clinic);

        Mail::assertSent(OwnerSummaryMail::class, function (OwnerSummaryMail $mail) {
            if ($mail->summary['period'] !== 'weekly') return false;
            $t = $mail->summary['total'];
            $html = $mail->render();
            return $t['insurerOwed'] == 300 && $t['insurerOwed90'] == 300 && $t['insurerReceived'] == 100
                && $t['received'] == 100 && ($t['byMethod']['Insurer payments'] ?? 0) == 100 && $t['insurerWrittenOff'] == 30
                && $t['owed'] == 0
                && str_contains($html, 'Insurers still owe') && str_contains($html, 'Owed by insurers for more than 90 days')
                && str_contains($html, 'Insurer shortfalls written off');
        });
    }

    public function test_weekly_goes_on_monday_and_monthly_on_the_first_only_when_the_plan_includes_them(): void
    {
        Mail::fake();
        [$clinic] = $this->clinic(['clinical', Feature::DAILY_SUMMARY, Feature::MONTHLY_SUMMARY]);
        $summaries = app(OwnerSummaryService::class);

        // Thursday 1 October: daily and monthly are due; weekly isn't a Monday anyway.
        $this->assertSame(['daily' => 'sent', 'monthly' => 'sent'], $summaries->sendDue($clinic, Carbon::parse('2026-10-01 10:00:00', 'Africa/Accra')));
        // Monday 5 October: weekly is due but not on this plan.
        $this->assertSame(['daily' => 'sent'], $summaries->sendDue($clinic, Carbon::parse('2026-10-05 10:00:00', 'Africa/Accra')));

        $monthly = OwnerEmail::where('kind', 'summary_monthly')->sole();
        $this->assertStringContainsString('September 2026', $monthly->subject);
        $this->assertSame(0, OwnerEmail::where('kind', 'summary_weekly')->count());
    }

    public function test_summary_is_not_sent_without_an_owner_email_and_failed_sends_are_retried_three_times(): void
    {
        [$clinic] = $this->clinic();
        $clinic->update(['billing_email' => null]);
        $summaries = app(OwnerSummaryService::class);
        $at = Carbon::parse('2026-09-29 10:00:00', 'Africa/Accra');
        $this->assertSame(['daily' => 'skipped'], $summaries->sendDue($clinic, $at));

        OwnerEmail::query()->delete();
        $clinic->update(['billing_email' => self::OWNER]);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Resend is down'));
        foreach (range(1, 4) as $run) $summaries->sendDue($clinic, $at);

        $row = OwnerEmail::sole();
        $this->assertSame([OwnerEmail::FAILED, 3, 'Resend is down'], [$row->status, $row->attempts, $row->error]);
    }

    public function test_approval_requests_email_the_owner_with_a_link_to_the_approval_screen(): void
    {
        Mail::fake();
        config()->set('tenancy.enabled', true);
        [$clinic, $main, , $admin] = $this->clinic();
        app(TenantContext::class)->set($admin, $clinic, $main, [$main->id]);
        $request = DiscountApprovalRequest::create(['cashier_id' => $admin->id, 'discount_type' => 'percentage', 'discount_value' => 10,
            'discount_amount' => 20, 'gross_amount' => 200, 'final_amount' => 180, 'cart_snapshot' => [], 'status' => DiscountApprovalRequest::STATUS_PENDING]);

        app(OwnerAlerts::class)->discountRequested($request);
        app(OwnerAlerts::class)->discountRequested($request);

        Mail::assertSent(OwnerNoticeMail::class, 1);
        Mail::assertSent(OwnerNoticeMail::class, fn (OwnerNoticeMail $mail) => $mail->hasTo(self::OWNER)
            && $mail->details['Discount'] === '10% (GHS 20.00)' && $mail->details['Branch'] === 'Osu'
            && str_contains($mail->buttonUrl, 'type=discount'));
    }

    public function test_password_reset_request_emails_the_owner_of_each_clinic_the_person_works_at(): void
    {
        Mail::fake();
        [$clinic, $main] = $this->clinic();
        $staff = User::factory()->create(['email' => 'nurse@brighteyes.test', 'name' => 'Ama Nurse']);
        $clinic->users()->attach($staff->id, ['status' => 'active']);

        $this->post(route('password.email'), ['email' => 'nurse@brighteyes.test'])->assertSessionHas('request_status', 'submitted');
        $this->post(route('password.email'), ['email' => 'nobody@brighteyes.test']);

        $this->assertSame(1, PasswordResetRequest::count());
        Mail::assertSent(OwnerNoticeMail::class, 1);
        Mail::assertSent(OwnerNoticeMail::class, fn (OwnerNoticeMail $mail) => $mail->hasTo(self::OWNER)
            && $mail->details['Staff member'] === 'Ama Nurse' && str_contains($mail->buttonUrl, 'type=password_reset'));
    }

    public function test_optical_settings_page_shows_owner_emails_to_super_admins_only(): void
    {
        [$clinic, $main, , $admin] = $this->clinic(['optical', Feature::DAILY_SUMMARY]);
        $this->actingAs($admin);
        app(TenantContext::class)->set($admin, $clinic, $main, [$main->id]);
        Livewire::test(\App\Livewire\Optical\OpticalSettingsComponent::class)->assertSee('Owner emails')->assertSee(self::OWNER);
        Livewire::test(\App\Livewire\Admin\OwnerEmailsComponent::class, ['optical' => true])
            ->assertSee('Scheduled emails on your plan')->assertSee('Not on your plan');

        $manager = User::factory()->create();
        $manager->assignRole(Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']));
        $clinic->users()->attach($manager->id, ['status' => 'active']);
        $main->users()->attach($manager->id, ['status' => 'active', 'is_default' => true]);
        $this->actingAs($manager);
        app(TenantContext::class)->set($manager, $clinic, $main, [$main->id]);
        Livewire::test(\App\Livewire\Optical\OpticalSettingsComponent::class)->assertDontSee('Owner emails');
    }

    public function test_owner_emails_tab_shows_the_owner_email_plan_summaries_and_sends_a_test(): void
    {
        Mail::fake();
        config()->set('tenancy.enabled', true);
        [$clinic, $main, , $admin] = $this->clinic(['clinical', Feature::DAILY_SUMMARY]);
        $this->actingAs($admin);
        app(TenantContext::class)->set($admin, $clinic, $main, [$main->id]);

        Livewire::test(\App\Livewire\Admin\OwnerEmailsComponent::class)
            ->assertSee(self::OWNER)->assertSee('Not on your plan')
            ->call('sendTest')
            ->assertSee('Daily summary - Bright Eyes');

        Mail::assertSent(OwnerSummaryMail::class, fn (OwnerSummaryMail $mail) => $mail->hasTo(self::OWNER));
    }
}
