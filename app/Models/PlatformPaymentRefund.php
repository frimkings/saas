<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PlatformPaymentRefund extends Model{protected $fillable=['number','clinic_id','platform_invoice_id','platform_payment_id','amount','currency','method','reference','reason','refunded_by','refunded_at'];protected $casts=['amount'=>'decimal:2','refunded_at'=>'datetime'];public function invoice(){return $this->belongsTo(PlatformInvoice::class,'platform_invoice_id');}public function payment(){return $this->belongsTo(PlatformPayment::class,'platform_payment_id');}}
