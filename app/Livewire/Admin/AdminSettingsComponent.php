<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AdminSettingsComponent extends Component
{
    public string $activeTab = 'system';

    public function canManageFullBackups(): bool
    {
        return ! config('tenancy.enabled') || app()->environment('local');
    }

    private const TABS = ['system', 'links', 'receipts', 'reminders', 'backup', 'report', 'license'];

    /** SMS, templates and WhatsApp moved to Communications; old links still land on them. */
    private const MOVED = ['sms' => 'admin.sms-settings', 'templates' => 'admin.messages', 'whatsapp' => 'admin.whatsapp-settings'];

    public function mount()
    {
        abort_if(!Auth::user()->hasRole('Super Admin'), 403);

        $tab = request()->query('tab', 'system');
        if (isset(self::MOVED[$tab])) {
            return $this->redirectRoute(self::MOVED[$tab]);
        }
        if (in_array($tab, self::TABS, true) && ($tab !== 'backup' || $this->canManageFullBackups())) {
            $this->activeTab = $tab;
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true) && ($tab !== 'backup' || $this->canManageFullBackups())) {
            $this->activeTab = $tab;
        }
    }

    public function render()
    {
        return view('livewire.admin.admin-settings-component')
            ->layout('layouts.admin.admin-layout');
    }
}
