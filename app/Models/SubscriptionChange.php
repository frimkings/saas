<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionChange extends Model
{
    protected $fillable = ['clinic_id','clinic_subscription_id','from_plan_id','to_plan_id','billing_interval','timing','status','effective_at','reason','requested_by','applied_at','cancelled_at'];
    protected $casts = ['effective_at'=>'datetime','applied_at'=>'datetime','cancelled_at'=>'datetime'];
    public function clinic(){ return $this->belongsTo(Clinic::class); }
    public function subscription(){ return $this->belongsTo(ClinicSubscription::class, 'clinic_subscription_id'); }
    public function fromPlan(){ return $this->belongsTo(SubscriptionPlan::class, 'from_plan_id'); }
    public function toPlan(){ return $this->belongsTo(SubscriptionPlan::class, 'to_plan_id'); }
}
