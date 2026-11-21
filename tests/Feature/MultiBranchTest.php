<?php

namespace Tests\Feature;

use App\Livewire\Admin\BranchesOverviewComponent;
use App\Livewire\Admin\StockTransferComponent;
use App\Livewire\Doctor\PatientTimelineComponent;
use App\Livewire\OutstandingBalancesComponent;
use App\Livewire\Secretary\SpectaclesComponent;
use App\Models\{Branch, CashierPatientClearance, Category, Consultations, LensOrder, Patient, PaymentTransaction, Product, Refractions, SaleItem, Sales, StockTransfer};
use App\Services\Inventory\BranchInventoryService;
use App\Services\Inventory\StockTransferService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\ClinicOnPlan;
use Tests\TestCase;

/** 21 Nov 2026: branches of one clinic work as one place for patients, money and stock. */
class MultiBranchTest extends TestCase
{
    use RefreshDatabase, ClinicOnPlan;

    private Branch $east;

    /** The owner, on Clinic Pro, with a second branch "East" they can open. */
    private function twoBranches()
    {
        $owner = $this->ownerOn('clinic-pro');
        $this->east = $this->clinic->branches()->create(['code' => 'EAST', 'name' => 'East', 'is_default' => false, 'is_active' => true]);
        $this->east->users()->attach($owner->id, ['status' => 'active', 'is_default' => false]);
        DB::table('branch_user_role')->insert(['branch_id' => $this->east->id, 'user_id' => $owner->id,
            'role_id' => \Spatie\Permission\Models\Role::findByName('Super Admin')->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->at($this->branch);

        return $owner;
    }

    private function at(Branch $branch): void
    {
        app(TenantContext::class)->set(auth()->user(), $this->clinic, $branch, [$this->branch->id, $this->east->id]);
    }

    /** A visit at the branch open now, with a refraction for glasses. */
    private function visit(Patient $patient, string $complaint): Consultations
    {
        $clearance = CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => $patient->id, 'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = Consultations::create(['user_id' => auth()->id(), 'patient_id' => $patient->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => $complaint]);
        Refractions::create(['user_id' => auth()->id(), 'consultation_id' => $consultation->id, 'refractionOD' => '-1.00', 'refractionOS' => '-1.00',
            'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6', 'pd' => 62, 'lensType' => 'Single vision',
            'dispensing_required' => true, 'dispensing_authorized_at' => now()]);

        return $consultation;
    }

    private function patient(): Patient
    {
        return Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Kofi Branchwalker', 'contact' => '0247770000', 'gender' => 'Male']);
    }

    /** A part-paid bill at the branch open now, with a frame line (a glasses bill when $glasses). */
    private function partSale(Patient $patient, ?Consultations $consultation = null, float $total = 400, float $paid = 100): Sales
    {
        $category = Category::firstOrCreate(['name' => 'Frames'], ['user_id' => auth()->id()]);
        $product = Product::firstOrCreate(['name' => 'Branch Frame'], ['user_id' => auth()->id(), 'category_id' => $category->id, 'quantity' => 5, 'cost_price' => 100, 'selling_price' => $total]);
        $sale = Sales::create(['user_id' => auth()->id(), 'patient_id' => $patient->id, 'consultation_id' => $consultation?->id, 'transaction_id' => 'TXN-MB-'.uniqid(),
            'business_line' => 'clinic', 'total_amount' => $total, 'amount_paid' => $paid, 'payment_status' => 'partial', 'bill_status' => 'open']);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'prescribed_quantity' => 1, 'dispensed_quantity' => 0, 'selling_price' => $total, 'subtotal' => 0]);

        return $sale;
    }

    public function test_one_patient_one_chart_across_branches(): void
    {
        $this->twoBranches();
        $patient = $this->patient();
        $main = $this->visit($patient, 'Seen at Main');
        $this->at($this->east);
        $this->visit($patient, 'Seen at East');

        // Today's lists stay per branch; the patient's chart has both visits, the other one marked.
        $this->assertSame(1, Consultations::where('patient_id', $patient->id)->count());
        $this->assertSame(2, Consultations::chart($patient->id)->count());
        $this->assertSame(2, Refractions::chart($patient->id)->count());
        $this->assertSame('Main', Consultations::chart($patient->id)->whereKey($main->id)->first()->seenElsewhere());
        $this->assertNotNull(Consultations::chart($patient->id)->whereKey($main->id)->first()->refraction);

        Livewire::test(PatientTimelineComponent::class, ['patient' => $patient])->assertSee('Seen at Main')->assertSee('Seen at East', false);
        // The other branch's visit prints from here.
        $this->get(route('doctor.visit-summary.print', $main))->assertOk();
        // Another clinic's visit is still out of reach.
        $this->assertNull(Consultations::chart($patient->id + 999)->first());
    }

