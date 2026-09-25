<?php
namespace App\Services;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
class PlanVersioningService
{
 public function save(?SubscriptionPlan $current,array $attributes):SubscriptionPlan{return DB::transaction(function()use($current,$attributes){if(!$current){$attributes['version']=1;$attributes['family_code']=$attributes['code'];$attributes['published_at']=now();return SubscriptionPlan::create($attributes);} $family=$current->family_code?:$current->code;$version=SubscriptionPlan::where('family_code',$family)->max('version')+1;$current->update(['is_active'=>false,'retired_at'=>now()]);$attributes['family_code']=$family;$attributes['version']=$version;$attributes['supersedes_plan_id']=$current->id;$attributes['code']=$family.'-v'.$version;$attributes['published_at']=now();$attributes['retired_at']=null;return SubscriptionPlan::create($attributes);});}
}
