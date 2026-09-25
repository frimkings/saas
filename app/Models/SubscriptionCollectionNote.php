<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SubscriptionCollectionNote extends Model{protected $fillable=['subscription_collection_case_id','user_id','contact_method','note','contacted_at'];protected $casts=['contacted_at'=>'datetime'];public function collectionCase(){return $this->belongsTo(SubscriptionCollectionCase::class,'subscription_collection_case_id');}}
