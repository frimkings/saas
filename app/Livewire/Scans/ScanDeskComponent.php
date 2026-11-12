<?php

namespace App\Livewire\Scans;

use App\Models\AuditTrail;
use App\Models\DiagnosticScan;
use App\Models\Patient;
use App\Models\ScanOrder;
use App\Models\ScanOrderItem;
use App\Models\ScanPartnerClinic;
use App\Models\ScanPartnerDoctor;
use App\Models\User;
use App\Services\ClinicAccessService;
use App\Services\Scans\ScanOrderService;
use App\Support\Feature;
use App\Support\PaymentMethods;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Diagnostic Scans → Scan Desk: register a patient's scans with who referred them, take the
 * payment, then follow each order through scanned and reported.
 */
class ScanDeskComponent extends Component
{
    use WithPagination;

    /** Work lists: what still needs doing comes first. */
    public const STAGES = ['open' => 'Open', 'unbilled' => 'To bill', 'to_scan' => 'To scan', 'to_report' => 'To report', 'reported' => 'Reported', 'cancelled' => 'Cancelled', 'all' => 'All'];

    public string $stage = 'open';
    public string $search = '';
    public string $sourceFilter = '';
    public ?int $viewOrderId = null;

    // New order
    public bool $showForm = false;
    public string $patientSearch = '';
    public ?int $patientId = null;
    public bool $newPatient = false;
    public string $newName = '';
    public string $newPhone = '';
    public string $newGender = '';
    public string $newDob = '';
    public string $source = 'partner';
    public ?int $partnerId = null;
    public ?int $partnerDoctorId = null;
    public ?int $orderedBy = null;
    public string $referrerNote = '';
    public bool $shareConsent = false;
    public string $notes = '';
    /** @var list<array{diagnostic_scan_id:int, eye:?string}> */
    public array $lines = [];

