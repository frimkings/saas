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

    public function mount(): void
    {
        $support = PlatformSetting::support();
        $this->name     = (string) $support['name'];
        $this->phone    = (string) $support['phone'];
        $this->whatsapp = (string) $support['whatsapp'];
        $this->email    = (string) $support['email'];
    }

    public function save(PlatformAuditService $audit): void
    {
        $data = $this->validate([
            'name'     => 'required|string|max:100',
            'phone'    => 'nullable|string|max:30',
            'whatsapp' => 'nullable|string|max:30',
            'email'    => 'nullable|email|max:150',
        ]);

        $old = PlatformSetting::support();
        PlatformSetting::put(['support_name' => $data['name'], 'support_phone' => $data['phone'],
            'support_whatsapp' => $data['whatsapp'], 'support_email' => $data['email']]);
        $audit->record('PLATFORM_SUPPORT_CONTACT_UPDATED', null, $old, PlatformSetting::support());
        session()->flash('support_message', 'Support contact saved.');
    }

    public function render()
    {
        return view('livewire.platform.support-settings-component')->layout('layouts.platform');
    }
}
