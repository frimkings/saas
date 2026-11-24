<?php

namespace App\Livewire\Platform;

use App\Models\PlatformSetting;
use App\Services\PlatformAuditService;
use Livewire\Component;

/** Support contact shown to clinics whose subscription or license has lapsed. */
class SupportSettingsComponent extends Component
{
    public string $name = '';
    public string $phone = '';
    public string $whatsapp = '';
    public string $email = '';
    /** Where clinic requests (plan changes, SMS orders, sender IDs) are emailed. */
    public string $requestsEmail = '';
    /** USSD code clinics dial to pay; empty hides the payment prompt. */
    public string $paymentCode = '';

    public function mount(): void
    {
        $support = PlatformSetting::support();
        $this->name     = (string) $support['name'];
        $this->phone    = (string) $support['phone'];
        $this->whatsapp = (string) $support['whatsapp'];
        $this->email    = (string) $support['email'];
        $this->requestsEmail = (string) PlatformSetting::get(\App\Services\PlatformRequestAlerts::INBOX_SETTING);
        $this->paymentCode = (string) PlatformSetting::paymentCode();
    }

    public function save(PlatformAuditService $audit): void
    {
        $data = $this->validate([
            'name'     => 'required|string|max:100',
            'phone'    => 'nullable|string|max:30',
            'whatsapp' => 'nullable|string|max:30',
            'email'    => 'nullable|email|max:150',
            'requestsEmail' => 'nullable|email|max:150',
            'paymentCode' => ['nullable', 'string', 'max:40', 'regex:/^[0-9*#]+$/'],
        ], ['paymentCode.regex' => 'Use only digits, * and #, e.g. *713*62#.']);

        $old = PlatformSetting::support() + ['payment_code' => PlatformSetting::paymentCode()];
        PlatformSetting::put(['support_name' => $data['name'], 'support_phone' => $data['phone'],
            'support_whatsapp' => $data['whatsapp'], 'support_email' => $data['email'],
            \App\Services\PlatformRequestAlerts::INBOX_SETTING => $data['requestsEmail'],
            PlatformSetting::PAYMENT_CODE_KEY => $data['paymentCode'] ?? '']);
        $audit->record('PLATFORM_SUPPORT_CONTACT_UPDATED', null, $old, PlatformSetting::support() + ['payment_code' => PlatformSetting::paymentCode()]);
        session()->flash('support_message', 'Support contact saved.');
    }

    public function render()
    {
        return view('livewire.platform.support-settings-component')->layout('layouts.platform');
    }
}
