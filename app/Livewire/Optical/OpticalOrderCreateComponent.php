<?php

namespace App\Livewire\Optical;

use App\Models\LensOrder;
use App\Models\OpticalCategory;
use App\Models\Refractions;
use App\Models\Product;
use App\Models\OpticalProduct;
use App\Models\Patient;
use App\Models\OpticalPrescription;
use App\Models\OpticalSetting;
use App\Models\OpticalService;
use App\Models\OpticalPartnerClinic;
use App\Services\OpticalOrderService;
use App\Services\OpticalLensAvailabilityService;
use App\Services\OpticalOrderWorkflowService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

class OpticalOrderCreateComponent extends Component
{
    public $currentStep = 1;
    public ?int $editingQuotationId = null;
    #[\Livewire\Attributes\Locked]
    public ?int $remakeOfId = null;
    public string $remake_reason = '';
    public string $remake_charge = '';

    // Step 1: Customer
    public $patient_id;
    public string $customerSearch = '';
    public $customer_name;
    public $customer_phone;
    public string $reference = '';
    public string $order_source = 'in_clinic';
    public $partner_id;
    public string $partnerSearch = '';
    public string $work_type = 'prescription';
    public string $bill_to = 'customer';
    public string $partner_clinic_name = '';
    public array $service_lines = [];

    // Step 2: Prescr (Rx)
    public $refraction_id;
    public $optical_prescription_id;
    public $frame_product_id;
    public $lens_product_id;
    public $frame_optical_product_id;
    public $lens_optical_product_id;
    public $optical_category_id;
    public $rx_od_sph = '';
    public $rx_od_cyl = '';
    public $rx_od_axis = '';
    public $rx_od_add = '';
    public $rx_od_hgt = '';
    public $rx_od_pd = '';
    
    public $rx_os_sph = '';
    public $rx_os_cyl = '';
    public $rx_os_axis = '';
    public $rx_os_add = '';
    public $rx_os_hgt = '';
    public $rx_os_pd = '';

    // Step 3: Frame
    public string $frame_source = 'custom';
    public $frame_model_number = '';
    public $frame_structure = 'Full Frame';
    public $frame_price = 0;

    // Step 4: Lenses
    public $lens_type = 'Single Vision';
    public $lens_index = '1.56';
    public $progressive_brand = 'Advance';
    public $base_color = 'White';
    public $lens_coatings = '';
    public $lens_price = 0;
    public string $stock_coating = 'AR';
    public string $lens_fulfilment_source = 'external';
    public array $lensAvailability = [];
    public array $stockLensOptions = [];
    public string $stock_lens_key = '';

    // Step 5: Fitting / Lab Details (Matches screenshot)
    public $pd_right = '';
    public $pd_left = '';
    public $fitting_height = '';
    public $segment_height = '';
    public $pickUpDate;
    public $lab_instructions = '';

    // Step 6: Pricing
    public $glazing_fee = 0;
    public $discount_amount = 0.00;

    // Step 7: Deposit
    public $paid_amount = 0;
    public $payment_method = 'cash';
    public $deposit_reference = '';

    // Step 8: Review & Notes
    public $notes = '';

    public function mount()
    {
        OpticalService::ensureTemplates();
        $this->order_source = \App\Support\OpticalMode::opticalOnly() ? 'walk_in' : 'in_clinic';
        $this->pickUpDate = now()->addDays(5)->format('Y-m-d');
        if (request()->filled('quotation_id')) {
            $this->loadQuotation((int) request('quotation_id'));
            return;
        }
        if (request()->filled('remake_of')) {
            $this->loadRemake((int) request('remake_of'));
            return;
        }
        if (request()->filled('partner_id')) {
            $this->changeOrderSource('partner');
            $this->choosePartner((int) request('partner_id'));
        }
        if (request()->filled('patient_id')) $this->choosePatient((int) request('patient_id'));
        if (request()->filled('refraction_id')) $this->chooseRefraction((int) request('refraction_id'));
        if (request()->filled('prescription_id')) $this->choosePrescription((int) request('prescription_id'));
    }

    public function choosePatient($id): void
    {
        $this->resetValidation();
        if (! $id) {
            $this->patient_id = null;
            $this->customer_name = $this->customer_phone = '';
            $this->clearPrescription();
            return;
        }
        $patient = Patient::findOrFail((int) $id);
        $this->order_source = 'in_clinic';
        $this->patient_id = $patient->id;
        $this->customer_name = $patient->name;
        $this->customer_phone = $patient->contact;
        $this->customerSearch = '';
        $this->clearPrescription();
    }

    public function updatedOrderSource(): void
    {
        $this->resetRequesterForSource();
    }

    public function changeOrderSource(string $source): void
    {
        abort_unless(in_array($source, ['in_clinic', 'partner', 'walk_in'], true), 422);
        $this->order_source = $source;
        $this->resetRequesterForSource();
    }

    public function changeBillTo(string $billTo): void
    {
        abort_unless(in_array($billTo, ['customer', 'partner'], true), 422);
        $this->bill_to = $billTo;
        $this->resetValidation(['bill_to', 'customer_name', 'reference', 'partner_clinic_name']);
    }

    public function changeWorkType(string $workType): void
    {
        abort_unless(in_array($workType, ['prescription', 'service'], true), 422);
        $this->work_type = $workType;
        $this->resetValidation();
    }

    public function nextStepWithRequester(array $requester): void
    {
        $source = (string) ($requester['order_source'] ?? '');
        abort_unless(in_array($source, ['in_clinic', 'partner', 'walk_in'], true), 422);

        $this->order_source = $source;
        $this->work_type = in_array(($requester['work_type'] ?? ''), ['prescription', 'service'], true)
            ? $requester['work_type'] : 'prescription';
        $this->bill_to = in_array(($requester['bill_to'] ?? ''), ['customer', 'partner'], true)
            ? $requester['bill_to'] : 'customer';

        if ($source === 'partner') {
            $partnerId = filter_var($requester['partner_id'] ?? null, FILTER_VALIDATE_INT);
            $partner = $partnerId ? OpticalPartnerClinic::where('is_active', true)->find($partnerId) : null;
            $this->partner_id = $partner?->id;
            $this->partner_clinic_name = $partner?->name ?? '';
            $this->patient_id = null;
        }

        $this->customer_name = trim((string) ($requester['customer_name'] ?? ''));
        $this->customer_phone = trim((string) ($requester['customer_phone'] ?? ''));
        $this->reference = trim((string) ($requester['reference'] ?? ''));
        $this->nextStep();
    }

