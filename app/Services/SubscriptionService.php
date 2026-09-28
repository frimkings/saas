<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\ClinicSubscription;

class SubscriptionService
{
    public function current(Clinic $clinic): ?ClinicSubscription
    {
        return $clinic->subscriptions()->with('plan')->latest('id')->first();
    }

    public function branchAllowance(Clinic $clinic): array
    {
        if (!app(ClinicAccessService::class)->hosted($clinic) && app(\App\Support\Tenancy\TenantContext::class)->clinicId() === $clinic->id) {
            $access = LicenseService::offlineAccess();
            $activeCount = $clinic->branches()->where('is_active', true)->count();
            return ['subscription' => null, 'activeCount' => $activeCount, 'permitted' => !$access['read_only'],
                'limit' => null, 'can_add' => !$access['read_only'], 'remaining' => null];
        }
        $subscription = $this->current($clinic);
        $activeCount = $clinic->branches()->where('is_active', true)->count();
        $permitted = $subscription?->permitsBranches() ?? false;
        $limit = $subscription?->branchLimit();
        return compact('subscription', 'activeCount', 'permitted', 'limit') + [
            'can_add' => $permitted && ($limit === null || $activeCount < $limit),
            'remaining' => $limit === null ? null : max(0, $limit - $activeCount),
        ];
    }

    public function access(Clinic $clinic, ?string $feature = null): array
    {
        $subscription = $this->current($clinic);
        if (! $subscription) return ['allowed'=>false,'read_only'=>true,'status'=>'missing','reason'=>'No subscription is assigned.'];
        $status = $subscription->status;
        if (in_array($status, ['suspended','cancelled'], true)) return ['allowed'=>false,'read_only'=>true,'status'=>$status,'reason'=>'The clinic subscription is not operational.'];
        $end = $status === 'trial' ? $subscription->trial_ends_at : $subscription->current_period_ends_at;
        if (in_array($status, ['trial', 'active', 'overdue'], true) && ($status === 'overdue' || ($end && $end->lte(now())))) {
            // Fully usable for `subscriptions.grace_days` after expiry, then read-only (and locked for staff).
            $status = $end && $end->copy()->addDays((int) config('subscriptions.grace_days', 1))->gt(now()) ? 'overdue' : 'restricted';
        }
        $readOnly = in_array($status, ['restricted', 'expired'], true);
        $features = $subscription->feature_snapshot;
        // Plus the clinic's add-ons (a list that already means "everything" needs none).
        if (! empty($features) && ! in_array('*', $features, true)) {
            $features = array_values(array_unique([...$features, ...app(ClinicAddonService::class)->activeFeatures($clinic->id)]));
        }
        $allowed = ! $feature || \App\Support\PlanProduct::allows($features, $feature);
        return ['allowed'=>$allowed,'read_only'=>$readOnly,'status'=>$status,'reason'=>$allowed?null:'This feature is not included in your subscription.'];
    }

    public function hasFeature(Clinic $clinic, string $feature): bool
    {
        return $this->access($clinic, $feature)['allowed'];
    }
}
