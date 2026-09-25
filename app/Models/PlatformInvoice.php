<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PlatformInvoice extends Model {
    protected $fillable=['number','clinic_id','clinic_subscription_id','period_start','period_end','due_date','subtotal','tax','total','amount_paid','credited_amount','refunded_amount','currency','status','source','idempotency_key','paid_at','voided_at','void_reason','voided_by','notes','sms_bundle_id','sms_credits'];
    protected $casts=['period_start'=>'date','period_end'=>'date','due_date'=>'date','subtotal'=>'decimal:2','tax'=>'decimal:2','total'=>'decimal:2','amount_paid'=>'decimal:2','credited_amount'=>'decimal:2','refunded_amount'=>'decimal:2','paid_at'=>'datetime','voided_at'=>'datetime'];
    public function clinic(){return $this->belongsTo(Clinic::class);} public function subscription(){return $this->belongsTo(ClinicSubscription::class,'clinic_subscription_id');} public function payments(){return $this->hasMany(PlatformPayment::class);} public function creditNotes(){return $this->hasMany(PlatformCreditNote::class);} public function refunds(){return $this->hasMany(PlatformPaymentRefund::class);} public function smsBundle(){return $this->belongsTo(SmsBundle::class);}
    public function balance(): float { return max(0, (float)$this->total-(float)$this->amount_paid-(float)$this->credited_amount+(float)$this->refunded_amount); }
}
