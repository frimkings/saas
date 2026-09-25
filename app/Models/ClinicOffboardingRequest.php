<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class ClinicOffboardingRequest extends Model
{protected $guarded=[];protected $casts=['effective_at'=>'datetime','retention_until'=>'datetime','checklist'=>'array','blockers'=>'array','approved_at'=>'datetime','reversed_at'=>'datetime','completed_at'=>'datetime'];protected static function booted():void{static::creating(fn($m)=>$m->uuid??=(string)Str::uuid());}public function clinic(){return $this->belongsTo(Clinic::class);}public function subscription(){return $this->belongsTo(ClinicSubscription::class,'clinic_subscription_id');}public function requester(){return $this->belongsTo(User::class,'requested_by');}public function approver(){return $this->belongsTo(User::class,'approved_by');}}
