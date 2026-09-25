<?php

namespace App\Livewire\Optical;

use App\Models\LensOrder;
use App\Models\OpticalPrescription;
use App\Models\Patient;
use App\Models\Refractions;
use App\Services\ClinicAccessService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class OpticalPrescriptionsComponent extends Component
{
    use WithPagination;

    public const SOURCES = ['' => 'All', 'external' => 'External', 'entered' => 'Entered with an order', 'clinic' => 'From the clinic'];

    public string $searchTerm = '';
    public string $sourceFilter = '';
    public ?int $viewRxId = null;
    public bool $showExternalModal = false;
    public bool $showAllClinic = false;

    // External Rx form
    public string $customerSearch = '';
    public $patient_id;
    public $prescriber_name;
    public $prescriber_clinic;
    public $rx_date;
    public $od_sphere = '';
    public $od_cylinder = '';
    public $od_axis = '';
    public $od_add = '';
    public $os_sphere = '';
    public $os_cylinder = '';
    public $os_axis = '';
    public $os_add = '';
    public $pd = '';
    public $pd_right = '';
    public $pd_left = '';
    public $od_va = '';
    public $os_va = '';
    public $notes = '';

    protected $queryString = ['searchTerm' => ['except' => ''], 'sourceFilter' => ['except' => '']];

    public function mount()
    {
        $this->rx_date = now()->format('Y-m-d');
    }

    public function updated($name): void
    {
        if (in_array($name, ['searchTerm', 'sourceFilter'], true)) $this->resetPage();
    }

    public function setSource(string $source): void
    {
        abort_unless(array_key_exists($source, self::SOURCES), 422);
        $this->sourceFilter = $source;
        $this->resetPage();
    }

    public function openRx(int $id): void
    {
        $this->viewRxId = OpticalPrescription::findOrFail($id)->id;
        $this->showExternalModal = false;
    }

    public function closeRx(): void
    {
        $this->viewRxId = null;
    }

    public function openExternalModal(?int $patientId = null)
    {
        $this->resetExternalForm();
        $this->viewRxId = null;
        if ($patientId) $this->pickCustomer($patientId);
        $this->showExternalModal = true;
    }

    public function closeExternalModal()
    {
        $this->showExternalModal = false;
        $this->resetErrorBag();
    }

    public function pickCustomer(int $id): void
    {
        $this->patient_id = Patient::findOrFail($id)->id;
        $this->customerSearch = '';
        $this->resetErrorBag('patient_id');
    }

    public function clearCustomer(): void
    {
        $this->patient_id = null;
    }

    private function resetExternalForm(): void
    {
        $this->reset(['customerSearch', 'patient_id', 'prescriber_name', 'prescriber_clinic', 'od_sphere', 'od_cylinder', 'od_axis', 'od_add',
            'os_sphere', 'os_cylinder', 'os_axis', 'os_add', 'pd', 'pd_right', 'pd_left', 'od_va', 'os_va', 'notes']);
        $this->rx_date = now()->format('Y-m-d');
        $this->resetErrorBag();
    }

    public function saveExternalRx()
    {
        $this->validate([
            'patient_id' => ['required', Rule::exists('patients', 'id')->where('clinic_id', app(TenantContext::class)->clinicId())],
            'prescriber_name' => 'required|string|max:255',
            'prescriber_clinic' => 'nullable|string|max:255',
            'rx_date' => 'required|date|before_or_equal:today',
            'od_sphere' => 'required|numeric|between:-30,30',
            'od_cylinder' => 'nullable|numeric|between:-15,15',
            'od_axis' => 'nullable|integer|between:0,180',
            'od_add' => 'nullable|numeric|between:0,8',
            'os_sphere' => 'required|numeric|between:-30,30',
            'os_cylinder' => 'nullable|numeric|between:-15,15',
            'os_axis' => 'nullable|integer|between:0,180',
            'os_add' => 'nullable|numeric|between:0,8',
            'pd' => 'nullable|numeric|between:40,85',
            'pd_right' => 'nullable|numeric|between:20,45',
            'pd_left' => 'nullable|numeric|between:20,45',
            'notes' => 'nullable|string|max:1000',
        ], [], ['patient_id' => 'customer', 'prescriber_name' => 'prescriber', 'rx_date' => 'prescription date',
            'od_sphere' => 'right eye sphere', 'os_sphere' => 'left eye sphere']);
        app(ClinicAccessService::class)->assertWritable('optical');
        $rx = OpticalPrescription::create([
            'patient_id' => $this->patient_id,
            'created_by' => auth()->id(),
            'source' => 'external',
            'prescriber_name' => $this->prescriber_name,
            'prescriber_clinic' => $this->prescriber_clinic,
            'prescribed_at' => $this->rx_date,
            'measurements' => [
                'od' => ['sph' => $this->od_sphere, 'cyl' => $this->od_cylinder, 'axis' => $this->od_axis, 'add' => $this->od_add, 'va' => $this->od_va, 'pd' => $this->pd_right],
                'os' => ['sph' => $this->os_sphere, 'cyl' => $this->os_cylinder, 'axis' => $this->os_axis, 'add' => $this->os_add, 'va' => $this->os_va, 'pd' => $this->pd_left],
                'pd' => $this->pd,
            ],
            'notes' => $this->notes,
        ]);
        session()->flash('success', 'External prescription saved.');
        $this->showExternalModal = false;
        $this->viewRxId = $rx->id;
    }

    private function query()
    {
        $term = '%'.$this->searchTerm.'%';
        return OpticalPrescription::query()
            ->when($this->searchTerm !== '', fn ($q) => $q->where(fn ($x) => $x
                ->whereHas('patient', fn ($p) => $p->where('name', 'like', $term)->orWhere('contact', 'like', $term)->orWhere('pxnumber', 'like', $term))
                ->orWhere('prescriber_name', 'like', $term)->orWhere('prescriber_clinic', 'like', $term)));
    }

    public function render()
    {
        $prescriptions = $this->query()->with(['patient', 'creator'])->withCount('orders')
            ->when($this->sourceFilter !== '', fn ($q) => $q->where('source', $this->sourceFilter))
            ->latest()->paginate(12);

        // Authorized clinic refractions the optical side can dispense from.
        $clinicRefractions = \App\Support\OpticalMode::opticalOnly() ? collect() : Refractions::with(['consultation.patient', 'user'])
            ->where('dispensing_required', true)
            ->whereNotNull('dispensing_authorized_at')
            ->whereHas('consultation.patient', fn ($q) => $q->when($this->searchTerm !== '', fn ($p) => $p->where('name', 'like', '%'.$this->searchTerm.'%')))
            ->latest()->limit($this->showAllClinic ? 50 : 6)->get();
        $orderedRefractions = LensOrder::whereIn('refraction_id', $clinicRefractions->pluck('id'))->where('status', '!=', 'Cancelled')
            ->get(['id', 'order_id', 'refraction_id'])->keyBy('refraction_id');

        return view('livewire.optical.optical-prescriptions-component', [
            'prescriptions' => $prescriptions,
            'sourceCounts' => collect(array_keys(self::SOURCES))->mapWithKeys(fn ($key) => [$key => $this->query()->when($key !== '', fn ($q) => $q->where('source', $key))->count()]),
            'customerMatches' => $this->showExternalModal && ! $this->patient_id && strlen(trim($this->customerSearch)) >= 2
                ? Patient::where(fn ($q) => $q->where('name', 'like', '%'.trim($this->customerSearch).'%')->orWhere('contact', 'like', '%'.trim($this->customerSearch).'%')->orWhere('pxnumber', 'like', '%'.trim($this->customerSearch).'%'))
                    ->orderBy('name')->limit(8)->get(['id', 'name', 'contact', 'pxnumber'])
                : collect(),
            'selectedCustomer' => $this->patient_id ? Patient::find($this->patient_id, ['id', 'name', 'contact', 'pxnumber']) : null,
            'clinicRefractions' => $clinicRefractions,
            'orderedRefractions' => $orderedRefractions,
            'viewRx' => $this->viewRxId ? OpticalPrescription::with(['patient', 'creator', 'verifier', 'refraction.user', 'orders' => fn ($q) => $q->latest()])->find($this->viewRxId) : null,
        ])->layout('layouts.optical');
    }
}
