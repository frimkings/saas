<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresSuperAdmin;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Services\SmsService;
use Livewire\Component;

/**
 * Communications → Broadcast: one message to a chosen group of patients (Christmas, a new
 * service, a screening day). Part of SMS campaigns; the route requires the feature.
 */
class BroadcastComponent extends Component
{
    use RequiresSuperAdmin;

    public string $message = '';
    public string $broadcastFilter = 'all';
    public ?int $broadcastCustomMonths = null;
    public bool $broadcastConfirmStep = false;
    public int $broadcastRecipientCount = 0;

    public function mount(): void
    {
        SmsTemplate::ensureDefaults();
        $this->message = (string) SmsTemplate::where('key', 'custom_broadcast')->value('message');
    }

    public function saveMessage(): void
    {
        $this->validate(['message' => 'required|string|max:1000'], ['message.required' => 'Write the message first.']);
        SmsTemplate::where('key', 'custom_broadcast')->update(['message' => trim($this->message)]);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Broadcast message saved.']);
    }

    public function updatedBroadcastFilter(): void
    {
        $this->broadcastConfirmStep = false;
    }

    public function prepareBroadcast(): void
    {
        $this->validate(['message' => 'required|string|max:1000'], ['message.required' => 'Write the message first.']);
        if ($this->broadcastFilter === 'custom' && (empty($this->broadcastCustomMonths) || $this->broadcastCustomMonths < 1)) {
            $this->addError('broadcastCustomMonths', 'Please enter a valid number of months.');
            return;
        }

        $this->saveMessage();
        $this->broadcastRecipientCount = $this->buildBroadcastQuery()->count();
        $this->broadcastConfirmStep    = true;
    }

    public function cancelBroadcast(): void
    {
        $this->broadcastConfirmStep = false;
    }

    public function sendCustomBroadcast(): void
    {
        $text = (string) SmsTemplate::where('key', 'custom_broadcast')->value('message');
        if (trim($text) === '') {
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'Broadcast message is empty.']);
            $this->broadcastConfirmStep = false;
            return;
        }

        $patients = $this->buildBroadcastQuery()->get(['id', 'name', 'contact']);
        $clinic   = Setting::getSettings()->clinic_name ?? 'the clinic';
        $smsService = new SmsService();
        $sent = $failed = 0;

        foreach ($patients as $patient) {
            $msg = SmsTemplate::fillMessage($text, ['[NAME]' => $patient->name, '[CLINIC]' => $clinic]);
            $result = $smsService->send($patient->contact, $msg, $patient->id, 'custom_broadcast');
            $result['success'] ? $sent++ : $failed++;
        }

        $this->broadcastConfirmStep = false;
        $this->dispatch('notify', ...[
            'type'    => $failed === 0 ? 'success' : 'warning',
            'message' => "Broadcast complete — Sent: {$sent}" . ($failed ? ", Failed: {$failed}" : '') . '.',
        ]);
    }

    private function buildBroadcastQuery()
    {
        $query = Patient::whereNotNull('contact')->where('contact', '!=', '');
        $today = now();

        if ($this->broadcastFilter === 'this_year') {
            $query->whereHas('consultations', fn ($q) => $q->whereYear('created_at', $today->year));
        } elseif ($this->broadcastFilter === 'last_24_months') {
            $query->whereHas('consultations', fn ($q) => $q->where('created_at', '>=', $today->copy()->subMonths(24)));
        } elseif ($this->broadcastFilter === 'custom') {
            $months = max(1, (int) ($this->broadcastCustomMonths ?? 24));
            $query->whereHas('consultations', fn ($q) => $q->where('created_at', '>=', $today->copy()->subMonths($months)));
        }

        return $query;
    }

    public function render()
    {
        return view('livewire.admin.broadcast-component', [
            'placeholders' => array_values(array_unique(array_merge(['[NAME]', '[CLINIC]', '[OCCASION]'], SmsTemplate::CONTEXT_PLACEHOLDERS))),
            'sampleName' => 'Ama Mensah',
            'clinicName' => Setting::getSettings()->clinic_name ?? 'the clinic',
        ])->layout('layouts.admin.admin-layout');
    }
}