    #[On('optical-order-source-changed')]
    public function handleOrderSourceChanged(string $source): void
    {
        $this->changeOrderSource($source);
    }

    private function resetRequesterForSource(): void
    {
        $this->resetValidation();
        $this->patient_id = null;
        $this->partner_id = null;
        $this->customerSearch = '';
        $this->partnerSearch = '';
        $this->customer_name = '';
        $this->customer_phone = '';
        $this->partner_clinic_name = '';
        $this->bill_to = $this->order_source === 'partner' ? 'partner' : 'customer';
        $this->clearPrescription();
    }

    public function choosePartner(int $id): void
    {
        $this->resetValidation();
        $partner = OpticalPartnerClinic::where('is_active', true)->findOrFail($id);
        $this->order_source = 'partner';
        $this->partner_id = $partner->id;
        $this->partner_clinic_name = $partner->name;
        $this->partnerSearch = '';
        $this->customerSearch = '';
        $this->patient_id = null;
        $this->clearPrescription();
        $this->bill_to = 'partner';
    }

    public function choosePrescription($id): void
    {
        if (! $id) {
            $this->clearPrescription();
            return;
        }
        $rx = OpticalPrescription::findOrFail((int) $id);
        if ($this->patient_id && (int) $this->patient_id !== (int) $rx->patient_id) abort(404);
        $this->choosePatient($rx->patient_id);
        $this->optical_prescription_id = $rx->id;
        $this->fillMeasurements($rx->measurements ?? []);
    }

    public function chooseRefraction($id): void
    {
        if (! $id) {
            $this->clearPrescription();
            return;
        }
        abort_if(\App\Support\OpticalMode::opticalOnly(), 403);
        $rx = Refractions::with('consultation.patient')->findOrFail((int) $id);
        abort_unless($rx->dispensing_required && $rx->dispensing_authorized_at && $rx->consultation?->patient, 403);
        $this->choosePatient($rx->consultation->patient->id);
        $this->refraction_id = $rx->id;
        $this->fillMeasurements([
            'od' => ['sph' => $rx->subjective_od_sphere, 'cyl' => $rx->subjective_od_cylinder, 'axis' => $rx->subjective_od_axis, 'add' => $rx->subjective_od_add],
            'os' => ['sph' => $rx->subjective_os_sphere, 'cyl' => $rx->subjective_os_cylinder, 'axis' => $rx->subjective_os_axis, 'add' => $rx->subjective_os_add],
            'pd' => $rx->pd,
        ]);
    }

    private function fillMeasurements(array $rx): void
    {
        $this->lensAvailability = [];
        $this->stockLensOptions = [];
        $this->stock_lens_key = '';
        $this->lens_fulfilment_source = 'external';
        foreach (['od', 'os'] as $eye) foreach (['sph', 'cyl', 'axis', 'add'] as $field) {
            $key = "rx_{$eye}_{$field}";
            $this->{$key} = data_get($rx, "{$eye}.{$field}", '');
        }
        $this->rx_od_hgt = data_get($rx, 'od.hgt', '');
        $this->rx_os_hgt = data_get($rx, 'os.hgt', '');
        $this->rx_od_pd = data_get($rx, 'od.pd', data_get($rx, 'pd', ''));
        $this->rx_os_pd = data_get($rx, 'os.pd', data_get($rx, 'pd', ''));
    }

    public function clearPrescription(): void
    {
        $this->refraction_id = null;
        $this->optical_prescription_id = null;
        foreach (['od', 'os'] as $eye) foreach (['sph', 'cyl', 'axis', 'add', 'hgt', 'pd'] as $field) {
            $this->{"rx_{$eye}_{$field}"} = '';
        }
        $this->lensAvailability = [];
        $this->stockLensOptions = [];
        $this->stock_lens_key = '';
        $this->lens_fulfilment_source = 'external';
    }

    public function chooseOpticalCategory($id): void
    {
        $this->optical_category_id = null;
        $this->lensAvailability = [];
        $this->lens_fulfilment_source = 'external';
        if (! $id) return;
        $category = OpticalCategory::where('is_active', true)->findOrFail((int) $id);
        abort_unless(in_array($category->group, ['single_vision', 'progressive', 'bifocal'], true), 404);
        $this->optical_category_id = $category->id;
        $this->lens_type = [
            'single_vision' => 'Single Vision', 'progressive' => 'Progressive', 'bifocal' => 'Bifocal',
        ][$category->group];
    }

    public function selectFrameOpticalProduct($id): void
    {
        $this->frame_optical_product_id = null;
        $this->frame_product_id = null;
        if (! $id) return;
        $product = OpticalProduct::where('is_active', true)->findOrFail((int) $id);
        abort_unless($product->category?->group === 'frames', 404);
        $this->frame_optical_product_id = $product->id;
        $this->frame_source = 'stock';
        $this->frame_model_number = $product->name;
        $this->frame_price = $product->selling_price;
    }

    public function selectLensOpticalProduct($id): void
    {
        $this->lens_optical_product_id = null;
        $this->lens_product_id = null;
        $this->lens_fulfilment_source = 'external';
        if (! $id) return;
        $product = OpticalProduct::where('is_active', true)->findOrFail((int) $id);
        abort_unless(in_array($product->category?->group, ['single_vision', 'progressive', 'bifocal'], true), 404);
        // A stock lens item is one lens; stock lenses are matched per eye from the prescription.
        abort_unless($product->lens_specs === null, 404);
        $this->lens_optical_product_id = $product->id;
        $this->lens_fulfilment_source = 'catalogue';
        $this->lens_price = $product->selling_price;
    }

