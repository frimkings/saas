<?php
namespace App\Services;
use App\Models\LegacyImportBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
class LegacyImportReadinessService {
 public function run(LegacyImportBatch $batch):array{
  abort_unless($batch->committed_at&&!$batch->rolled_back_at,422,'A completed import is required.');$clinic=(int)$batch->clinic_id;$branch=(int)($batch->result['branch_id']??0);$checks=[];
  $add=function($key,$label,$passed,$detail='')use(&$checks){$checks[$key]=compact('label','passed','detail');};
  $admin=DB::table('users')->whereRaw('LOWER(email)=?',[strtolower((string)$batch->admin_email)])->first();
  $add('super_admin_login','Super Admin login identity',(bool)$admin&&DB::table('clinic_user')->where(['clinic_id'=>$clinic,'user_id'=>$admin->id,'status'=>'active'])->exists(),$admin?'Identity and active clinic membership found.':'Configured administrator identity is missing.');
  $add('default_branch','Default branch',DB::table('branches')->where(['id'=>$branch,'clinic_id'=>$clinic,'is_default'=>1,'is_active'=>1])->exists());
  $add('branch_roles','Branch role assignment',(bool)$admin&&DB::table('branch_user_role')->where(['branch_id'=>$branch,'user_id'=>$admin->id])->exists());
  $add('settings','Clinic settings',DB::table('settings')->where('clinic_id',$clinic)->exists());
  $add('subscription','Subscription',DB::table('clinic_subscriptions')->where('clinic_id',$clinic)->whereIn('status',['active','trial'])->exists());
  foreach(['patients'=>'Patient access','consultations'=>'Consultation access','sales'=>'POS records'] as $table=>$label){$expected=(int)($batch->result['counts'][$table]??0);$actual=DB::table($table)->where('clinic_id',$clinic)->count();$add($table,$label,$actual>=$expected,"Expected at least {$expected}; found {$actual}.");}
  $reportRoutes=collect(Route::getRoutes()->getRoutesByName())->keys()->contains(fn($name)=>str_contains((string)$name,'report'));
  $add('reports','Reports availability',$reportRoutes&&DB::getSchemaBuilder()->hasTable('sales'),'Report route and financial source records are available.');
  $passed=collect($checks)->every(fn($c)=>$c['passed']);$report=['passed'=>$passed,'generated_at'=>now()->toIso8601String(),'checks'=>$checks,'totals'=>['checks'=>count($checks),'passed'=>collect($checks)->where('passed',true)->count(),'failed'=>collect($checks)->where('passed',false)->count()]];$batch->update(['readiness_report'=>$report,'readiness_checked_at'=>now()]);return $report;
 }
}
