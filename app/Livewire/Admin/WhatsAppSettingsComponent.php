<?php

namespace App\Livewire\Admin;

use App\Models\Setting;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Livewire\Component;

class WhatsAppSettingsComponent extends Component
{
    public bool   $whatsappEnabled       = false;
    public string $phoneNumberId         = '';
    public string $accessToken           = '';   // blank = keep existing
    public string $apptTemplate          = 'appointment_reminder';
    public string $apptTemplateLang      = 'en';
    public string $birthdayTemplate      = '';
    public string $recallTemplate        = '';
    public string $renewalTemplate       = '';
    public string $bulkChannel           = 'sms';
    public string $testPhone             = '';

    public function mount(): void
    {
        if (!Auth::user()->hasRole('Super Admin')) return;

        $s = Setting::getSettings();
        $this->whatsappEnabled  = (bool) ($s->whatsapp_enabled ?? false);
        $this->phoneNumberId    = $s->whatsapp_phone_number_id ?? '';
        $this->apptTemplate     = $s->whatsapp_appt_template ?? 'appointment_reminder';
        $this->apptTemplateLang = $s->whatsapp_appt_template_lang ?? 'en';
        $this->birthdayTemplate = $s->whatsapp_birthday_template ?? '';
        $this->recallTemplate   = $s->whatsapp_recall_template ?? '';
        $this->renewalTemplate  = $s->whatsapp_renewal_template ?? '';
        $this->bulkChannel      = $s->whatsapp_bulk_channel ?? 'sms';
        // Access token intentionally blank — user must re-enter to change
    }

    public function save(): void
    {
        $this->validate([
            'phoneNumberId'    => 'required_if:whatsappEnabled,true|nullable|string|max:100',
            'apptTemplate'     => 'nullable|string|max:100',
            'apptTemplateLang' => 'nullable|string|max:20',
            'birthdayTemplate' => 'nullable|string|max:100',
            'recallTemplate'   => 'nullable|string|max:100',
            'renewalTemplate'  => 'nullable|string|max:100',
            'bulkChannel'      => 'required|in:sms,whatsapp,both',
        ]);

        $data = [
            'whatsapp_phone_number_id'   => trim($this->phoneNumberId) ?: null,
            'whatsapp_appt_template'     => trim($this->apptTemplate) ?: null,
            'whatsapp_appt_template_lang'=> trim($this->apptTemplateLang) ?: 'en',
            'whatsapp_birthday_template' => trim($this->birthdayTemplate) ?: null,
            'whatsapp_recall_template'   => trim($this->recallTemplate) ?: null,
            'whatsapp_renewal_template'  => trim($this->renewalTemplate) ?: null,
            'whatsapp_bulk_channel'      => $this->bulkChannel,
        ];

        if (!empty(trim($this->accessToken))) {
            $data['whatsapp_access_token'] = Crypt::encryptString(trim($this->accessToken));
        }

        Setting::getSettings()->update($data);
        $this->accessToken = '';

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'WhatsApp settings saved.']);
    }

    public function toggleWhatsApp(): void
    {
        $this->whatsappEnabled = !$this->whatsappEnabled;
        Setting::getSettings()->update(['whatsapp_enabled' => $this->whatsappEnabled]);

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => $this->whatsappEnabled ? 'WhatsApp notifications enabled.' : 'WhatsApp notifications disabled.',
        ]);
    }

    public function sendTest(): void
    {
        $this->validate(['testPhone' => 'required|string|max:20']);

        $s = Setting::getSettings();
        $msg = 'Test WhatsApp message from ' . ($s->clinic_name ?? 'the clinic') . '. Your WhatsApp integration is working correctly.';

        $result = (new WhatsAppService)->sendText($this->testPhone, $msg);

        if ($result['success']) {
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Test message sent. Check your WhatsApp.']);
        } else {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Test failed: ' . ($result['error'] ?? 'Unknown error')]);
        }
    }

    public function render()
    {
        return view('livewire.admin.whatsapp-settings-component')
            ->layout('layouts.admin.admin-layout');
    }
}