    /**
     * A remake re-does the lenses of ready or collected glasses: same customer
     * and prescription, the customer's existing frame, a fresh stock check.
     */
    private function loadRemake(int $id): void
    {
        $order = LensOrder::with('patient')->whereIn('status', ['Ready for Collection', 'Ready', 'Collected'])->findOrFail($id);
        $this->fillFromOrder($order);
        $this->remakeOfId = $order->id;
        $this->service_lines = [];
        $this->glazing_fee = 0;
        $this->discount_amount = 0;
        $this->frame_source = 'customer';
        $this->frame_product_id = $this->frame_optical_product_id = null;
        $this->frame_price = 0;
        $this->frame_model_number = trim(($order->frame_model_number ?: 'Frame').' (from order '.$order->order_id.')');
        $this->lens_fulfilment_source = 'external';
        $this->stock_lens_key = '';
        $this->lensAvailability = $this->stockLensOptions = [];
        $this->notes = '';
        $this->reference = 'Remake of '.$order->order_id;
    }

    public function updatedRemakeReason(): void
    {
        if (! $this->remakeOfId || ! array_key_exists($this->remake_reason, LensOrder::REMAKE_REASONS)) return;
        $original = LensOrder::find($this->remakeOfId);
        // Suggest: our mistakes are free; defects and breakage are free under warranty.
        $free = $this->remake_reason === 'lab_error'
            || (in_array($this->remake_reason, ['defect', 'breakage'], true) && $original?->isUnderWarranty());
        $this->remake_charge = $free ? 'free' : 'charged';
    }

    public function remakeIsFree(): bool
    {
        return $this->remakeOfId !== null && $this->remake_charge === 'free';
    }

    private function loadQuotation(int $id): void
    {
        $order = LensOrder::with('patient')->findOrFail($id);
        abort_unless($order->status === 'Quotation', 404);
        $this->editingQuotationId = $order->id;
        $this->fillFromOrder($order);
    }

    private function fillFromOrder(LensOrder $order): void
    {
        $this->order_source = $order->order_source ?: 'in_clinic';
        if ($order->customer) $this->choosePatient($order->customer->id);
        else {
            $this->customer_name = $order->customer_name ?? '';
            $this->customer_phone = $order->customer_phone ?? '';
        }
        $this->order_source = $order->order_source ?: 'in_clinic';
        $this->partner_id = $order->partner_clinic_id;
        $this->optical_prescription_id = $order->optical_prescription_id;
        $this->fillMeasurements($order->prescription_snapshot ?? []);
        $this->frame_product_id = $order->frame_product_id;
        $this->lens_product_id = $order->lens_product_id;
        $this->frame_optical_product_id = $order->frame_optical_product_id;
        $this->lens_optical_product_id = $order->lens_optical_product_id;
        $this->frame_model_number = $order->frame_model_number;
        $this->frame_price = $order->frame_price;
        $this->lens_price = $order->lens_price;
        $this->glazing_fee = $order->glazing_fee;
        $this->discount_amount = $order->discount_amount;
        $this->pickUpDate = $order->pickUpDate;
        $details = json_decode($order->notes ?? '', true);
        $details = is_array($details) ? $details : [];
        $this->lens_type = data_get($details, 'lens_details.type', $this->lens_type);
        $this->lens_index = data_get($details, 'lens_details.index', $this->lens_index);
        $this->progressive_brand = data_get($details, 'lens_details.brand', $this->progressive_brand);
        $this->base_color = data_get($details, 'lens_details.color', $this->base_color);
        $this->lens_coatings = data_get($details, 'lens_details.coatings', $this->lens_coatings);
        $this->optical_category_id = data_get($details, 'lens_details.category_id');
        $this->stock_coating = data_get($details, 'lens_details.stock_coating', $this->stock_coating);
        $this->stock_lens_key = (string) data_get($details, 'lens_details.stock_key', '');
        $this->lens_fulfilment_source = $order->lens_supply_source === 'stock'
            ? 'stock'
            : ($order->lens_supply_source === 'customer' ? 'customer' : ($order->lens_optical_product_id ? 'catalogue' : 'external'));
        $this->pd_right = data_get($details, 'fitting.pd_right', '');
        $this->pd_left = data_get($details, 'fitting.pd_left', '');
        $this->fitting_height = data_get($details, 'fitting.fitting_height', '');
        $this->segment_height = data_get($details, 'fitting.segment_height', '');
        $this->lab_instructions = data_get($details, 'lab.instructions', '');
        $this->frame_structure = data_get($details, 'frame_structure', $this->frame_structure);
        $this->frame_source = data_get($details, 'frame_source', ($order->frame_product_id || $order->frame_optical_product_id) ? 'stock' : 'custom');
        $this->notes = data_get($details, 'notes', '');
        $this->deposit_reference = data_get($details, 'deposit_reference', '');
        $this->reference = data_get($details, 'reference', '');
        $this->work_type = $order->work_type ?? 'prescription';
        $this->bill_to = $order->bill_to ?? 'customer';
        $this->partner_clinic_name = $order->partner_clinic_name ?? '';
        $this->service_lines = $order->serviceLines->filter(fn ($line) => $line->optical_service_id)->map(fn ($line) => [
            'service_id' => $line->optical_service_id, 'quantity' => $line->quantity,
        ])->values()->all();
    }

    public function toggleService(int $id): void
    {
        OpticalService::where('is_active', true)->findOrFail($id);
        $index = collect($this->service_lines)->search(fn ($line) => (int) ($line['service_id'] ?? 0) === $id);
        if ($index !== false) $this->removeServiceLine((int) $index);
        else $this->service_lines[] = ['service_id' => $id, 'quantity' => 1];
    }

    public function addService(int $id): void
    {
        OpticalService::where('is_active', true)->findOrFail($id);
        if (collect($this->service_lines)->contains(fn ($line) => (int) ($line['service_id'] ?? 0) === $id)) return;
        $this->service_lines[] = ['service_id' => $id, 'quantity' => 1];
        $this->resetValidation('service_lines');
    }

    public function removeServiceLine(int $index): void
    {
        unset($this->service_lines[$index]);
        $this->service_lines = array_values($this->service_lines);
    }

