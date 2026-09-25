<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BillingNotificationLog extends Model
{
 protected $fillable=['clinic_id','clinic_subscription_id','platform_invoice_id','event','deduplication_key','recipients','status','error','sent_at'];
 protected $casts=['recipients'=>'array','sent_at'=>'datetime'];
 public function clinic(){return $this->belongsTo(Clinic::class);}
}
