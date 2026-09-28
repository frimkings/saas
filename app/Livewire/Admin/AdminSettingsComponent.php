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

    public function mount(): void
    {
        abort_if(!Auth::user()->hasRole('Super Admin'), 403);

        $tab = request()->query('tab', 'system');
        if (in_array($tab, ['system', 'backup', 'report', 'sms', 'templates', 'whatsapp', 'license']) && ($tab !== 'backup' || $this->canManageFullBackups())) {
            $this->activeTab = $tab;
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['system', 'backup', 'report', 'sms', 'templates', 'whatsapp', 'license']) && ($tab !== 'backup' || $this->canManageFullBackups())) {
            $this->activeTab = $tab;
        }
    }

    public function render()
    {
        return view('livewire.admin.admin-settings-component')
            ->layout('layouts.admin.admin-layout');
    }
}