    private function selectedServices()
    {
        return OpticalService::whereIn('id', collect($this->service_lines)->pluck('service_id'))->where('is_active', true)->get();
    }

    private function needsRx(): bool
    {
        return $this->work_type === 'prescription' || $this->selectedServices()->contains(fn ($service) => $service->requires_rx);
    }

    private function needsFrame(): bool
    {
        return $this->work_type === 'prescription' || $this->selectedServices()->contains(fn ($service) => $service->requires_frame);
    }

    private function nextApplicableStep(int $from, int $direction): int
    {
        $step = $from;
        do {
            $step = max(1, min(7, $step + $direction));
        } while (($step === 2 && ! $this->needsRx()) || ($step === 3 && ! $this->needsFrame()));
        return $step;
    }

    public function setStep($step)
    {
        $target = max(1, min(7, (int) $step));
        if (($target === 2 && ! $this->needsRx()) || ($target === 3 && ! $this->needsFrame())) return;

        if ($target <= $this->currentStep) {
            $this->resetValidation();
            $this->currentStep = $target;
            return;
        }

        // Forward navigation must pass the same validation and stock checks as
        // the Next button. Do not let the step bar bypass required data.
        if ($target === $this->nextApplicableStep($this->currentStep, 1)) {
            $this->nextStep();
        }
    }

    public function nextStep()
    {
        if ($this->currentStep === 1) {
            $rules = [
                'order_source' => 'required|in:in_clinic,partner,walk_in',
                'patient_id' => $this->order_source === 'in_clinic' ? 'required|integer' : 'nullable',
                'partner_id' => $this->order_source === 'partner' ? 'required|integer' : 'nullable',
                'customer_name' => $this->order_source === 'walk_in' || ($this->order_source === 'partner' && $this->bill_to === 'customer') ? 'required|string|max:255' : 'nullable|string|max:255',
                'customer_phone' => 'nullable|string|max:50',
                'reference' => $this->order_source === 'partner' && ! trim((string) $this->customer_name) ? 'required|string|max:255' : 'nullable|string|max:255',
                'work_type' => 'required|in:prescription,service',
                'bill_to' => 'required|in:customer,partner',
                'partner_clinic_name' => $this->bill_to === 'partner' && $this->order_source === 'in_clinic' ? 'required|string|max:255' : 'nullable',
                'service_lines' => $this->work_type === 'service' ? 'required|array|min:1' : 'array',
            ];
            if ($this->work_type === 'service') $rules += [
                'service_lines.*.service_id' => [
                    'required', 'integer', Rule::exists('optical_services', 'id')->where('clinic_id', app(TenantContext::class)->clinicId())->where('is_active', true),
                ],
                'service_lines.*.quantity' => 'required|integer|min:1|max:1000',
            ];
            if ($this->remakeOfId) $rules += [
                'remake_reason' => ['required', Rule::in(array_keys(LensOrder::REMAKE_REASONS))],
                'remake_charge' => 'required|in:free,charged',
            ];
            $this->validate($rules, [
                'remake_reason.required' => 'Choose why the glasses are being remade.',
                'remake_charge.required' => 'Choose whether this remake is free or charged.',
            ]);
        }
        if ($this->currentStep === 2 && $this->needsRx()) {
            $this->validateRxMeasurements();
            if ($this->work_type === 'prescription') {
                $this->validate([
                    'lens_fulfilment_source' => 'required|in:stock,external,customer',
                    'stock_lens_key' => $this->lens_fulfilment_source === 'stock' ? 'required|string' : 'nullable',
                ]);
                if ($this->lens_fulfilment_source === 'stock') $this->selectStockLensOption($this->stock_lens_key);
                if ($this->lens_fulfilment_source === 'stock' && ! $this->stockLensUsable()) {
                    throw ValidationException::withMessages([
                        'lens_stock' => 'Neither lens is in stock for this design. Choose special order or customer supplied lenses to continue.',
                    ]);
                }
            }
        }
        if ($this->currentStep === 3 && $this->needsFrame()) {
            $this->normalizeFrameSelection();
            $this->validate([
                'frame_source' => 'required|in:stock,customer,custom',
                'frame_optical_product_id' => $this->frame_source === 'stock' ? 'required|integer' : 'nullable',
                'frame_model_number' => 'required|string|max:255',
                'frame_price' => 'required|numeric|min:0',
            ]);
        }
        if ($this->currentStep === 5) $this->validatePricing();
        $this->currentStep = $this->nextApplicableStep($this->currentStep, 1);
    }

    /** Discount is optional; a blank field means no discount. */
    private function normalizeDiscount(): void
    {
        if (trim((string) $this->discount_amount) === '') $this->discount_amount = 0;
    }

    private function validatePricing(): void
    {
        $this->normalizeDiscount();
        $this->validate([
            'service_lines' => $this->work_type === 'service' ? 'required|array|min:1|max:20' : 'array|max:20',
            'service_lines.*.service_id' => [
                'required', 'integer', Rule::exists('optical_services', 'id')->where('clinic_id', app(TenantContext::class)->clinicId())->where('is_active', true),
            ],
            'service_lines.*.quantity' => 'required|integer|min:1|max:1000',
            'discount_amount' => 'nullable|numeric|min:0',
        ], [
            'service_lines.*.quantity.*' => 'Enter a whole-number quantity from 1 to 1000 for each service.',
        ]);
        if ((float) $this->discount_amount > $this->priceBreakdown()['subtotal']) {
            throw ValidationException::withMessages(['discount_amount' => 'Discount cannot be more than the order subtotal.']);
        }
    }

    private function normalizeFrameSelection(): void
    {
        if ($this->frame_source === 'customer') {
            $this->frame_product_id = null;
            $this->frame_optical_product_id = null;
            $this->frame_price = 0;
            $this->frame_model_number = trim((string) $this->frame_model_number) ?: 'Customer-supplied frame';
        } elseif ($this->frame_source === 'custom') {
            $this->frame_product_id = null;
            $this->frame_optical_product_id = null;
        }
    }