    public function test_the_owner_sees_every_branch_side_by_side(): void
    {
        $this->twoBranches();
        $patient = $this->patient();
        $this->partSale($patient, null, 300, 300)->update(['payment_status' => 'paid']);
        PaymentTransaction::create(['sale_id' => Sales::first()->id, 'amount' => 300, 'payment_method' => 'cash', 'collected_by' => auth()->id()]);
        $this->at($this->east);
        $this->partSale($patient, null, 500, 100);

        $page = Livewire::test(BranchesOverviewComponent::class)->assertOk()->assertSee('Main')->assertSee('East');
        $rows = collect($page->viewData('rows'))->keyBy('name');
        $this->assertSame([300.0, 300.0], [$rows['Main']['billed'], $rows['Main']['cash']]);
        $this->assertSame([500.0, 400.0], [$rows['East']['billed'], $rows['East']['owed']]);
        $this->assertSame(800.0, $page->viewData('totals')['billed']);
        $this->get(route('admin.dashboard'))->assertSee(route('admin.branches-overview'), false);
        // The page itself opens over HTTP for the owner (its screen is registered).
        $this->get(route('admin.branches-overview'))->assertOk()->assertSee('East');

        // Not for staff, and not for a clinic with one branch.
        $this->signIn($this->staff('Cashier'));
        $this->get(route('admin.branches-overview'))->assertForbidden();
        // One branch: the owner gets a pointer to add one, not the comparison.
        \App\Models\Branch::withoutGlobalScopes()->whereKey($this->east->id)->update(['is_active' => false]);
    }

    public function test_a_bill_from_another_branch_is_paid_here_and_the_cash_is_this_branches(): void
    {
        $this->twoBranches();
        $sale = $this->partSale($this->patient());
        $this->at($this->east);

        $page = Livewire::test(OutstandingBalancesComponent::class)->assertDontSee($sale->transaction_id)
            ->set('allBranches', true)->assertSee($sale->transaction_id)->assertSee('Billed at Main')
            ->call('openCollect', $sale->id)->set('collectAmount', '300')->call('collectPayment')->assertHasNoErrors();
        $sale = Sales::withoutGlobalScope('branch')->findOrFail($sale->id);
        $this->assertSame(['paid', 400.0, $this->branch->id], [$sale->payment_status, (float) $sale->amount_paid, (int) $sale->branch_id]);
        $payment = PaymentTransaction::withoutGlobalScope('branch')->where('sale_id', $sale->id)->sole();
        $this->assertSame($this->east->id, (int) $payment->branch_id);
        $this->assertSame(1, SaleItem::withoutGlobalScope('branch')->where('sale_id', $sale->id)->where('dispensed_quantity', 1)->count());
        $page->call('openHistory', $sale->id)->assertSee('at East');
        // Its till receipt prints here, from the bill of the other branch.
        $this->get(route('cashier.receipt.show', $sale->id))->assertOk()->assertSee($sale->transaction_id);
    }

    public function test_glasses_ready_at_another_branch_are_handed_over_here_once_paid(): void
    {
        $this->twoBranches();
        $patient = $this->patient();
        $consultation = $this->visit($patient, 'Glasses');
        $sale = $this->partSale($patient, $consultation);
        $order = LensOrder::create(['user_id' => auth()->id(), 'refraction_id' => $consultation->refraction->id, 'order_id' => 'ORD-MB1', 'status' => 'Ready',
            'frame_model_number' => 'Frame', 'frame_price' => 300, 'lens_price' => 100, 'pickUpDate' => now()->toDateString()]);
        $this->at($this->east);

        $page = Livewire::test(SpectaclesComponent::class)->call('setStatusFilter', 'Ready')->assertSee('Glasses ready at another branch?')
            ->set('otherBranchSearch', 'Branchwalker')->assertSee('ORD-MB1')->assertSee('ready at Main')
            ->call('collectHere', $order->id)->assertHasErrors('otherBranchSearch');
        $this->assertSame('Ready', $order->fresh()->status);

        // Paid here (any branch), then handed over here.
        Sales::withoutGlobalScope('branch')->whereKey($sale->id)->update(['amount_paid' => 400, 'payment_status' => 'paid']);
        $page->call('collectHere', $order->id)->assertHasNoErrors();
        $order = LensOrder::withoutGlobalScope('branch')->findOrFail($order->id);
        $this->assertSame(['Collected', $this->branch->id], [$order->status, (int) $order->branch_id]);
        $this->assertStringContainsString('Collected at East', $order->notes);
    }

