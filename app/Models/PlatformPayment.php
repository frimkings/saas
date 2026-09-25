<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PlatformPayment extends Model {
    protected $fillable=['platform_invoice_id','amount','method','reference','idempotency_key','status','paid_at','recorded_by']; protected $casts=['amount'=>'decimal:2','paid_at'=>'datetime'];
    public function invoice(){return $this->belongsTo(PlatformInvoice::class,'platform_invoice_id');}
    public function refunds(){return $this->hasMany(PlatformPaymentRefund::class);}
}