    private function validateRxMeasurements(): void
    {
        $rules = [
            'rx_od_axis' => [filled($this->rx_od_cyl) ? 'required' : 'nullable', 'integer', 'between:0,180'],
            'rx_os_axis' => [filled($this->rx_os_cyl) ? 'required' : 'nullable', 'integer', 'between:0,180'],
        ];
        $messages = [
            'rx_od_axis.required' => 'Enter a right-eye axis when cylinder is entered.',
            'rx_os_axis.required' => 'Enter a left-eye axis when cylinder is entered.',
            'rx_od_axis.between' => 'Right-eye axis must be between 0 and 180.',
            'rx_os_axis.between' => 'Left-eye axis must be between 0 and 180.',
        ];

        // Saved prescriptions and authorized refractions are read-only here;
        // manually entered powers must be clinically plausible before stock
        // matching or moving on, not only when the order is finally saved.
        if (! $this->optical_prescription_id && ! $this->refraction_id) {
            $quarterStep = function (string $attribute, $value, \Closure $fail) {
                if (is_numeric($value) && fmod(abs((float) $value) * 100, 25) != 0) $fail('The :attribute must be in 0.25 steps.');
            };
            foreach (['od' => 'Right-eye', 'os' => 'Left-eye'] as $eye => $label) {
                $rules += [
                    "rx_{$eye}_sph" => ['required', 'numeric', 'between:-30,30', $quarterStep],
                    "rx_{$eye}_cyl" => ['nullable', 'numeric', 'between:-15,15', $quarterStep],
                    "rx_{$eye}_add" => ['nullable', 'numeric', 'between:0,8', $quarterStep],
                    "rx_{$eye}_hgt" => ['nullable', 'numeric', 'between:0,60'],
                    "rx_{$eye}_pd" => ['nullable', 'numeric', 'between:20,80'],
                ];
                $messages += [
                    "rx_{$eye}_sph.required" => "Enter the {$label} sphere (SPH).",
                    "rx_{$eye}_sph.between" => "{$label} SPH must be between -30.00 and +30.00.",
                    "rx_{$eye}_cyl.between" => "{$label} CYL must be between -15.00 and +15.00.",
                    "rx_{$eye}_add.between" => "{$label} ADD must be between 0.00 and +8.00.",
                    "rx_{$eye}_hgt.between" => "{$label} height must be between 0 and 60 mm.",
                    "rx_{$eye}_pd.between" => "{$label} PD must be between 20 and 80 mm.",
                ];
            }
        }

        $this->validate($rules, $messages, [
            'rx_od_sph' => 'right-eye SPH', 'rx_os_sph' => 'left-eye SPH',
            'rx_od_cyl' => 'right-eye CYL', 'rx_os_cyl' => 'left-eye CYL',
            'rx_od_add' => 'right-eye ADD', 'rx_os_add' => 'left-eye ADD',
            'rx_od_hgt' => 'right-eye height', 'rx_os_hgt' => 'left-eye height',
            'rx_od_pd' => 'right-eye PD', 'rx_os_pd' => 'left-eye PD',
        ]);
    }

    public function updated($property): void
    {
        if (str_starts_with($property, 'rx_') || in_array($property, ['lens_type', 'lens_index', 'stock_coating', 'base_color', 'progressive_brand'], true)) {
            $this->lensAvailability = [];
            if (str_starts_with($property, 'rx_')) {
                $this->stockLensOptions = [];
                $this->stock_lens_key = '';
            }
        }
        if ($property === 'lens_fulfilment_source' && $this->lens_fulfilment_source !== 'catalogue') $this->lens_optical_product_id = null;
        if ($property === 'lens_fulfilment_source' && $this->lens_fulfilment_source === 'customer') $this->lens_price = 0;
    }

    private function currentMeasurements(): array
    {
        return [
            'od' => ['sph' => $this->rx_od_sph, 'cyl' => $this->rx_od_cyl, 'axis' => $this->rx_od_axis, 'add' => $this->rx_od_add, 'hgt' => $this->rx_od_hgt, 'pd' => $this->rx_od_pd],
            'os' => ['sph' => $this->rx_os_sph, 'cyl' => $this->rx_os_cyl, 'axis' => $this->rx_os_axis, 'add' => $this->rx_os_add, 'hgt' => $this->rx_os_hgt, 'pd' => $this->rx_os_pd],
        ];
    }

    private function applyLensStepInput(array $input): void
    {
        foreach (['od', 'os'] as $eye) {
            foreach (['sph', 'cyl', 'axis', 'add', 'hgt', 'pd'] as $field) {
                $property = "rx_{$eye}_{$field}";
                $this->{$property} = trim((string) data_get($input, "{$eye}.{$field}", ''));
            }
        }

        $source = (string) ($input['fulfilment'] ?? '');
        abort_unless(in_array($source, ['stock', 'external', 'customer'], true), 422);
        $this->lens_fulfilment_source = $source;
        if ($source === 'customer') $this->lens_price = 0;
        elseif ($source === 'external') $this->lens_price = max(0, (float) ($input['lens_price'] ?? 0));
    }

    public function checkLensAvailabilityFromClient(array $input): void
    {
        $input['fulfilment'] = 'stock';
        $this->applyLensStepInput($input);
        $this->checkLensAvailability();
    }

    public function nextStepWithLens(array $input): void
    {
        $this->applyLensStepInput($input);
        $this->nextStep();
    }

    public function checkLensAvailability(): void
    {
        if ($this->work_type !== 'prescription') return;
        $this->validateRxMeasurements();
        $this->stockLensOptions = app(OpticalLensAvailabilityService::class)->stockOptions($this->currentMeasurements());
        $this->stock_lens_key = '';
        $this->lensAvailability = [];
        $usable = collect($this->stockLensOptions)->whereIn('status', ['available', 'partial']);
        if ($usable->count() === 1) {
            $this->selectStockLensOption($usable->first()['key']);
        } elseif ($usable->isEmpty()) {
            $this->lensAvailability = [
                'status' => 'outside_sourcing',
                'message' => $this->noStockMessage($this->currentMeasurements()),
                'eyes' => [],
            ];
        }
    }

