<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Services\LicenseService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class LicenseComponent extends Component
{
    public string $licenseKey  = '';
    public string $resultType  = ''; // 'success' | 'error'
    public string $resultMsg   = '';
    public array  $licenseInfo = [];

    public function mount(): void
    {
        // Fix #12: generic 403 — don't leak feature/role names in error messages
        abort_if(!Auth::user()->hasRole('Super Admin'), 403);
    }

    public function activate(): void
    {
        abort_unless(Auth::user()?->hasRole('Super Admin'), 403);
        abort_if(app(\App\Services\ClinicAccessService::class)->hosted(), 403);
        $key = trim($this->licenseKey);

        if (empty($key)) {
            $this->resultType = 'error';
            $this->resultMsg  = 'Please paste a license key before clicking Activate.';
            return;
        }

        LicenseService::clearCache();
        $result = LicenseService::activate($key);

        if ($result['ok']) {
            $this->resultType  = 'success';
            $this->resultMsg   = $result['message'];
            $this->licenseKey  = '';
            $this->licenseInfo = LicenseService::info();
            // Fix #8 force=true: license changes must always be audited
            $access = LicenseService::offlineAccess();
            AuditTrail::record('license.activated', 'Offline license activated.', null, [], [
                'plan' => $access['plan'], 'expires' => $access['expires'], 'features' => $access['features'],
                'installation_id' => LicenseService::installationId(), 'key_fingerprint' => hash('sha256', $key),
            ], null, true);
        } else {
            $this->resultType = 'error';
            $this->resultMsg  = $result['message'];
            // Fix #9: log failed activation attempts so brute-force is visible
            AuditTrail::record('license.activation_failed', 'License activation attempt failed.', null, [], [], null, true);
        }
    }

    public function render()
    {
        $service = app(\App\Services\ClinicAccessService::class);
        $clinic = app(\App\Support\Tenancy\TenantContext::class)->clinic();
        if (!$clinic && !config('tenancy.enabled')) $clinic = \App\Models\Setting::first()?->clinic;
        $hosted = $service->hosted();
        $subscription = $hosted ? app(\App\Services\SubscriptionService::class)->current($clinic) : null;
        return view('livewire.admin.license-component', [
            'hosted' => $hosted,
            'clinicName' => \App\Models\Setting::getSettings()->clinic_name,
            'access' => $service->access(),
            'subscription' => $subscription,
            'installationId' => $hosted ? null : LicenseService::installationId(),
            'activationHistory' => AuditTrail::where('event', 'license.activated')->with('user')->latest()->limit(20)->get(),
        ])
            ->layout('layouts.admin.admin-layout');
    }
}