    // Checkout and cancel, on the open order
    /** @var list<array{method:string, amount:string}> */
    public array $payments = [];
    public string $cancelReason = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'sourceFilter'], true)) $this->resetPage();
        if ($property === 'partnerId') {
            $this->partnerDoctorId = null;
        }
        if ($property === 'source' && $this->source !== 'partner') {
            $this->shareConsent = false;
        }
    }

    public function setStage(string $stage): void
    {
        abort_unless(array_key_exists($stage, self::STAGES), 422);
        $this->stage = $stage;
        $this->resetPage();
    }

    private function writable(): void
    {
        app(ClinicAccessService::class)->assertWritable(Feature::DIAGNOSTIC_SCANS);
    }

    // --- New order -----------------------------------------------------------------------

    public function add(): void
    {
        $this->reset(['patientSearch', 'patientId', 'newPatient', 'newName', 'newPhone', 'newGender', 'newDob', 'partnerId', 'partnerDoctorId',
            'orderedBy', 'referrerNote', 'shareConsent', 'notes', 'lines']);
        $this->source = 'partner';
        $this->viewOrderId = null;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetErrorBag();
    }

    public function pickPatient(int $id): void
    {
        $this->patientId = Patient::findOrFail($id)->id;
        $this->newPatient = false;
        $this->patientSearch = '';
        $this->resetErrorBag('patientId');
    }

    public function clearPatient(): void
    {
        $this->patientId = null;
    }

    public function startNewPatient(): void
    {
        $this->patientId = null;
        $this->newPatient = true;
        // A typed name or phone carries over.
        $term = trim($this->patientSearch);
        if ($term !== '' && $this->newName === '' && $this->newPhone === '') {
            preg_match('/^[\d\s+()-]+$/', $term) ? $this->newPhone = $term : $this->newName = $term;
        }
    }

    public function addLine(int $scanId): void
    {
        $scan = DiagnosticScan::where('is_active', true)->findOrFail($scanId);
        $this->lines[] = ['diagnostic_scan_id' => $scan->id, 'eye' => $scan->per_eye ? 'OU' : null];
        $this->resetErrorBag('items');
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function save(): void
    {
        $this->writable();
        $this->validate([
            'source' => 'required|in:'.implode(',', array_keys(ScanOrder::SOURCES)),
            'referrerNote' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'orderedBy' => $this->source === 'internal' ? 'required|integer' : 'nullable',
            'partnerId' => $this->source === 'partner' ? 'required|integer' : 'nullable',
        ], ['partnerId.required' => 'Choose the partner clinic that referred the patient.', 'orderedBy.required' => 'Choose the doctor who ordered the scans.']);

        if ($this->source === 'internal' && ! $this->doctors()->contains('id', $this->orderedBy)) {
            $this->addError('orderedBy', 'Choose one of the clinic\'s doctors.');
            return;
        }

        if ($this->newPatient) {
            $this->validate([
                'newName' => 'required|string|max:255',
                'newPhone' => 'required|string|max:50',
                'newGender' => 'required|in:Male,Female,Other',
                'newDob' => 'nullable|date|before_or_equal:today',
            ], ['newName.required' => "Enter the patient's name.", 'newPhone.required' => "Enter the patient's phone number.", 'newGender.required' => 'Choose the sex.']);
        } elseif (! $this->patientId || ! Patient::whereKey($this->patientId)->exists()) {
            $this->addError('patientId', 'Find the patient, or register them as new.');
            return;
        }
        if ($this->lines === []) {
            $this->addError('items', 'Add at least one scan.');
            return;
        }

        try {
            $order = \Illuminate\Support\Facades\DB::transaction(function () {
                $patientId = $this->patientId;
                if ($this->newPatient) {
                    $patient = Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => trim($this->newName), 'contact' => trim($this->newPhone),
                        'gender' => $this->newGender, 'dob' => $this->newDob ?: null]);
                    AuditTrail::record('patient.created', "Registered new patient at the scan desk: {$patient->name} ({$patient->pxnumber})", $patient, [], [], $patient->id);
                    $patientId = $patient->id;
                }

                return app(ScanOrderService::class)->create([
                    'patient_id' => $patientId, 'source' => $this->source, 'scan_partner_clinic_id' => $this->partnerId,
                    'scan_partner_doctor_id' => $this->partnerDoctorId, 'ordered_by' => $this->orderedBy, 'referrer_note' => $this->referrerNote,
                    'share_consent' => $this->shareConsent, 'notes' => $this->notes, 'items' => $this->lines,
                ]);
            });
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) $this->addError($field, $messages[0]);
            return;
        }

        $this->showForm = false;
        $this->openOrder($order->id);
        session()->flash('success', "Order {$order->order_number} registered. Take the payment to bill it.");
    }

    // --- The open order ------------------------------------------------------------------

    public function openOrder(int $id): void
    {
        $order = ScanOrder::findOrFail($id);
        $this->viewOrderId = $order->id;
        $this->showForm = false;
        $this->cancelReason = '';
        $this->payments = [['method' => PaymentMethods::first(PaymentMethods::CLINIC), 'amount' => number_format((float) $order->total_amount, 2, '.', '')]];
        $this->resetErrorBag();
    }

    public function closeOrder(): void
    {
        $this->viewOrderId = null;
        $this->resetErrorBag();
    }

    private function order(): ScanOrder
    {
        abort_unless($this->viewOrderId, 404);

        return ScanOrder::findOrFail($this->viewOrderId);
    }

    public function addPayment(): void
    {
        $this->payments[] = ['method' => PaymentMethods::first(PaymentMethods::CLINIC), 'amount' => ''];
    }

    public function removePayment(int $index): void
    {
        unset($this->payments[$index]);
        $this->payments = array_values($this->payments);
    }

    public function checkout(): void
    {
        $this->writable();
        $this->resetErrorBag();
        try {
            $sale = app(ScanOrderService::class)->checkout($this->order(), $this->payments);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) $this->addError($field, $messages[0]);
            return;
        }
        session()->flash('success', $sale->payment_status === 'paid'
            ? 'Paid in full. The patient can go for the scan.'
            : 'Part payment recorded; the balance is on Outstanding Balances. The patient can go for the scan.');
    }

    public function markScanned(): void
    {
        $this->step(fn ($service, $order) => $service->markScanned($order));
    }

    public function markReported(): void
    {
        $this->step(fn ($service, $order) => $service->markReported($order));
    }

    public function undoStep(): void
    {
        $this->step(fn ($service, $order) => $service->undo($order));
    }

    public function cancelOrder(): void
    {
        $this->writable();
        $this->cancelReason = trim($this->cancelReason);
        $this->validate(['cancelReason' => 'required|string|min:3|max:255'], ['cancelReason.required' => 'Say why the order is cancelled.']);
        $this->step(fn ($service, $order) => $service->cancel($order, $this->cancelReason));
        $this->cancelReason = '';
    }

    private function step(callable $action): void
    {
        $this->writable();
        $this->resetErrorBag('status');
        try {
            $action(app(ScanOrderService::class), $this->order());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) $this->addError($field, $messages[0]);
        }
    }

    // --- Render ----------------------------------------------------------------------------

    private function doctors()
    {
        // A clinic that never set up a Doctor role has no doctors to pick (role() throws on a missing role).
        if (! \Spatie\Permission\Models\Role::where('name', 'Doctor')->where('guard_name', 'web')->exists()) return collect();

        return User::role('Doctor')->inCurrentClinic(['active'])->orderBy('name')->get(['id', 'name']);
    }

    public function render()
    {
        $term = trim($this->search);
        $orders = ScanOrder::with(['patient:id,name,pxnumber,contact', 'partner:id,name,code', 'partnerDoctor:id,name', 'orderedBy:id,name', 'sale:id,payment_status,amount_paid,total_amount', 'items'])
            ->when($this->sourceFilter !== '', fn ($q) => $q->where('source', $this->sourceFilter))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('order_number', 'like', "%{$term}%")
                ->orWhereHas('patient', fn ($p) => $p->where('name', 'like', "%{$term}%")->orWhere('pxnumber', 'like', "%{$term}%")->orWhere('contact', 'like', "%{$term}%"))))
            ->when(true, fn ($q) => match ($this->stage) {
                'open' => $q->whereIn('status', ['registered', 'scanned']),
                'unbilled' => $q->where('status', 'registered')->whereNull('sale_id'),
                'to_scan' => $q->where('status', 'registered')->whereNotNull('sale_id'),
                'to_report' => $q->where('status', 'scanned'),
                'reported' => $q->whereIn('status', ['reported', 'sent']),
                'cancelled' => $q->where('status', 'cancelled'),
                default => $q,
            })
            ->latest('id')->paginate(15);

        $base = fn () => ScanOrder::query();
        $counts = [
            'unbilled' => $base()->where('status', 'registered')->whereNull('sale_id')->count(),
            'to_scan' => $base()->where('status', 'registered')->whereNotNull('sale_id')->count(),
            'to_report' => $base()->where('status', 'scanned')->count(),
            'today' => $base()->whereDate('created_at', today())->where('status', '!=', 'cancelled')->count(),
        ];
        $counts['open'] = $counts['unbilled'] + $counts['to_scan'] + $counts['to_report'];

        $view = $this->viewOrderId ? ScanOrder::with(['patient', 'partner', 'partnerDoctor', 'orderedBy', 'sale.paymentTransactions', 'items', 'creator:id,name'])->find($this->viewOrderId) : null;

        $patientResults = collect();
        if ($this->showForm && ! $this->patientId && mb_strlen(trim($this->patientSearch)) >= 2) {
            $like = '%'.trim($this->patientSearch).'%';
            $patientResults = Patient::where(fn ($q) => $q->where('name', 'like', $like)->orWhere('pxnumber', 'like', $like)->orWhere('contact', 'like', $like))
                ->orderBy('name')->limit(8)->get(['id', 'name', 'pxnumber', 'contact', 'dob']);
        }

        $scans = DiagnosticScan::where('is_active', true)->orderBy('name')->get();
        $scanMap = DiagnosticScan::whereIn('id', array_column($this->lines, 'diagnostic_scan_id'))->get()->keyBy('id');
        $formTotal = collect($this->lines)->sum(fn ($line) => (float) ($scanMap[$line['diagnostic_scan_id']]->price ?? 0) * (($line['eye'] ?? null) === 'OU' ? 2 : 1));

        return view('livewire.scans.scan-desk-component', [
            'orders' => $orders,
            'counts' => $counts,
            'viewOrder' => $view,
            'patientResults' => $patientResults,
            'pickedPatient' => $this->patientId ? Patient::find($this->patientId, ['id', 'name', 'pxnumber', 'contact']) : null,
            'scans' => $scans,
            'scanMap' => $scanMap,
            'formTotal' => $formTotal,
            'partners' => $this->showForm ? ScanPartnerClinic::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']) : collect(),
            'partnerDoctors' => $this->showForm && $this->partnerId ? ScanPartnerDoctor::where('scan_partner_clinic_id', $this->partnerId)->where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'doctors' => $this->showForm && $this->source === 'internal' ? $this->doctors() : collect(),
            'methods' => collect(PaymentMethods::keys(PaymentMethods::CLINIC))->mapWithKeys(fn ($key) => [$key => PaymentMethods::label($key, PaymentMethods::CLINIC)]),
            'paymentTotal' => round((float) collect($this->payments)->sum(fn ($p) => (float) ($p['amount'] ?? 0)), 2),
        ]);
    }
}