    /** Explains why nothing matched, and points reading-only prescriptions at single vision stock. */
    private function noStockMessage(array $measurements): string
    {
        $num = fn ($value) => is_numeric($value) ? (float) $value : 0.0;
        $power = fn ($value) => sprintf('%+.2f', $value);
        $adds = array_filter([$num($measurements['od']['add'] ?? ''), $num($measurements['os']['add'] ?? '')]);
        if (! $adds) {
            return 'Neither eye\'s lens power is in single vision stock at this branch. Choose special order.';
        }

        $addText = count(array_unique(array_map($power, $adds))) === 1 ? 'ADD '.$power(reset($adds)) : 'This ADD';
        $hasEyeSpecificStock = OpticalProduct::whereNotNull('lens_specs')->where('is_active', true)
            ->whereIn('lens_specs->design', \App\Support\LensDesign::EYE_SPECIFIC)
            ->whereHas('stocks', fn ($stock) => $stock->where('quantity', '>', 0))->exists();
        $message = $hasEyeSpecificStock
            ? "{$addText} needs a progressive or bifocal lens, and none are in stock at this branch for these powers. Choose special order."
            : "{$addText} needs a progressive or bifocal lens, and this branch has no progressive or bifocal lenses in stock. Choose special order.";

        // Reading glasses: the near power is SPH + ADD in a single vision lens. Stock
        // multifocals and this shortcut both need a prescription without cylinder.
        if ($num($measurements['od']['cyl'] ?? '') == 0 && $num($measurements['os']['cyl'] ?? '') == 0) {
            $reading = [];
            foreach (['od', 'os'] as $eye) {
                $reading[$eye] = ['sph' => $power($num($measurements[$eye]['sph'] ?? '') + $num($measurements[$eye]['add'] ?? '')), 'cyl' => '', 'add' => ''];
            }
            $label = 'SPH '.$reading['od']['sph'].($reading['os']['sph'] !== $reading['od']['sph'] ? ' (R) / '.$reading['os']['sph'].' (L)' : '');
            $inStock = collect(app(OpticalLensAvailabilityService::class)->stockOptions($reading))->contains('status', 'available');
            $message .= " If these are reading glasses only, enter {$label} with no ADD to use single vision lenses"
                .($inStock ? ', which are in stock.' : ' (not currently in stock either).');
        }

        return $message;
    }

    private function stockLensUsable(): bool
    {
        return in_array($this->lensAvailability['status'] ?? null, ['available', 'partial'], true);
    }

    public function selectStockLensOption(string $key): void
    {
        $options = app(OpticalLensAvailabilityService::class)->stockOptions($this->currentMeasurements());
        $option = collect($options)->firstWhere('key', $key);
        if (! $option) {
            $this->stockLensOptions = $options;
            $this->stock_lens_key = '';
            $this->lensAvailability = [];
            throw ValidationException::withMessages(['lens_stock' => 'The prescription or stock changed. Check available lens stock again.']);
        }

        $this->stockLensOptions = $options;
        $this->stock_lens_key = $key;
        $this->lens_fulfilment_source = 'stock';
        $this->lens_type = $option['lens_type'];
        $this->lens_index = $option['index'];
        $this->stock_coating = $option['coating'];
        $this->base_color = $option['coating'] === 'Transitions' ? 'Photochromic' : 'White';
        $this->lens_price = $option['price'];
        $this->lensAvailability = $this->availabilitySummary($option);
    }

    private function availabilitySummary(array $option): array
    {
        $eyes = [];
        foreach ($option['eyes'] ?? [] as $eye => $line) {
            $eyes[$eye] = [
                'sphere' => $line['sphere'], 'cylinder' => $line['power'], 'source' => $line['source'],
                'power_type' => $line['power_type'] ?? 'cyl', 'reason' => $line['reason'] ?? null,
                'available' => $line['free'], 'unit_price' => $line['unit_price'], 'price_estimated' => $line['price_estimated'],
            ];
        }
        $ordered = collect($eyes)->where('source', 'special_order')->keys()->map(fn ($eye) => strtoupper($eye));

        return [
            'status' => $option['status'] === 'none' ? 'outside_sourcing' : $option['status'],
            'message' => match ($option['status']) {
                'available' => 'Both lenses are in stock at this branch.',
                'partial' => 'Half pair in stock. The '.$ordered->implode(' and ').' lens will be special-ordered in the same design.',
                default => 'This lens design does not have enough stock for both eyes.',
            },
            'split' => $option['split'] ?? null,
            'eyes' => $eyes,
        ];
    }

    public function prevStep()
    {
        $this->currentStep = $this->nextApplicableStep($this->currentStep, -1);
    }

    /**
     * Itemised charges shown on the Pricing and Review steps. Mirrors how
     * OpticalOrderService prices the order so the customer sees what is saved.
     */
    public function priceBreakdown(): array
    {
        $lines = [];
        if ($this->needsFrame() && $this->frame_source !== 'customer') {
            $frame = (float) ($this->frame_optical_product_id ? OpticalProduct::find($this->frame_optical_product_id)?->selling_price : $this->frame_price);
            $lines[] = ['label' => 'Frame'.($this->frame_model_number ? ' · '.$this->frame_model_number : ''), 'amount' => $frame];
        }
        if ($this->work_type === 'prescription') {
            $eyes = $this->lens_fulfilment_source === 'stock' ? ($this->lensAvailability['eyes'] ?? []) : [];
            if ($eyes && isset(reset($eyes)['unit_price'])) {
                foreach ($eyes as $eye => $line) {
                    $lines[] = ['label' => strtoupper($eye).' lens · '.(($line['source'] ?? 'stock') === 'stock' ? 'branch stock' : 'special order'), 'amount' => (float) $line['unit_price']];
                }
            } elseif ($this->lens_optical_product_id) {
                $lines[] = ['label' => 'Lenses (pair)', 'amount' => (float) OpticalProduct::find($this->lens_optical_product_id)?->selling_price];
            } elseif ($this->lens_fulfilment_source === 'customer') {
                $lines[] = ['label' => 'Lenses · customer supplied', 'amount' => 0.0];
            } else {
                $lines[] = ['label' => 'Lenses (pair)'.($this->lens_fulfilment_source === 'stock' ? ' · branch stock' : ' · special order'), 'amount' => (float) $this->lens_price];
            }
        }
        $catalogue = $this->selectedServices()->keyBy('id');
        foreach ($this->service_lines as $index => $line) {
            $service = $catalogue->get((int) ($line['service_id'] ?? 0));
            if (! $service) continue;
            $quantity = max(0, (int) ($line['quantity'] ?? 0));
            // name, unit and service_index let the page recount the line as the quantity is typed.
            $lines[] = ['label' => $service->name.($quantity !== 1 ? ' × '.$quantity : ''), 'amount' => round($quantity * (float) $service->price, 2),
                'name' => $service->name, 'unit' => (float) $service->price, 'service_index' => $index];
        }
        if ((float) $this->glazing_fee > 0) {
            $lines[] = ['label' => 'Glazing fee (from earlier quotation)', 'amount' => (float) $this->glazing_fee];
        }
        if ($this->remakeIsFree()) {
            $lines = array_map(fn ($line) => ['label' => $line['label'], 'amount' => 0.0], $lines);
            $lines[] = ['label' => 'Free remake (no charge)', 'amount' => 0.0];
        }
        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);

