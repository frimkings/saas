<?php

namespace App\Livewire\Scans;

use App\Models\AuditTrail;
use App\Models\DiagnosticScan;
use App\Services\ClinicAccessService;
use App\Support\Feature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** Diagnostic Scans → Scan Catalogue: the scans the clinic offers, their price and the referrer's commission. */
class ScanCatalogueComponent extends Component
{
    public string $search = '';
    public string $statusFilter = 'active';

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $code = '';
    public $price = '';
    public bool $perEye = false;
    public string $commissionType = 'percent';
    public $commissionValue = '';
    public string $turnaround = '';
    public bool $isActive = true;

    public function setStatus(string $status): void
    {
        abort_unless(in_array($status, ['active', 'archived', 'all'], true), 422);
        $this->statusFilter = $status;
    }

    private function assertManager(): void
    {
        abort_unless(DiagnosticScansComponent::canManage(), 403);
    }

    public function add(): void
    {
        $this->assertManager();
        $this->reset(['editingId', 'name', 'code', 'price', 'perEye', 'commissionValue', 'turnaround']);
        $this->commissionType = 'percent';
        $this->isActive = true;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->assertManager();
        $scan = DiagnosticScan::findOrFail($id);
        $this->editingId = $scan->id;
        $this->name = $scan->name;
        $this->code = $scan->code ?? '';
        $this->price = (string) $scan->price;
        $this->perEye = $scan->per_eye;
        $this->commissionType = $scan->commission_type;
        $this->commissionValue = $scan->commission_type === 'none' ? '' : (string) $scan->commission_value;
        $this->turnaround = $scan->turnaround ?? '';
        $this->isActive = $scan->is_active;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->assertManager();
        app(ClinicAccessService::class)->assertWritable(Feature::DIAGNOSTIC_SCANS);
        $this->name = trim($this->name);
        $this->code = strtoupper(trim($this->code));
        $clinicId = app(TenantContext::class)->clinicId();
        $none = $this->commissionType === 'none';

        $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('diagnostic_scans', 'name')->where('clinic_id', $clinicId)->ignore($this->editingId)],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('diagnostic_scans', 'code')->where('clinic_id', $clinicId)->ignore($this->editingId)],
            'price' => 'required|numeric|min:0|max:9999999',
            'perEye' => 'boolean',
            'commissionType' => 'required|in:'.implode(',', array_keys(DiagnosticScan::COMMISSION_TYPES)),
            'commissionValue' => $none ? 'nullable' : ['required', 'numeric', 'gt:0', $this->commissionType === 'percent' ? 'max:100' : 'lte:price'],
            'turnaround' => 'nullable|string|max:50',
            'isActive' => 'boolean',
        ], [
            'name.unique' => 'A scan with this name already exists (it may be archived).',
            'code.unique' => 'Another scan already uses this code.',
            'commissionValue.max' => 'A percentage can be at most 100.',
            'commissionValue.lte' => "A fixed commission can't be more than the scan's price.",
            'commissionValue.required' => 'Enter the commission, or choose "No commission".',
        ]);

        $scan = $this->editingId ? DiagnosticScan::findOrFail($this->editingId) : new DiagnosticScan();
        $scan->fill([
            'name' => $this->name,
            'code' => $this->code ?: null,
            'price' => round((float) $this->price, 2),
            'per_eye' => $this->perEye,
            'commission_type' => $this->commissionType,
            'commission_value' => $none ? 0 : round((float) $this->commissionValue, 2),
            'turnaround' => trim($this->turnaround) ?: null,
            'is_active' => $this->isActive,
        ]);
        AuditTrail::recordSave($scan, 'scans.catalogue', 'diagnostic scan '.$scan->name);
        $this->showForm = false;
        session()->flash('success', 'Scan saved.');
    }

    public function archive(int $id): void
    {
        $this->setActive($id, false);
        session()->flash('success', 'Scan archived. It can no longer be ordered; past orders keep it.');
    }

    public function restore(int $id): void
    {
        $this->setActive($id, true);
        session()->flash('success', 'Scan restored.');
    }

    private function setActive(int $id, bool $active): void
    {
        $this->assertManager();
        app(ClinicAccessService::class)->assertWritable(Feature::DIAGNOSTIC_SCANS);
        $scan = DiagnosticScan::findOrFail($id);
        $scan->is_active = $active;
        AuditTrail::recordSave($scan, 'scans.catalogue', 'diagnostic scan '.$scan->name.($active ? ' (restored)' : ' (archived)'));
    }

    public function render()
    {
        $all = DiagnosticScan::orderBy('name')->get();
        $term = mb_strtolower(trim($this->search));
        $scans = $all
            ->when($this->statusFilter === 'active', fn ($c) => $c->where('is_active', true))
            ->when($this->statusFilter === 'archived', fn ($c) => $c->where('is_active', false))
            ->when($term !== '', fn ($c) => $c->filter(fn ($s) => str_contains(mb_strtolower($s->name.' '.$s->code), $term)))
            ->values();
        $active = $all->where('is_active', true);

        return view('livewire.scans.scan-catalogue-component', [
            'scans' => $scans,
            'canManage' => DiagnosticScansComponent::canManage(),
            'counts' => ['active' => $active->count(), 'archived' => $all->count() - $active->count(), 'all' => $all->count()],
            'tiles' => [
                'withCommission' => $active->where('commission_type', '!=', 'none')->count(),
                'lowest' => $active->min('price'),
                'highest' => $active->max('price'),
            ],
            // Commission on the form's figures, shown as it is typed.
            'preview' => is_numeric($this->price) && ($this->commissionType === 'none' || is_numeric($this->commissionValue))
                ? DiagnosticScan::commissionOn((float) $this->price, $this->commissionType, (float) $this->commissionValue) : null,
        ]);
    }
}
