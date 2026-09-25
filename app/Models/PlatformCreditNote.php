<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PlatformCreditNote extends Model{protected $fillable=['number','clinic_id','platform_invoice_id','amount','currency','reason','issued_by','issued_at'];protected $casts=['amount'=>'decimal:2','issued_at'=>'datetime'];public function clinic(){return $this->belongsTo(Clinic::class);}public function invoice(){return $this->belongsTo(PlatformInvoice::class,'platform_invoice_id');}}
