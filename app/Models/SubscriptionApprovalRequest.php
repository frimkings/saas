<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class SubscriptionApprovalRequest extends Model
{protected $fillable=['uuid','clinic_id','action','target_type','target_id','payload','payload_hash','reason','status','requested_by','reviewed_by','review_notes','expires_at','reviewed_at','executed_at'];protected $casts=['payload'=>'array','expires_at'=>'datetime','reviewed_at'=>'datetime','executed_at'=>'datetime'];protected static function booted():void{static::creating(fn($m)=>$m->uuid??=(string)Str::uuid());}public function clinic(){return $this->belongsTo(Clinic::class);}public function requester(){return $this->belongsTo(User::class,'requested_by');}public function reviewer(){return $this->belongsTo(User::class,'reviewed_by');}}
