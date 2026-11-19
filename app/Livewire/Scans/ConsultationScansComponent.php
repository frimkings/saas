<?php

namespace App\Livewire\Scans;

use App\Models\DiagnosticScan;
use App\Models\Patient;
use App\Models\ScanOrder;
use App\Models\ScanOrderItem;
use App\Services\ClinicAccessService;
use App\Services\LicenseService;
use App\Services\Scans\ScanOrderService;
use App\Support\Feature;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Inside the consultation: the doctor orders scans for the patient. The order goes to the Scan
 * Desk as one of ours (the doctor earns the commission once the patient pays) and waits there to
 * be billed. The doctor can withdraw their own order until it is billed.
 */
class ConsultationScansComponent extends Component
{
    public int $patientId;

    #[Reactive]
    public ?int $consultationId = null;

    public bool $open = false;

    /** scanId => ['on' => bool, 'eye' => 'OU'|'OD'|'OS'] */
    public array $chosen = [];

    public string $note = '';

    public static function allowed(): bool
    {
        $user = Auth::user();

        return $user !== null && LicenseService::has(Feature::DIAGNOSTIC_SCANS) && \App\Support\Access\Access::allows($user, 'clinical.records');
    }

    public function mount(int $patientId, ?int $consultationId = null): void
    {
        abort_unless(self::allowed(), 403);
        $this->patientId = Patient::query()->findOrFail($patientId)->id;
        $this->consultationId = $consultationId;
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
        $this->reset(['chosen', 'note']);
        $this->resetErrorBag();
    }

    public function order(): void
    {
        abort_unless(self::allowed(), 403);
        app(ClinicAccessService::class)->assertWritable(Feature::DIAGNOSTIC_SCANS);
        $this->resetErrorBag();
        $this->validate(['note' => 'nullable|string|max:500']);

        $items = collect($this->chosen)->filter(fn ($pick) => ! empty($pick['on']))
            ->map(fn ($pick, $scanId) => ['diagnostic_scan_id' => (int) $scanId, 'eye' => $pick['eye'] ?? 'OU'])->values()->all();

        try {
            $order = app(ScanOrderService::class)->create([
                'patient_id' => $this->patientId, 'source' => 'internal', 'ordered_by' => Auth::id(),
                'consultation_id' => $this->consultationId, 'notes' => trim($this->note) ?: null, 'items' => $items,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) $this->addError('items', $messages[0]);
            return;
        }

        $this->open = false;
        $this->reset(['chosen', 'note']);
        $this->dispatch('notify', type: 'success', message: "Scans ordered ({$order->order_number}). The patient pays at the front desk before the scan.");
    }

    /** Only the doctor who ordered it (or the owner), and only before it is billed. */
    public function withdraw(int $orderId): void
    {
        abort_unless(self::allowed(), 403);
        app(ClinicAccessService::class)->assertWritable(Feature::DIAGNOSTIC_SCANS);
        $order = ScanOrder::where('patient_id', $this->patientId)->findOrFail($orderId);
        abort_unless($order->source === 'internal' && ((int) $order->ordered_by === Auth::id() || \App\Support\Access\Access::can('records.delete')), 403);
        try {
            app(ScanOrderService::class)->cancel($order, 'Withdrawn by the doctor from the consultation');
        } catch (ValidationException $e) {
            $this->addError('withdraw', collect($e->errors())->flatten()->first());
            return;
        }
        $this->dispatch('notify', type: 'success', message: "Scan order {$order->order_number} withdrawn.");
    }

    public function render()
    {
        $orders = ScanOrder::where('patient_id', $this->patientId)->where('created_at', '>=', now()->subDays(90))
            ->with(['items:id,scan_order_id,scan_name,eye', 'orderedBy:id,name', 'partner:id,name', 'sale:id,payment_status'])
            ->latest()->limit(6)->get();

        return view('livewire.scans.consultation-scans-component', [
            'orders' => $orders,
            'scans' => $this->open ? DiagnosticScan::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'price', 'per_eye']) : collect(),
            'eyes' => ScanOrderItem::EYES,
            'statuses' => ScanOrder::STATUSES,
        ]);
    }
}
