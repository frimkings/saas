<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SubscriptionCollectionCase extends Model{protected $fillable=['clinic_id','clinic_subscription_id','platform_invoice_id','status','aging_bucket','promised_payment_date','promised_amount','last_contacted_at','resolved_at','assigned_to'];protected $casts=['promised_payment_date'=>'date','promised_amount'=>'decimal:2','last_contacted_at'=>'datetime','resolved_at'=>'datetime'];public function clinic(){return $this->belongsTo(Clinic::class);}public function invoice(){return $this->belongsTo(PlatformInvoice::class,'platform_invoice_id');}public function notes(){return $this->hasMany(SubscriptionCollectionNote::class);}public function assignee(){return $this->belongsTo(User::class,'assigned_to');}}
