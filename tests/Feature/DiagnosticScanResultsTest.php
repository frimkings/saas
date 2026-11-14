<?php

namespace Tests\Feature;

use App\Livewire\Scans\DiagnosticScansComponent;
use App\Livewire\Scans\ScanReportsComponent;
use App\Livewire\Scans\ScanResultsComponent;
use App\Mail\OwnerNoticeMail;
use App\Models\{Clinic, ClinicSubscription, DiagnosticScan, Patient, PatientDocument, ScanOrder, ScanPartnerClinic, SubscriptionPlan, User};
use App\Services\Reminders\AttentionItems;
use App\Services\Scans\ScanOrderService;
use App\Services\Scans\ScanResultsService;
use App\Support\Feature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Diagnostic scans, phase 5: result files, sending them to the referrer with consent, reports and Needs Attention. */
class DiagnosticScanResultsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Clinic $clinic;
    private $branch;
    private DiagnosticScan $oct;
    private ScanPartnerClinic $partner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        Storage::fake('local');
        config(['tenancy.enabled' => true, 'filesystems.patient_documents_disk' => 'local']);
        $this->user = User::factory()->create(['name' => 'Desk Dora']);
        $this->clinic = Clinic::create(['name' => 'Scan Centre', 'slug' => 'scan-centre', 'deployment_mode' => 'hosted']);
        $this->branch = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $this->clinic->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $this->branch->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $features = ['*', Feature::DIAGNOSTIC_SCANS];
        $plan = SubscriptionPlan::create(['name' => 'Scan plan', 'code' => 'scan-plan', 'included_branches' => 1, 'included_users' => 5,
            'storage_limit_mb' => 100, 'features' => $features, 'base_price' => 10, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $this->clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addYear(), 'feature_snapshot' => $features]);
        $this->actAs('Manager');

        $this->oct = DiagnosticScan::create(['name' => 'OCT Macula', 'price' => 150, 'per_eye' => true, 'commission_type' => 'percent', 'commission_value' => 10]);
        $this->partner = ScanPartnerClinic::create(['code' => 'RC-001', 'name' => 'Hilltop Eye Clinic', 'phone' => '0241234567', 'email' => 'results@hilltop.test', 'notify_via' => 'whatsapp']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actAs(string $roleName): void
    {
        app(TenantContext::class)->set($this->user, $this->clinic, $this->branch, [$this->branch->id]);
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $this->user->syncRoles([$role]);
        DB::table('branch_user_role')->where('user_id', $this->user->id)->delete();
        DB::table('branch_user_role')->insert(['branch_id' => $this->branch->id, 'user_id' => $this->user->id, 'role_id' => $role->id]);
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($this->user, $this->branch);
        $this->actingAs($this->user);
    }

    private function order(bool $consent = true, bool $billed = true, string $source = 'partner'): ScanOrder
    {
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $this->user->id, 'name' => 'Ama Scan', 'contact' => '0201112223', 'gender' => 'Female']);
        $order = app(ScanOrderService::class)->create(['patient_id' => $patient->id, 'source' => $source, 'share_consent' => $consent]
            + ($source === 'partner' ? ['scan_partner_clinic_id' => $this->partner->id] : ['ordered_by' => $this->user->id])
            + ['items' => [['diagnostic_scan_id' => $this->oct->id, 'eye' => 'OD']]]);
        if ($billed) app(ScanOrderService::class)->checkout($order, [['method' => 'cash', 'amount' => 150]]);

        return $order->fresh();
    }

    private function reportedWithFile(bool $consent = true): ScanOrder
    {
        $order = $this->order($consent);
        app(ScanResultsService::class)->attach($order, UploadedFile::fake()->create('oct-od.pdf', 120, 'application/pdf'), 'oct');
        app(ScanOrderService::class)->markScanned($order->fresh());
        app(ScanOrderService::class)->markReported($order->fresh());

        return $order->fresh();
    }

    public function test_results_are_added_to_a_billed_order_and_kept_in_the_patients_file(): void
    {
        $unbilled = $this->order(billed: false);
        Livewire::test(ScanResultsComponent::class, ['orderId' => $unbilled->id])->assertSee('Results can be added once the order is billed')->assertDontSee('Add results');

        $order = $this->order();
        Livewire::test(ScanResultsComponent::class, ['orderId' => $order->id])
            ->call('saveResults')->assertHasErrors('resultFiles')
            ->set('resultFiles', [UploadedFile::fake()->create('notes.docx', 10, 'application/msword')])->call('saveResults')->assertHasErrors('resultFiles.0')
            ->set('resultFiles', [UploadedFile::fake()->create('oct-od.pdf', 120, 'application/pdf'), UploadedFile::fake()->image('fundus.jpg')])
            ->set('resultType', 'oct')->set('resultTitle', 'OCT right eye')
            ->call('saveResults')->assertHasNoErrors()->assertDispatched('scan-order-changed')
            ->assertSee('OCT right eye 1')->assertSee('OCT right eye 2');

        $documents = PatientDocument::where('scan_order_id', $order->id)->get();
        $this->assertCount(2, $documents);
        $this->assertTrue($documents->every(fn ($d) => $d->patient_id === $order->patient_id && $d->document_type === 'oct'));
        Storage::disk('local')->assertExists($documents->first()->file_path);
    }

    public function test_results_go_to_the_referrer_only_with_consent_once_reported(): void
    {
        Mail::fake();
        $service = app(ScanResultsService::class);

        // Not agreed: refused, until the patient agrees.
        $order = $this->reportedWithFile(consent: false);
        Livewire::test(ScanResultsComponent::class, ['orderId' => $order->id])->assertSee('has not agreed')
            ->call('send', 'link')->assertHasErrors('send')
            ->call('recordConsent')->assertHasNoErrors()->assertSee('Copy link')->assertSee('WhatsApp');
        $this->assertTrue($order->fresh()->share_consent);

        Livewire::test(ScanResultsComponent::class, ['orderId' => $order->id])->call('send', 'email')->assertHasNoErrors()->assertSee('Sent');
        Mail::assertSent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo('results@hilltop.test') && $mail->buttonUrl !== '' && str_contains($mail->buttonUrl, 'signature='));
        $order->refresh();
        $this->assertSame(['sent', 'email', $this->user->id], [$order->status, $order->results_sent_via, (int) $order->results_sent_by]);

        // Not before it is reported, and not our doctors' orders.
        $early = $this->order();
        $this->expectException(ValidationException::class);
        $service->send($early, 'link');
    }

    public function test_the_signed_link_shows_only_that_orders_results_and_expires(): void
    {
        $order = $this->reportedWithFile();
        $document = PatientDocument::where('scan_order_id', $order->id)->sole();
        $link = app(ScanResultsService::class)->link($order);
        auth()->logout();

        $this->get($link)->assertOk()->assertSee('Scan results for Ama Scan')->assertSee('OCT Macula (OD)')->assertSee($document->title)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get(route('scan-results.show', ['order' => $order->id]))->assertForbidden();
        $this->get($link.'x')->assertForbidden();

        // The file opens from the page's own signed link.
        $page = $this->get($link)->getContent();
        preg_match('/href="([^"]+\/files\/'.$document->id.'[^"]*)"/', $page, $m);
        $this->get(html_entity_decode($m[1]))->assertOk();

        Carbon::setTestNow(now()->addDays(ScanResultsService::LINK_DAYS + 1));
        $this->get($link)->assertForbidden();
    }

    public function test_the_link_stops_working_without_consent(): void
    {
        $order = $this->reportedWithFile();
        $link = app(ScanResultsService::class)->link($order);
        ScanOrder::whereKey($order->id)->update(['share_consent' => false]);
        auth()->logout();
        $this->get($link)->assertNotFound();
    }

    public function test_reports_count_referrals_per_partner_and_download(): void
    {
        $this->order();
        $this->order();
        $this->order(source: 'internal');
        $cancelled = $this->order(billed: false);
        app(ScanOrderService::class)->cancel($cancelled, 'Patient left');

        $component = Livewire::test(ScanReportsComponent::class)->assertSet('from', '2026-11-01')->assertSee('Hilltop Eye Clinic');
        $report = app(\App\Services\Scans\ScanReports::class)->for(now()->startOfMonth(), now());
        $this->assertSame([3, 3, 450.0, 450.0, 45.0], [$report['totals']['orders'], $report['totals']['scans'], $report['totals']['billed'], $report['totals']['paid'], $report['totals']['commission']]);
        $partner = $report['partners']->sole();
        $this->assertSame([2, 300.0, 30.0], [$partner['orders'], $partner['billed'], $partner['commission']]);
        $this->assertSame(1, $report['sources']['internal']['orders']);

        $component->call('preset', 'last_month')->assertSet('from', '2026-10-01')->assertSee('No referrals from partner clinics in these dates');

        $csv = $this->get(route('frontdesk.scans.report', ['from' => '2026-11-01', 'to' => '2026-11-10']));
        $csv->assertOk();
        $this->assertStringContainsString('Hilltop Eye Clinic', $csv->streamedContent());

        $this->actAs('Secretary');
        $this->assertArrayNotHasKey('reports', DiagnosticScansComponent::tabs());
        $this->get(route('frontdesk.scans.report', ['from' => '2026-11-01', 'to' => '2026-11-10']))->assertRedirect(); // the role check sends them away
    }

    public function test_sales_records_show_scan_sales_alone_or_without_them(): void
    {
        $scan = $this->order();
        \App\Models\Sales::create(['user_id' => $this->user->id, 'business_line' => 'clinic', 'transaction_id' => 'TXN-DROPS-1',
            'total_amount' => 40, 'amount_paid' => 40, 'payment_status' => 'paid']);
        $scanTxn = $scan->sale->transaction_id;

        Livewire::test(\App\Livewire\Cashier\SalesRecordsComponent::class)
            ->assertSee('Diagnostic scans')->assertSee($scanTxn)->assertSee('TXN-DROPS-1')->assertSee('Scan · '.$scan->order_number)
            ->assertViewHas('totalSales', fn ($total) => (float) $total === 190.0)
            ->set('scanFilter', 'only')->assertSee($scanTxn)->assertDontSee('TXN-DROPS-1')->assertSee('scans only')
            ->assertViewHas('totalSales', fn ($total) => (float) $total === 150.0)->assertViewHas('totalReceipts', 1)
            ->set('scanFilter', 'exclude')->assertDontSee($scanTxn)->assertSee('TXN-DROPS-1')
            ->assertViewHas('totalSales', fn ($total) => (float) $total === 40.0)
            ->set('scanFilter', 'nonsense')->assertSet('scanFilter', '')
            ->set('scanFilter', 'only')->call('resetFilters')->assertSet('scanFilter', '');

        $this->assertNotNull(collect(\App\Support\Guides\TourCatalog::tour('clinic.sales', ['scans' => true])['steps'])->firstWhere('target', 'sales-scan-filter'));
        $this->assertNull(collect(\App\Support\Guides\TourCatalog::tour('clinic.sales', ['scans' => false])['steps'])->firstWhere('target', 'sales-scan-filter'));
    }

    public function test_needs_attention_lists_late_reports_and_unsent_results(): void
    {
        $late = $this->order();
        $sent = $this->reportedWithFile();
        $items = app(AttentionItems::class);
        $this->assertArrayNotHasKey('scan_unreported', $items->groups(AttentionItems::CLINIC));

        Carbon::setTestNow(now()->addDays(AttentionItems::SCAN_REPORT_DAYS)->addHour());
        $groups = $items->groups(AttentionItems::CLINIC);
        $this->assertSame([$late->id], $groups['scan_unreported']['items']->pluck('id')->all());
        $this->assertSame([$sent->id], $groups['scan_unsent']['items']->pluck('id')->all());

        $items->act('scan_unsent', $sent->id, \App\Models\AttentionAction::DONE, 'Phoned them');
        $this->assertArrayNotHasKey('scan_unsent', $items->groups(AttentionItems::CLINIC));
    }
}
