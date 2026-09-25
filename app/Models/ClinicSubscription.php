<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicSubscription extends Model
{
    protected $fillable = ['clinic_id', 'subscription_plan_id', 'plan_snapshot', 'pricing_snapshot', 'feature_snapshot', 'status', 'renewal_mode', 'billing_interval', 'branch_limit_override', 'notes', 'change_reason', 'trial_ends_at', 'current_period_starts_at', 'current_period_ends_at', 'grace_ends_at', 'cancelled_at', 'status_changed_at', 'lifecycle_evaluated_at', 'restricted_at', 'suspended_at'];
    protected $casts = ['plan_snapshot'=>'array','pricing_snapshot'=>'array','feature_snapshot'=>'array','branch_limit_override' => 'integer', 'trial_ends_at' => 'datetime', 'current_period_starts_at' => 'datetime', 'current_period_ends_at' => 'datetime', 'grace_ends_at' => 'datetime', 'cancelled_at' => 'datetime', 'status_changed_at'=>'datetime', 'lifecycle_evaluated_at'=>'datetime', 'restricted_at'=>'datetime', 'suspended_at'=>'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $subscription): void {
            if (! $subscription->plan_snapshot && $subscription->subscription_plan_id) {
                $plan = $subscription->plan()->first();
                if ($plan) $subscription->fill(app(\App\Services\SubscriptionLifecycleService::class)->snapshots($plan, $subscription->billing_interval));
            }
            $subscription->status_changed_at ??= now();
        });
        static::created(function (self $subscription): void {
            $plan=$subscription->plan;
            \App\Models\SubscriptionAgreement::firstOrCreate(['clinic_subscription_id'=>$subscription->id],[
                'clinic_id'=>$subscription->clinic_id,'subscription_plan_id'=>$plan?->id,'plan_family_code'=>$plan?->family_code??$plan?->code??data_get($subscription->plan_snapshot,'code','unknown'),'plan_version'=>$plan?->version??data_get($subscription->plan_snapshot,'version',1),'terms_version'=>$plan?->terms_version??1,
                'plan_snapshot'=>$subscription->plan_snapshot??[],'pricing_snapshot'=>$subscription->pricing_snapshot??[],'feature_snapshot'=>$subscription->feature_snapshot??[],'terms_snapshot'=>$plan?->terms??[],'acceptance_status'=>'recorded','accepted_by'=>auth()->id(),'accepted_at'=>now(),'source'=>auth()->check()?'platform_assignment':'system',
            ]);
        });
    }

    public function clinic() { return $this->belongsTo(Clinic::class); }
    public function plan() { return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id'); }
    public function agreement() { return $this->hasOne(SubscriptionAgreement::class); }

    public function permitsBranches(): bool
    {
        $end = $this->status === 'trial' ? $this->trial_ends_at : $this->current_period_ends_at;
        return in_array($this->status, ['trial', 'active'], true) && (!$end || $end->isFuture());
    }

    public function branchLimit(): ?int
    {
        return $this->branch_limit_override ?? data_get($this->plan_snapshot, 'included_branches', $this->plan?->included_branches);
    }
}
