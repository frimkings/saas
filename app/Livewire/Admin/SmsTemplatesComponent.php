<?php

namespace App\Livewire\Admin;

use App\Models\Patient;
use App\Services\LicenseService;
use App\Support\Feature;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Services\SmsService;
use Livewire\Component;

class SmsTemplatesComponent extends Component
{
    public array $templates = [];

    // Birthday filter (persisted to settings)
    public string $birthdayFilter = 'all';
    public ?int $birthdayCustomMonths = null;

    // Recall settings (persisted to settings)
    public bool $recallEnabled = false;
    public int $recallMonths = 12;

    // Broadcast (session-state only — not persisted)
    public string $broadcastFilter = 'all';
    public ?int $broadcastCustomMonths = null;
    public bool $broadcastConfirmStep = false;
    public int $broadcastRecipientCount = 0;

    public function mount(): void
    {
        abort_if(!LicenseService::has(Feature::SMS_CAMPAIGNS), 403, 'SMS campaigns require a Pro license.');
        $this->loadTemplates();
        $s = Setting::getSettings();
        $this->birthdayFilter        = $s->birthday_sms_filter        ?? 'all';
        $this->birthdayCustomMonths  = $s->birthday_sms_custom_months ?? null;
        $this->recallEnabled         = (bool) ($s->recall_sms_enabled ?? false);
        $this->recallMonths          = (int)  ($s->recall_months      ?? 12);
    }

    private function loadTemplates(): void
    {
        if (!app(\App\Services\ClinicAccessService::class)->access()['read_only']) {
            SmsTemplate::ensureDefaults();
        }

        $this->templates = SmsTemplate::orderBy('id')
            ->get(['id', 'key', 'label', 'message', 'placeholders'])
            ->keyBy('key')
            ->map(fn($t) => [
                'id'           => $t->id,
                'label'        => $t->label,
                'message'      => $t->message,
                'placeholders' => array_values(array_unique(array_merge($t->placeholders ?? [], SmsTemplate::CONTEXT_PLACEHOLDERS))),
            ])
            ->toArray();
    }

    public function save(string $key): void
    {
        $this->validate([
            "templates.{$key}.message" => 'required|string|max:1000',
        ], [
            "templates.{$key}.message.required" => 'Message cannot be empty.',
            "templates.{$key}.message.max"      => 'Message must be 1000 characters or fewer.',
        ]);

        SmsTemplate::where('key', $key)->update([
            'message' => trim($this->templates[$key]['message']),
        ]);

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => '"' . $this->templates[$key]['label'] . '" template saved.',
        ]);
    }

    public function discardChanges(string $key): void
    {
        $tpl = SmsTemplate::where('key', $key)->first();
        if ($tpl) {
            $this->templates[$key]['message'] = $tpl->message;
        }
    }

    public function prepareBroadcast(): void
    {
        if ($this->broadcastFilter === 'custom' && (empty($this->broadcastCustomMonths) || $this->broadcastCustomMonths < 1)) {
            $this->addError('broadcastCustomMonths', 'Please enter a valid number of months.');
            return;
        }

        $tpl = SmsTemplate::where('key', 'custom_broadcast')->first();
        if (!$tpl || empty(trim($tpl->message))) {
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'Please save a message before broadcasting.']);
            return;
        }

        $this->broadcastRecipientCount = $this->buildBroadcastQuery()->count();
        $this->broadcastConfirmStep    = true;
    }

    public function cancelBroadcast(): void
    {
        $this->broadcastConfirmStep = false;
    }

    public function sendCustomBroadcast(): void
    {
        $tpl = SmsTemplate::where('key', 'custom_broadcast')->first();
        if (!$tpl || empty(trim($tpl->message))) {
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'Broadcast message is empty.']);
            $this->broadcastConfirmStep = false;
            return;
        }

        $patients = $this->buildBroadcastQuery()->get(['id', 'name', 'contact']);
        $clinic   = Setting::getSettings()->clinic_name ?? 'the clinic';
        $smsService = new SmsService();
        $sent = $failed = 0;

        foreach ($patients as $patient) {
            $msg = SmsTemplate::fillMessage($tpl->message, ['[NAME]' => $patient->name, '[CLINIC]' => $clinic]);
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

    public function saveRecallSettings(): void
    {
        $this->validate([
            'recallMonths' => 'required|integer|min:1|max:120',
        ], [
            'recallMonths.required' => 'Please enter an inactivity threshold.',
            'recallMonths.min'      => 'Must be at least 1 month.',
            'recallMonths.max'      => 'Cannot exceed 120 months (10 years).',
        ]);

        Setting::getSettings()->update([
            'recall_sms_enabled' => $this->recallEnabled,
            'recall_months'      => $this->recallMonths,
        ]);

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => 'Patient recall settings saved.',
        ]);
    }

    public function saveBirthdaySettings(): void
    {
        $this->validate([
            'birthdayFilter'       => 'required|in:all,this_year,last_24_months,custom',
            'birthdayCustomMonths' => 'nullable|integer|min:1|max:120',
        ], [
            'birthdayFilter.in'            => 'Please select a valid filter option.',
            'birthdayCustomMonths.integer' => 'Months must be a whole number.',
            'birthdayCustomMonths.min'     => 'Must be at least 1 month.',
            'birthdayCustomMonths.max'     => 'Cannot exceed 120 months (10 years).',
        ]);

        Setting::getSettings()->update([
            'birthday_sms_filter'        => $this->birthdayFilter,
            'birthday_sms_custom_months' => $this->birthdayFilter === 'custom' ? $this->birthdayCustomMonths : null,
        ]);

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => 'Birthday SMS filter saved.',
        ]);
    }

    public function render()
    {
        return view('livewire.admin.sms-templates-component');
    }
}