        return [
            'lines' => $lines,
            'free' => $this->remakeIsFree(),
            'subtotal' => $subtotal,
            'discount' => $this->remakeIsFree() ? 0.0 : (float) $this->discount_amount,
            'total' => $this->remakeIsFree() ? 0.0 : max(0, round($subtotal - (float) $this->discount_amount, 2)),
        ];
    }

    public function calculateTotalProperty()
    {
        return $this->priceBreakdown()['total'];
    }

    public function calculateBalanceProperty()
    {
        return max(0, $this->calculateTotalProperty() - (float)$this->paid_amount);
    }

    public function saveAsQuotation()
    {
        abort_if($this->remakeOfId !== null, 422, 'Place a remake as an order, not a quotation.');
        $this->saveOrder('Quotation');
        session()->flash('success', $this->editingQuotationId ? 'Optical quotation updated.' : 'Optical quotation created.');
        return redirect()->route('optical.orders');
    }

    public function createOrder()
    {
        if ($this->editingQuotationId) {
            $onAccount = $this->order_source === 'partner' && OpticalPartnerClinic::find($this->partner_id)?->billing_terms === 'on_account';
            $minimum = $onAccount ? 0 : (int) (OpticalSetting::first()?->min_deposit_percentage ?? 0);
            if ((float) $this->paid_amount < round($this->calculateTotalProperty() * $minimum / 100, 2)) {
                throw ValidationException::withMessages(['paid_amount' => "A {$minimum}% deposit is required."]);
            }
            DB::transaction(function () {
                $this->saveOrder('Quotation');
                app(OpticalOrderWorkflowService::class)->activateQuotation($this->editingQuotationId, (float) $this->paid_amount, $this->payment_method);
            });
        } else {
            $this->saveOrder('Pending');
        }
        session()->flash('success', 'Optical Order created successfully!');
        return redirect()->route('optical.orders');
    }

    protected function saveOrder($status = 'Pending')
    {
        if ($this->needsFrame()) $this->normalizeFrameSelection();
        if ($this->needsRx()) $this->validateRxMeasurements();
        if ($this->work_type === 'prescription' && $this->lens_fulfilment_source === 'stock') {
            $this->validate(['stock_lens_key' => 'required|string']);
            $this->selectStockLensOption($this->stock_lens_key);
            if (! $this->stockLensUsable()) {
                throw ValidationException::withMessages(['lens_stock' => 'These lenses are no longer in stock. Check availability again or choose special order.']);
            }
        }
        $this->normalizeDiscount();
        $this->validate([
            'order_source' => 'required|in:in_clinic,partner,walk_in',
            'patient_id' => $this->order_source === 'in_clinic'
                ? ['required', Rule::exists('patients', 'id')->where('clinic_id', app(TenantContext::class)->clinicId())] : 'nullable',
            'partner_id' => $this->order_source === 'partner'
                ? ['required', Rule::exists('optical_partner_clinics', 'id')->where('clinic_id', app(TenantContext::class)->clinicId())->where('is_active', true)] : 'nullable',
            'customer_name' => $this->order_source === 'walk_in' || ($this->order_source === 'partner' && $this->bill_to === 'customer') ? 'required|string|max:255' : 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'work_type' => 'required|in:prescription,service',
            'bill_to' => 'required|in:customer,partner',
            'partner_clinic_name' => $this->bill_to === 'partner' && $this->order_source === 'in_clinic' ? 'required|string|max:255' : 'nullable',
            'service_lines' => $this->work_type === 'service' ? 'required|array|min:1' : 'array',
            'frame_source' => $this->needsFrame() ? 'required|in:stock,customer,custom' : 'nullable',
            'frame_optical_product_id' => $this->needsFrame() && $this->frame_source === 'stock' ? 'required|integer' : 'nullable',
            'frame_optical_product_id' => $this->frame_optical_product_id ? ['integer', Rule::exists('optical_products', 'id')->where('clinic_id', app(TenantContext::class)->clinicId())->where('is_active', true)->whereNull('deleted_at')] : 'nullable',
            'lens_optical_product_id' => $this->lens_optical_product_id ? ['integer', Rule::exists('optical_products', 'id')->where('clinic_id', app(TenantContext::class)->clinicId())->where('is_active', true)->whereNull('deleted_at')] : 'nullable',
            'frame_model_number' => $this->needsFrame() ? 'required|string|max:255' : 'nullable',
            'frame_price' => 'required|numeric|min:0',
            'lens_price' => 'required|numeric|min:0',
            'optical_category_id' => ['nullable', Rule::exists('optical_categories', 'id')->where('clinic_id', app(TenantContext::class)->clinicId())->where('is_active', true)],
            'glazing_fee' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'paid_amount' => 'required|numeric|min:0',
            'pickUpDate' => 'required|date',
            'reference' => $this->order_source === 'partner' && ! trim((string) $this->customer_name) ? 'required|string|max:255' : 'nullable|string|max:255',
            'payment_method' => ['required', Rule::in(['cash', 'momo', 'card', 'bank_transfer'])],
        ]);

        $data = [
            'patient_id' => $this->order_source === 'in_clinic' ? $this->patient_id : null,
            'order_source' => $this->order_source,
            'partner_id' => $this->order_source === 'partner' ? $this->partner_id : null,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'work_type' => $this->work_type,
            'services' => $this->service_lines,
            'remake' => $this->remakeOfId ? ['of' => $this->remakeOfId, 'reason' => $this->remake_reason, 'charge' => $this->remake_charge] : null,
            'bill_to' => $this->bill_to,
            'partner_clinic_name' => trim($this->partner_clinic_name) ?: null,
            'optical_prescription_id' => $this->order_source === 'in_clinic' ? $this->optical_prescription_id : null,
            'refraction_id' => $this->order_source === 'in_clinic' ? $this->refraction_id : null,
            'measurements' => $this->currentMeasurements(),
            'frame_product_id' => $this->needsFrame() ? ($this->frame_product_id ?: null) : null,
            'lens_product_id' => $this->work_type === 'prescription' && $this->lens_fulfilment_source !== 'stock' ? ($this->lens_product_id ?: null) : null,
            'frame_optical_product_id' => $this->needsFrame() && $this->frame_source === 'stock' ? ($this->frame_optical_product_id ?: null) : null,
            'lens_optical_product_id' => $this->work_type === 'prescription' && $this->lens_fulfilment_source === 'catalogue' ? ($this->lens_optical_product_id ?: null) : null,
            'lens_fulfilment_source' => $this->work_type === 'prescription' ? $this->lens_fulfilment_source : 'external',
            'frame_model_number' => $this->needsFrame() ? $this->frame_model_number : null,
            'frame_price' => $this->needsFrame() ? $this->frame_price : 0, 'lens_price' => $this->work_type === 'prescription' ? $this->lens_price : 0,
            'glazing_fee' => $this->glazing_fee, 'discount_amount' => $this->discount_amount,
            'paid_amount' => $this->paid_amount, 'payment_method' => $this->payment_method,
            'pickup_date' => $this->pickUpDate,
            'docket' => [
                'lens_details' => ['category_id' => $this->work_type === 'prescription' ? $this->optical_category_id : null, 'category_name' => $this->optical_category_id ? OpticalCategory::find($this->optical_category_id)?->name : null, 'type' => $this->lens_type, 'index' => $this->lens_index, 'brand' => $this->progressive_brand, 'color' => $this->base_color, 'coatings' => $this->lens_coatings, 'stock_coating' => $this->stock_coating, 'stock_key' => $this->lens_fulfilment_source === 'stock' ? $this->stock_lens_key : null, 'stock_split' => $this->lens_fulfilment_source === 'stock' ? ($this->lensAvailability['split'] ?? null) : null],
                'fitting' => ['pd_right' => $this->pd_right, 'pd_left' => $this->pd_left, 'fitting_height' => $this->fitting_height, 'segment_height' => $this->segment_height],
                'lab' => ['instructions' => $this->lab_instructions],
                'frame_structure' => $this->frame_structure, 'notes' => $this->notes,
                'frame_source' => $this->frame_source,
                'deposit_reference' => $this->deposit_reference,
                'reference' => $this->reference,
            ],
        ];
        if ($this->editingQuotationId) {
            return app(OpticalOrderService::class)->updateQuotation($this->editingQuotationId, $data);
        }
        return app(OpticalOrderService::class)->create($data, $status === 'Quotation');
    }

    public function render()
    {
        $opticalCategories = OpticalCategory::where('is_active', true)->orderBy('name')->get();
        $frameIds = $opticalCategories->filter(fn ($category) => $category->group === 'frames')->pluck('id');
        $lensCategories = $opticalCategories->filter(fn ($category) => in_array($category->group, ['single_vision', 'progressive', 'bifocal'], true));
        return view('livewire.optical.optical-order-create-component', [
            'customers' => $this->order_source === 'in_clinic' && strlen(trim($this->customerSearch)) > 0
                ? Patient::where(function ($query) {
                    $term = '%'.trim($this->customerSearch).'%';
                    $query->where('name', 'like', $term)
                        ->orWhere('contact', 'like', $term)
                        ->orWhere('pxnumber', 'like', $term);
                })->orderBy('name')->limit(10)->get()
                : collect(),
            'partners' => OpticalPartnerClinic::where('is_active', true)
                    ->when(strlen(trim($this->customerSearch)) > 0, function ($query) {
                        $term = '%'.trim($this->customerSearch).'%';
                        $query->where(function ($match) use ($term) {
                            $match->where('name', 'like', $term)
                                ->orWhere('phone', 'like', $term)
                                ->orWhere('contact_person', 'like', $term);
                        });
                    })->orderBy('name')->limit(10)->get(),
            'prescriptions' => $this->patient_id ? OpticalPrescription::where('patient_id', $this->patient_id)->latest()->get() : collect(),
            'clinicRefractions' => $this->patient_id && ! \App\Support\OpticalMode::opticalOnly()
                ? Refractions::whereHas('consultation', fn ($q) => $q->where('patient_id', $this->patient_id))
                    ->where('dispensing_required', true)->whereNotNull('dispensing_authorized_at')->latest()->get()
                : collect(),
            'frames' => OpticalProduct::with('stocks')->where('is_active', true)->whereIn('optical_category_id', $frameIds)->orderBy('name')->get(),
            // SKU choices belong only to the finished/catalogued-lens workflow.
            // Branch lens blanks are matched directly from the Rx specification on Next.
            'lenses' => $this->lens_fulfilment_source === 'catalogue'
                ? OpticalProduct::with('stocks')->where('is_active', true)->whereNull('lens_specs')->whereIn('optical_category_id', $lensCategories->pluck('id'))->orderBy('name')->get()
                : collect(),
            'opticalCategories' => $lensCategories,
            'serviceCatalogue' => OpticalService::where('is_active', true)->orderBy('name')->get(),
            'nextStep' => $this->nextApplicableStep($this->currentStep, 1),
            'previousStep' => $this->nextApplicableStep($this->currentStep, -1),
        ])->layout('layouts.optical');
    }
}
