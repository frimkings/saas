<?php

namespace App\Support;

use App\Models\Clinic;
use App\Services\SubscriptionService;
use App\Support\Tenancy\TenantContext;

class OpticalMode
{
    public static function opticalOnly(?Clinic $clinic = null): bool
    {
        $clinic ??= app(TenantContext::class)->clinic();
        if (! $clinic && ! config('tenancy.enabled')) $clinic = \App\Models\Setting::first()?->clinic;
        if (! $clinic) return false;
        // Hosted clinics follow their subscription; local installs follow their signed license.
        $features = $clinic->deployment_mode === 'hosted'
            ? app(SubscriptionService::class)->current($clinic)?->feature_snapshot
            : \App\Services\LicenseService::offlineAccess()['features'];
        return PlanProduct::productOf($features) === PlanProduct::OPTICAL;
    }
}
