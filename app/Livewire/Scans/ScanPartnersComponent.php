<?php

namespace App\Livewire\Scans;

use App\Models\AuditTrail;
use App\Models\DiagnosticScan;
use App\Models\ScanPartnerClinic;
use App\Models\ScanPartnerDoctor;
use App\Models\ScanPartnerRate;
use App\Services\ClinicAccessService;
use App\Support\Feature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Diagnostic Scans → Partner Clinics: clinics that refer patients for scans. Like the optical
 * shop's partner clinics, without billing: referred patients always pay, and partners only earn
 * commission. Each partner has its doctors and any rates agreed with it.
 */
class ScanPartnersComponent extends Component
{
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'active';
    public ?int $viewPartnerId = null;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $code = '';
    public string $name = '';
    public string $contactPerson = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $notifyVia = 'whatsapp';
    public string $notificationPhone = '';
    public string $payoutMethod = 'cash';
    public string $payoutAccount = '';
    public bool $isActive = true;

    public string $doctorName = '';
    public string $doctorPhone = '';

    public ?int $rateScanId = null;
    public string $rateType = 'percent';
    public $rateValue = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'statusFilter'], true)) $this->resetPage();
    }

    public function setStatus(string $status): void
    {
        abort_unless(in_array($status, ['active', 'archived', 'all'], true), 422);
        $this->statusFilter = $status;
        $this->resetPage();
    }

    private function assertManager(): void
    {
        abort_unless(DiagnosticScansComponent::canManage(), 403);
    }

    private function assertWritable(): void
    {
        $this->assertManager();
        app(ClinicAccessService::class)->assertWritable(Feature::DIAGNOSTIC_SCANS);
    }

    private function viewed(): ScanPartnerClinic
    {
        abort_unless($this->viewPartnerId, 404);

        return ScanPartnerClinic::findOrFail($this->viewPartnerId);
    }

    public function openPartner(int $id): void
    {
        $this->viewPartnerId = ScanPartnerClinic::findOrFail($id)->id;
        $this->showForm = false;
        $this->reset(['doctorName', 'doctorPhone', 'rateScanId', 'rateValue']);
        $this->resetErrorBag();
    }

    public function closePartner(): void
    {
        $this->viewPartnerId = null;
        $this->showForm = false;
        $this->resetErrorBag();
    }

    public function add(): void
    {
        $this->assertManager();
        $this->reset(['editingId', 'name', 'contactPerson', 'phone', 'email', 'address', 'notificationPhone', 'payoutAccount']);
        $this->code = ScanPartnerClinic::nextCode();
        $this->notifyVia = 'whatsapp';
        $this->payoutMethod = 'cash';
        $this->isActive = true;
        $this->viewPartnerId = null;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->assertManager();
        $partner = ScanPartnerClinic::findOrFail($id);
        $this->editingId = $partner->id;
        $this->code = $partner->code;
        $this->name = $partner->name;
        $this->contactPerson = $partner->contact_person ?? '';
        $this->phone = $partner->phone ?? '';
        $this->email = $partner->email ?? '';
        $this->address = $partner->address ?? '';
        $this->notifyVia = $partner->notify_via ?: 'whatsapp';
        $this->notificationPhone = $partner->notification_phone ?? '';
        $this->payoutMethod = $partner->payout_method ?: 'cash';
        $this->payoutAccount = $partner->payout_account ?? '';
        $this->isActive = $partner->is_active;
        $this->viewPartnerId = $partner->id;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        // Editing returns to the partner's panel; adding closes.
        $this->showForm = false;
        if (! $this->editingId) $this->viewPartnerId = null;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->assertWritable();
        $this->name = trim($this->name);
        $this->code = strtoupper(trim($this->code));
        $clinicId = app(TenantContext::class)->clinicId();
        $this->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('scan_partner_clinics', 'code')->where('clinic_id', $clinicId)->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:255', Rule::unique('scan_partner_clinics', 'name')->where('clinic_id', $clinicId)->ignore($this->editingId)],
            'contactPerson' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:2000',
            'notifyVia' => 'required|in:'.implode(',', array_keys(ScanPartnerClinic::NOTIFY_VIA)),
            'notificationPhone' => 'nullable|string|max:50',
            'payoutMethod' => 'required|in:'.implode(',', array_keys(ScanPartnerClinic::PAYOUT_METHODS)),
            'payoutAccount' => 'nullable|string|max:255',
            'isActive' => 'boolean',
        ], [
            'name.unique' => 'A partner clinic with this name already exists (it may be archived).',
            'code.unique' => 'Another partner clinic already uses this code.',
        ]);
        if ($this->notifyVia === 'email' && trim($this->email) === '') {
            $this->addError('email', 'Enter an email address to send results by email.');
            return;
        }

        $partner = $this->editingId ? ScanPartnerClinic::findOrFail($this->editingId) : new ScanPartnerClinic();
        $partner->fill([
            'code' => $this->code, 'name' => $this->name, 'contact_person' => trim($this->contactPerson) ?: null,
            'phone' => trim($this->phone) ?: null, 'email' => trim($this->email) ?: null, 'address' => trim($this->address) ?: null,
            'notify_via' => $this->notifyVia, 'notification_phone' => trim($this->notificationPhone) ?: null,
            'payout_method' => $this->payoutMethod, 'payout_account' => trim($this->payoutAccount) ?: null,
            'is_active' => $this->isActive,
        ]);
        AuditTrail::recordSave($partner, 'scans.partner_clinic', 'scan partner clinic '.$partner->name);
        $this->showForm = false;
        $this->viewPartnerId = $partner->id;
        session()->flash('success', 'Partner clinic saved.');
    }

    public function archive(int $id): void
    {
        $this->setActive($id, false);
        session()->flash('success', 'Partner clinic archived. It can no longer be picked for new scans.');
    }

    public function restore(int $id): void
    {
        $this->setActive($id, true);
        session()->flash('success', 'Partner clinic restored.');
    }

    private function setActive(int $id, bool $active): void
    {
        $this->assertWritable();
        $partner = ScanPartnerClinic::findOrFail($id);
        $partner->is_active = $active;
        AuditTrail::recordSave($partner, 'scans.partner_clinic', 'scan partner clinic '.$partner->name.($active ? ' (restored)' : ' (archived)'));
    }

    // --- Doctors -------------------------------------------------------------------------

    public function addDoctor(): void
    {
        $this->assertWritable();
        $partner = $this->viewed();
        $this->doctorName = trim($this->doctorName);
        $this->validate([
            'doctorName' => ['required', 'string', 'max:255', Rule::unique('scan_partner_doctors', 'name')->where('scan_partner_clinic_id', $partner->id)],
            'doctorPhone' => 'nullable|string|max:50',
        ], ['doctorName.unique' => 'This doctor is already listed (they may be marked as left).', 'doctorName.required' => "Enter the doctor's name."]);
        $doctor = new ScanPartnerDoctor(['scan_partner_clinic_id' => $partner->id, 'name' => $this->doctorName, 'phone' => trim($this->doctorPhone) ?: null, 'is_active' => true]);
        AuditTrail::recordSave($doctor, 'scans.partner_doctor', 'doctor '.$doctor->name.' at '.$partner->name);
        $this->reset(['doctorName', 'doctorPhone']);
    }

    public function toggleDoctor(int $id): void
    {
        $this->assertWritable();
        $doctor = ScanPartnerDoctor::where('scan_partner_clinic_id', $this->viewed()->id)->findOrFail($id);
        $doctor->is_active = ! $doctor->is_active;
        AuditTrail::recordSave($doctor, 'scans.partner_doctor', 'doctor '.$doctor->name.($doctor->is_active ? ' (back)' : ' (left)'));
    }

    // --- Rates agreed with this partner ---------------------------------------------------

    public function saveRate(): void
    {
        $this->assertWritable();
        $partner = $this->viewed();
        $scan = DiagnosticScan::find($this->rateScanId);
        if (! $scan) {
            $this->addError('rateScanId', 'Choose a scan.');
            return;
        }
        $none = $this->rateType === 'none';
        $this->validate([
            'rateType' => 'required|in:'.implode(',', array_keys(DiagnosticScan::COMMISSION_TYPES)),
            'rateValue' => $none ? 'nullable' : ['required', 'numeric', 'gt:0', $this->rateType === 'percent' ? 'max:100' : 'max:'.(float) $scan->price],
        ], [
            'rateValue.max' => $this->rateType === 'percent' ? 'A percentage can be at most 100.' : "A fixed commission can't be more than the scan's price.",
            'rateValue.required' => 'Enter the commission, or choose "No commission".',
        ]);
        $rate = ScanPartnerRate::firstOrNew(['scan_partner_clinic_id' => $partner->id, 'diagnostic_scan_id' => $scan->id]);
        $rate->fill(['commission_type' => $this->rateType, 'commission_value' => $none ? 0 : round((float) $this->rateValue, 2)]);
        AuditTrail::recordSave($rate, 'scans.partner_rate', 'commission for '.$partner->name.' on '.$scan->name);
        $this->reset(['rateScanId', 'rateValue']);
        $this->rateType = 'percent';
    }

    public function removeRate(int $id): void
    {
        $this->assertWritable();
        $rate = ScanPartnerRate::with('scan')->where('scan_partner_clinic_id', $this->viewed()->id)->findOrFail($id);
        AuditTrail::record('scans.partner_rate_deleted', 'Removed the agreed commission on '.($rate->scan?->name ?? 'a scan').'; the catalogue rate applies', $rate,
            $rate->only(['commission_type', 'commission_value']), []);
        $rate->delete();
    }

    public function render()
    {
        $all = ScanPartnerClinic::withCount(['doctors' => fn ($q) => $q->where('is_active', true), 'rates'])->orderBy('name')->get();
        $term = mb_strtolower(trim($this->search));
        $filtered = $all
            ->when($this->statusFilter === 'active', fn ($c) => $c->where('is_active', true))
            ->when($this->statusFilter === 'archived', fn ($c) => $c->where('is_active', false))
            ->when($term !== '', fn ($c) => $c->filter(fn ($p) => str_contains(mb_strtolower(implode(' ', [$p->code, $p->name, $p->contact_person, $p->phone, $p->email])), $term)))
            ->values();
        $page = $this->getPage();
        $partners = new \Illuminate\Pagination\LengthAwarePaginator($filtered->forPage($page, 10), $filtered->count(), 10, $page, ['path' => request()->url()]);

        $view = $this->viewPartnerId ? ScanPartnerClinic::with(['doctors' => fn ($q) => $q->orderByDesc('is_active')->orderBy('name'), 'rates.scan'])->find($this->viewPartnerId) : null;
        $canManage = DiagnosticScansComponent::canManage();

        return view('livewire.scans.scan-partners-component', [
            'partners' => $partners,
            'counts' => ['active' => $all->where('is_active', true)->count(), 'archived' => $all->where('is_active', false)->count(), 'all' => $all->count()],
            'doctorTotal' => $all->where('is_active', true)->sum('doctors_count'),
            'agreedRates' => $all->where('is_active', true)->sum('rates_count'),
            'viewPartner' => $view,
            'canManage' => $canManage,
            // Scans that can still be given a rate for this partner.
            'rateScans' => $view && $canManage ? DiagnosticScan::where('is_active', true)->whereNotIn('id', $view->rates->pluck('diagnostic_scan_id'))->orderBy('name')->get() : collect(),
            'scanCount' => DiagnosticScan::where('is_active', true)->count(),
        ]);
    }
}
