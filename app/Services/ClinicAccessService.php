<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\Setting;
use App\Support\Licensing\AccessStage;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;

class ClinicAccessService
{
    public function assertWritable(?string $feature = null): void
    {
        $access = $this->access($feature);
        abort_if(!$access['allowed'] || $access['read_only'], 403,
            'This clinic is read-only. Renew its license or subscription before making changes.');
    }

    public function hosted(?Clinic $clinic = null): bool
    {
        $clinic ??= app(TenantContext::class)->clinic();
        if (!$clinic && !config('tenancy.enabled')) $clinic = Setting::first()?->clinic;
        return $clinic !== null && $clinic->deployment_mode === 'hosted';
    }

    public function access(?string $feature = null): array
    {
        $clinic = app(TenantContext::class)->clinic();
        if (!$clinic && !config('tenancy.enabled')) $clinic = Setting::first()?->clinic;
        return $this->hosted($clinic)
            ? app(SubscriptionService::class)->access($clinic, $feature)
            : LicenseService::offlineAccess($feature);
    }

    /**
     * Expiry stage for the active clinic. Null when there is no expiry to measure against
     * (no subscription yet, or an invalid/tampered license), which keeps the read-only fallback.
     */
    public function stage(?Clinic $clinic = null): ?AccessStage
    {
        $clinic ??= app(TenantContext::class)->clinic();
        if (!$clinic && !config('tenancy.enabled')) $clinic = Setting::first()?->clinic;
        if (!$clinic) return null;

        if ($this->hosted($clinic)) {
            $subscription = app(SubscriptionService::class)->current($clinic);
            if (!$subscription) return null;
            $expiry = $subscription->status === 'trial' ? $subscription->trial_ends_at : $subscription->current_period_ends_at;
            return in_array($subscription->status, ['suspended', 'cancelled'], true)
                ? AccessStage::locked($expiry, true)
                : AccessStage::forExpiry($expiry, true);
        }

        $license = LicenseService::offlineAccess();
        if ($license['invalid'] ?? false) return null;
        // An offline license is valid through the whole of its expiry date.
        return AccessStage::forExpiry($license['expires'] ? Carbon::parse($license['expires'])->endOfDay() : null, false);
    }

    /**
     * Renewal banner: admins see it from 30 days before expiry, everyone else from
     * `subscriptions.expiry_warning_days`, and all users once the clinic has expired.
     */
    public function notice(bool $forAdmin = true): ?array
    {
        if (!app(TenantContext::class)->clinic()) return null;
        $stage = $this->stage();
        if (!$stage || !$stage->expiresAt) {
            // No expiry to count down to (invalid license, no subscription): only flag read-only mode.
            return $this->access()['read_only'] ? ['stage' => null, 'read_only' => true, 'hosted' => $this->hosted(),
                'cutoff' => null, 'blocks' => null, 'locks' => null, 'days' => null] : null;
        }

        $window = $forAdmin ? 30 : (int) config('subscriptions.expiry_warning_days', 7);
        if ($stage->stage === AccessStage::ACTIVE && $stage->expiresAt->gt(now()->addDays($window))) return null;
        if ($stage->stage === AccessStage::ACTIVE && !$forAdmin) return null;

        return ['stage' => $stage->stage, 'read_only' => $this->access()['read_only'], 'hosted' => $stage->hosted,
            'cutoff' => $stage->expiresAt->format('d M Y H:i:s T'), 'locks' => $stage->lockedAt?->format('d M Y H:i T'),
            'blocks' => $stage->expiresAt->copy()->addDays((int) config('subscriptions.grace_days', 1))->format('d M Y H:i T'),
            'days' => max(0, (int) now()->startOfDay()->diffInDays($stage->expiresAt->copy()->startOfDay(), false))];
    }
}