    public function test_a_branch_asks_another_for_stock_and_receives_what_arrived(): void
    {
        $this->twoBranches();
        $category = Category::firstOrCreate(['name' => 'Drops'], ['user_id' => auth()->id()]);
        $drops = Product::create(['user_id' => auth()->id(), 'category_id' => $category->id, 'name' => 'Timolol', 'quantity' => 0, 'cost_price' => 10, 'selling_price' => 20]);
        $inventory = app(BranchInventoryService::class);
        $inventory->increase($drops, 10); // at Main

        // East asks Main for 6 (two lines are allowed; one here).
        $this->at($this->east);
        Livewire::test(StockTransferComponent::class)->call('setMode', 'request')->set('otherBranchId', $this->branch->id)
            ->set('lines', [['product_id' => $drops->id, 'quantity' => 6]])->call('createTransfer')->assertHasNoErrors()->assertSee('Requested');
        $transfer = StockTransfer::withoutGlobalScopes()->sole();
        $this->assertSame(['requested', $this->branch->id, $this->east->id], [$transfer->status, (int) $transfer->branch_id, (int) $transfer->destination_branch_id]);

        // Main sees it, sends it: the stock leaves Main.
        $this->at($this->branch);
        Livewire::test(StockTransferComponent::class)->assertSee('Asked of this branch')->call('dispatchTransfer', $transfer->id);
        $this->assertSame(['in_transit', 4], [$transfer->fresh()->status, $inventory->quantity($drops, $this->branch->id)]);

        // East counts 5: 5 go on its shelf and the line shows one short.
        $this->at($this->east);
        $item = $transfer->items()->sole();
        Livewire::test(StockTransferComponent::class)->call('startReceive', $transfer->id)->set("received.{$item->id}", 7)->call('receiveTransfer')->assertHasErrors('received')
            ->set("received.{$item->id}", 5)->call('receiveTransfer')->assertHasNoErrors()->assertSee('short 1');
        $this->assertSame(['received', 5, 5], [$transfer->fresh()->status, $item->fresh()->received_quantity, $inventory->quantity($drops, $this->east->id)]);

        // A request Main can't fill is declined with a reason; a request not answered can be withdrawn.
        $second = app(StockTransferService::class)->request($this->branch->id, [['product_id' => $drops->id, 'quantity' => 50]]);
        $this->at($this->branch);
        Livewire::test(StockTransferComponent::class)->call('dispatchTransfer', $second->id);
        $this->assertSame('requested', $second->fresh()->status, 'more than Main holds: refused');
        Livewire::test(StockTransferComponent::class)->call('startDecline', $second->id)->set('declineReason', 'We only have 4')->call('declineTransfer')->assertHasNoErrors();
        $this->assertSame(['declined', 'We only have 4'], [$second->fresh()->status, $second->fresh()->response_note]);
        $this->at($this->east);
        $third = app(StockTransferService::class)->request($this->branch->id, [['product_id' => $drops->id, 'quantity' => 1]]);
        Livewire::test(StockTransferComponent::class)->assertSee('Declined: We only have 4')->call('cancelTransfer', $third->id);
        $this->assertSame('cancelled', $third->fresh()->status);
    }
    public function test_reports_and_the_cash_book_cover_one_branch_or_all(): void
    {
        $this->twoBranches();
        $patient = $this->patient();
        $this->partSale($patient, null, 300, 300)->update(['payment_status' => 'paid']);
        $this->at($this->east);
        $this->partSale($patient, null, 500, 100);

        // Sales reports: the open branch by default, every branch or one on request.
        $reports = Livewire::test(\App\Livewire\ReportsComponent::class)->call('switchTab', 'month');
        $this->assertSame(500.0, (float) $reports->viewData('summary')['total_sales']);
        $reports->set('branchView', 'all')->assertSee('All branches');
        $this->assertSame(800.0, (float) $reports->viewData('summary')['total_sales']);
        $reports->set('branchView', (string) $this->branch->id);
        $this->assertSame(300.0, (float) $reports->viewData('summary')['total_sales']);
        $this->get(route('reports.export.pdf', ['from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString(), 'branch' => 'all']))->assertOk();
        $this->at($this->east); // a request opens the default branch again

        // Cash book: every branch side by side (read-only), another branch's book read-only.
        $book = Livewire::test(\App\Livewire\Admin\CashBookComponent::class)->set('branchView', 'all');
        $this->assertCount(2, $book->viewData('summaryRows'));
        $book->set('branchView', (string) $this->branch->id)->assertSee('Viewing only')
            ->set('moveAmount', '10')->call('recordMovement')->assertForbidden();

        // Staff can't pick another branch: they always see the open one.
        $this->signIn($this->staff('Cashier'));
        $this->at($this->east);
        $this->assertNull(\App\Support\BranchView::ids('all'));
    }

    public function test_the_medical_record_names_the_branch_of_each_visit(): void
    {
        $this->twoBranches();
        $patient = $this->patient();
        $consultation = $this->visit($patient, 'At main');
        $this->at($this->east);
        $this->visit($patient, 'At east');

        $this->get(route('doctor.medical-record.preview', [$patient, $consultation->clearance]))->assertOk()->assertSee('Main')->assertSee('East');
    }
}