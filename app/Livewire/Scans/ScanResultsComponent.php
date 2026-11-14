<?php

namespace App\Livewire\Scans;

use App\Models\ScanOrder;
use App\Services\ClinicAccessService;
use App\Services\Scans\ScanResultsService;
use App\Support\Feature;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Inside a Scan Desk order: the result files, and sending them to the referring clinic when the
 * patient agreed. Files are saved as the patient's clinical documents.
 */
class ScanResultsComponent extends Component
{
    use WithFileUploads;

    public int $orderId;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $resultFiles = [];

    public string $resultType = 'oct';

    public string $resultTitle = '';

    /** Changes when files are saved, so the file input starts empty again. */
    public int $inputKey = 0;

    public function mount(int $orderId): void
    {
        $this->orderId = ScanOrder::findOrFail($orderId)->id;
    }

    /** The Scan Desk moved the order on (billed, scanned, reported): show it fresh. */
    #[\Livewire\Attributes\On('scan-order-step')]
    public function orderStepped(): void {}

    private function order(): ScanOrder
    {
        return ScanOrder::with(['partner', 'items', 'patient', 'documents'])->findOrFail($this->orderId);
    }

    private function writable(): void
    {
        app(ClinicAccessService::class)->assertWritable(Feature::DIAGNOSTIC_SCANS);
    }

    // Not "upload": Livewire uses that name for its own file uploads.
    public function saveResults(): void
    {
        $this->writable();
        $this->resetErrorBag();
        $this->validate([
            'resultFiles' => 'required|array|min:1|max:10',
            'resultFiles.*' => 'file|'.\App\Support\UploadLimits::rule().'|mimes:jpg,jpeg,png,pdf',
            'resultType' => 'required|in:'.implode(',', array_keys(ScanResultsService::TYPES)),
            'resultTitle' => 'nullable|string|max:150',
        ], ['resultFiles.required' => 'Choose the result files.', 'resultFiles.*.mimes' => 'Results must be PDF, JPG or PNG files.'], ['resultFiles.*' => 'file']);

        $order = $this->order();
        $service = app(ScanResultsService::class);
        $saved = 0;
        try {
            foreach (array_values($this->resultFiles) as $i => $file) {
                $title = trim($this->resultTitle) !== '' ? trim($this->resultTitle).(count($this->resultFiles) > 1 ? ' '.($i + 1) : '') : null;
                $service->attach($order, $file, $this->resultType, $title);
                $saved++;
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) $this->addError($field === 'subscription' ? 'resultFiles' : $field, $messages[0]);
        }
        $this->reset(['resultFiles', 'resultTitle']);
        $this->inputKey++;
        if ($saved) {
            $this->dispatch('notify', type: 'success', message: $saved.' result '.($saved === 1 ? 'file' : 'files').' added. '.($order->status === 'scanned' ? 'Mark the scans reported when the report is done.' : ''));
            $this->dispatch('scan-order-changed');
        }
    }

    public function recordConsent(): void
    {
        $this->writable();
        $this->act(fn ($service, $order) => $service->recordConsent($order), 'Consent recorded. The results can be sent once reported.');
    }

    public function send(string $channel): void
    {
        $this->writable();
        $messages = ['whatsapp' => 'Marked as sent by WhatsApp.', 'sms' => 'Results link sent by SMS.', 'email' => 'Results link emailed.', 'link' => 'Marked as sent with the copied link.'];
        $this->act(fn ($service, $order) => $service->send($order, $channel), $messages[$channel] ?? 'Sent.');
    }

    private function act(callable $action, string $done): void
    {
        $this->resetErrorBag('send');
        try {
            $action(app(ScanResultsService::class), $this->order());
        } catch (ValidationException $e) {
            $this->addError('send', collect($e->errors())->flatten()->first());
            return;
        }
        $this->dispatch('notify', type: 'success', message: $done);
        $this->dispatch('scan-order-changed');
    }

    public function render()
    {
        $order = $this->order();
        $service = app(ScanResultsService::class);
        $canSend = $order->canShareResults() && $order->documents->isNotEmpty();

        return view('livewire.scans.scan-results-component', [
            'order' => $order,
            'types' => ScanResultsService::TYPES,
            'channels' => ScanResultsService::CHANNELS,
            'canSend' => $canSend,
            'shareLink' => $canSend ? $service->link($order) : null,
            'whatsApp' => $canSend ? $service->whatsAppLink($order) : null,
            'days' => ScanResultsService::LINK_DAYS,
        ]);
    }
}
