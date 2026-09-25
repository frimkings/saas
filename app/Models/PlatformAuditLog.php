<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PlatformAuditLog extends Model {
    protected $fillable=['user_id','clinic_id','action','old_values','new_values','reason','ip_address']; protected $casts=['old_values'=>'array','new_values'=>'array'];
    public function user(){return $this->belongsTo(User::class);} public function clinic(){return $this->belongsTo(Clinic::class);}
}
