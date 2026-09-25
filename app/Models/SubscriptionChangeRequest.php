<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SubscriptionChangeRequest extends Model
{
    protected $fillable=['clinic_id','current_subscription_id','requested_plan_id','billing_interval','status','message','requested_by','reviewed_by','reviewed_at','review_notes'];
    protected $casts=['reviewed_at'=>'datetime'];
    public function clinic(){return $this->belongsTo(Clinic::class);}
    public function currentSubscription(){return $this->belongsTo(ClinicSubscription::class,'current_subscription_id');}
    public function requestedPlan(){return $this->belongsTo(SubscriptionPlan::class,'requested_plan_id');}
    public function requester(){return $this->belongsTo(User::class,'requested_by');}
}
