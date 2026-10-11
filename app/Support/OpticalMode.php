<?php

namespace App\Support;

use App\Models\Clinic;
use App\Services\SubscriptionService;
use App\Support\Tenancy\TenantContext;

class OpticalMode
{
    public static function opticalOnly(?Clinic $clinic = null): bool
    {
        return self::product($clinic) === PlanProduct::OPTICAL;
    }

    /** Subscribed to staff attendance and nothing else: no clinic and no optical shop. */
    public static function attendanceOnly(?Clinic $clinic = null): bool
    {
        return self::product($clinic) === PlanProduct::ATTENDANCE;
    }

    /** The product the clinic is on, or null when no clinic is in play. */
    public static function product(?Clinic $clinic = null): ?string
    {
        $clinic ??= app(TenantContext::class)->clinic();
        if (! $clinic && ! config('tenancy.enabled')) $clinic = \App\Models\Setting::first()?->clinic;
        if (! $clinic) return null;
        // Hosted clinics follow their subscription; local installs follow their signed license.
        $features = $clinic->deployment_mode === 'hosted'
            ? app(SubscriptionService::class)->current($clinic)?->feature_snapshot
            : \App\Services\LicenseService::offlineAccess()['features'];
        return PlanProduct::productOf($features);
    }
}
