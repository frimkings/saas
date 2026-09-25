<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DeploymentReadinessRun extends Model {protected $guarded=[];protected $casts=['ready'=>'boolean','report'=>'array','completed_at'=>'datetime'];public function operator(){return $this->belongsTo(User::class,'run_by');}}
