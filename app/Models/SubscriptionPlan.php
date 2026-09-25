<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = ['name','code','product','family_code','version','terms_version','terms','supersedes_plan_id','included_branches','included_users','storage_limit_mb','sms_allowance','trial_days','grace_days','features','base_price','annual_price','currency','additional_branch_price','billing_interval','tax_rate','is_active','published_at','retired_at'];
    protected $casts = ['version'=>'integer','terms_version'=>'integer','terms'=>'array','included_branches'=>'integer','included_users'=>'integer','storage_limit_mb'=>'integer','sms_allowance'=>'integer','trial_days'=>'integer','grace_days'=>'integer','features'=>'array','base_price'=>'decimal:2','annual_price'=>'decimal:2','additional_branch_price'=>'decimal:2','tax_rate'=>'decimal:2','is_active'=>'boolean','published_at'=>'datetime','retired_at'=>'datetime'];

    public function subscriptions() { return $this->hasMany(ClinicSubscription::class); }
    public function supersedes() { return $this->belongsTo(self::class, 'supersedes_plan_id'); }
    public function successors() { return $this->hasMany(self::class, 'supersedes_plan_id'); }
}
