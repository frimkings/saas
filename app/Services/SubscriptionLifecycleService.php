<?php

namespace App\Services;

use App\Models\ClinicSubscription;
use App\Models\SubscriptionPlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SubscriptionLifecycleService
{
    public function snapshots(SubscriptionPlan $plan, ?string $interval = null): array
    {
        return [
            'plan_snapshot' => [
                'id' => $plan->id, 'name' => $plan->name, 'code' => $plan->code,
                'version' => $plan->version, 'included_branches' => $plan->included_branches,
                'included_users' => $plan->included_users, 'storage_limit_mb' => $plan->storage_limit_mb,
            ],
            'pricing_snapshot' => [
                'base_price' => $plan->base_price, 'annual_price' => $plan->annual_price,
                'additional_branch_price' => $plan->additional_branch_price,
                'currency' => $plan->currency, 'billing_interval' => $interval ?: $plan->billing_interval,
                'tax_rate' => $plan->tax_rate,
            ],
            'feature_snapshot' => array_values($plan->features ?? []),
        ];
    }

    public function ensureSnapshots(ClinicSubscription $subscription): ClinicSubscription
    {
        if ($subscription->plan_snapshot && $subscription->pricing_snapshot && $subscription->feature_snapshot !== null) {
            return $subscription;
        }

        $subscription->fill($this->snapshots($subscription->plan, $subscription->billing_interval))->saveQuietly();
        return $subscription->refresh();
    }

    public function calculateStatus(ClinicSubscription $subscription, CarbonInterface $at): string
    {
        if (in_array($subscription->status, ['cancelled', 'suspended'], true)) {
            return $subscription->status;
        }
        if ($subscription->status === 'restricted') {
            $restrictedAt = $subscription->restricted_at ?? $subscription->status_changed_at ?? $at;
            return $restrictedAt->copy()->addDays(config('subscriptions.restriction_days', 7))->lte($at)
                ? 'suspended' : 'restricted';
        }
        if ($subscription->status === 'trial' && $subscription->trial_ends_at?->gt($at)) {
            return 'trial';
        }
        if ($subscription->status === 'active' && (! $subscription->current_period_ends_at || $subscription->current_period_ends_at->gt($at))) {
            return 'active';
        }
        // One grace period after expiry (fully usable, "overdue"), then restricted.
        $end = $subscription->status === 'trial' ? $subscription->trial_ends_at : $subscription->current_period_ends_at;
        if (in_array($subscription->status, ['trial', 'active', 'overdue'], true) && $end
            && $end->copy()->addDays((int) config('subscriptions.grace_days', 1))->gt($at)) {
            return 'overdue';
        }
        return 'restricted';
    }

    public function evaluate(ClinicSubscription $subscription, ?CarbonInterface $at = null): ClinicSubscription
    {
        $at ??= now();
        $this->ensureSnapshots($subscription);
        $oldStatus = $subscription->status;
        $newStatus = $this->calculateStatus($subscription, $at);

        $subscription->lifecycle_evaluated_at = $at;
        if ($newStatus !== $oldStatus) {
            $subscription->status = $newStatus;
            $subscription->status_changed_at = $at;
            if ($newStatus === 'restricted') $subscription->restricted_at ??= $at;
            if ($newStatus === 'suspended') $subscription->suspended_at ??= $at;
        }
        $subscription->save();

        return $subscription->refresh();
    }

    public function evaluateAll(?CarbonInterface $at = null): array
    {
        $counts = [];
        DB::transaction(function () use ($at, &$counts): void {
            ClinicSubscription::query()->with('plan')->orderBy('id')->chunkById(200, function ($items) use ($at, &$counts): void {
                foreach ($items as $item) {
                    $status = $this->evaluate($item, $at)->status;
                    $counts[$status] = ($counts[$status] ?? 0) + 1;
                }
            });
        });
        return $counts;
    }
}
