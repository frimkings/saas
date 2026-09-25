<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SubscriptionAgreement extends Model{protected $fillable=['clinic_id','clinic_subscription_id','subscription_plan_id','plan_family_code','plan_version','terms_version','plan_snapshot','pricing_snapshot','feature_snapshot','terms_snapshot','acceptance_status','accepted_by','accepted_at','source'];protected $casts=['plan_snapshot'=>'array','pricing_snapshot'=>'array','feature_snapshot'=>'array','terms_snapshot'=>'array','accepted_at'=>'datetime'];public function subscription(){return $this->belongsTo(ClinicSubscription::class,'clinic_subscription_id');}public function plan(){return $this->belongsTo(SubscriptionPlan::class,'subscription_plan_id');}}
