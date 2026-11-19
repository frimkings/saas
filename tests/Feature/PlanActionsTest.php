<?php

namespace Tests\Feature;

use App\Livewire\Admin\ProductsComponent;
use App\Livewire\Cashier\SalesRecordsComponent;
use App\Livewire\Doctor\PatientRecordsComponent;
use App\Livewire\POSComponent;
use App\Models\{Appointments, CashierPatientClearance, DiscountApprovalRequest, Patient, Referral};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Plan features used from pages every plan can open (patient record, till, sales records, products):
 * a hidden button is not enough, the action itself must refuse on a plan without the feature (18 Nov 2026).
 */
class PlanActionsTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\ClinicOnPlan;

    private function record()
    {
        $owner = auth()->user();
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $owner->id, 'name' => 'Kofi Asare', 'contact' => '0241112222', 'gender' => 'Male']);
        $clearance = CashierPatientClearance::create(['user_id' => $owner->id, 'patient_id' => $patient->id, 'payment_status' => 'Paid', 'doctor_status' => false, 'clearance_date' => now()->toDateString()]);

        return Livewire::test(PatientRecordsComponent::class, ['clearance' => $clearance]);
    }

    private function book($record)
    {
        return $record->call('toggleAppointmentSection')->set('appointmentTitle', 'Follow-up Visit')
            ->set('appointmentScheduledAt', now()->addWeek()->setTime(10, 0)->format('Y-m-d\TH:i'))->set('appointmentReminderChannel', 'none')
            ->call('bookAppointmentFromConsultation');
    }

    private function urgentReferral($record)
    {
        return $record->set('state.chiefComplaint', 'Sudden vision loss in the right eye')->call('createUrgentReferralDraft');
    }

    public function test_starter_cannot_book_from_the_record_draft_a_referral_or_attach_documents(): void
    {
        $this->ownerOn('clinic-starter');

        $this->book($this->record());
        $this->assertSame(0, Appointments::count(), 'appointments are on Standard');

        $this->urgentReferral($this->record());
        $this->assertSame(0, Referral::count(), 'referrals are on Standard');

        $this->record()->set('documentFiles', [UploadedFile::fake()->create('oct.pdf', 20, 'application/pdf')])->set('documentType', 'oct')
            ->call('uploadPatientDocument')->assertForbidden();
    }

    public function test_standard_can_do_all_three_from_the_record(): void
    {
        $this->ownerOn('clinic-standard');

        $this->book($this->record())->assertHasNoErrors();
        $this->assertSame(1, Appointments::count());
        $this->urgentReferral($this->record());
        $this->assertSame(1, Referral::count());
    }

    public function test_starter_cannot_export_sales_import_products_or_ask_for_a_discount(): void
    {
        $this->ownerOn('clinic-starter');
        $this->paidSale();

        Livewire::test(SalesRecordsComponent::class)->call('exportCSV')->assertForbidden();
        foreach (['exportCsv', 'downloadTemplate', 'importCsv'] as $action) {
            Livewire::test(ProductsComponent::class)->call($action)->assertForbidden();
        }
        // The lens catalogue is on every clinic plan: a refraction needing glasses needs a lens type.
        Livewire::test(ProductsComponent::class)->call('toggleLensCatalog')->assertSet('showLensCatalog', true)
            ->call('addCommonLensTypes');
        $this->assertSame(19, \App\Models\LensOption::count());
        Livewire::test(ProductsComponent::class)->call('addCommonLensTypes');
        $this->assertSame(19, \App\Models\LensOption::count(), 'Only the missing ones are added.');

        Livewire::test(POSComponent::class)->set('discountValue', 10)->call('requestDiscountApproval')->assertSet('discountValue', 0)->assertSet('discountAmount', 0);
        $this->assertSame(0, DiscountApprovalRequest::count());
    }

    public function test_standard_exports_sales_and_imports_products(): void
    {
        $this->ownerOn('clinic-standard');
        $this->paidSale();

        Livewire::test(SalesRecordsComponent::class)->call('exportCSV')->assertStatus(200);
        Livewire::test(ProductsComponent::class)->call('downloadTemplate')->assertStatus(200);
    }

    public function test_history_charts_and_risk_flags_start_on_plus(): void
    {
        foreach (['clinic-standard' => false, 'clinic-plus' => true] as $family => $charts) {
            $this->ownerOn($family);
            $record = $this->record()->call('switchTab', 'history');
            $this->assertSame($charts, $record->viewData('canCharts'), $family);
            if (!$charts) $this->assertSame([], $record->viewData('clinicalTrendData'), $family);
        }
    }
}
