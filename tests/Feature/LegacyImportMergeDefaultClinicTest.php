<?php
namespace Tests\Feature;
use App\Models\LegacyImportBatch;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\LegacyClinicImportService;
use App\Services\LegacyImportAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyImportMergeDefaultClinicTest extends TestCase
{
 use RefreshDatabase;
 protected function setUp():void{parent::setUp();config()->set('legacy_import.database_username','root');config()->set('legacy_import.database_password','');config()->set('database.connections.mysql.username','root');config()->set('database.connections.mysql.password','');DB::purge('mysql');Storage::fake('local');}

 public function test_merged_identity_keeps_existing_default_clinic():void
 {
  Storage::disk('local')->put('legacy-imports/merge.sql',file_get_contents(base_path('tests/Fixtures/legacy-clinic.sql')));$path=Storage::disk('local')->path('legacy-imports/merge.sql');
  $platform=User::factory()->create();$platform->forceFill(['is_platform_admin'=>true])->save();
  $existing=User::factory()->create(['email'=>'legacy-owner@example.test']);
  $homeClinic=DB::table('clinics')->insertGetId(['uuid'=>(string)Str::uuid(),'name'=>'Home Clinic','slug'=>'home-merge-clinic','status'=>'active','deployment_mode'=>'hosted','default_timezone'=>'UTC','default_currency'=>'GHS','created_at'=>now(),'updated_at'=>now()]);
  DB::table('clinic_user')->insert(['clinic_id'=>$homeClinic,'user_id'=>$existing->id,'status'=>'active','clinic_role'=>'Doctor','is_default'=>1,'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
  $plan=SubscriptionPlan::create(['name'=>'Merge Plan','code'=>'merge-'.Str::lower(Str::random(8)),'included_branches'=>1,'base_price'=>0,'annual_price'=>0,'additional_branch_price'=>0,'billing_interval'=>'monthly','is_active'=>1]);
  $analysis=app(LegacyImportAnalyzer::class)->analyze($path);
  $batch=LegacyImportBatch::create(['uuid'=>Str::uuid(),'created_by'=>$platform->id,'original_filename'=>'merge.sql','stored_path'=>'legacy-imports/merge.sql','checksum'=>hash_file('sha256',$path),'status'=>'analyzed','clinic_name'=>'Merged Eye Clinic','clinic_slug'=>'merged-eye-clinic','admin_email'=>'owner-merge@example.test','plan_id'=>$plan->id,'analysis'=>$analysis,'conflicts'=>$analysis['identities'],'analyzed_at'=>now()]);
  $options=['clinic_name'=>'Merged Eye Clinic','clinic_slug'=>'merged-eye-clinic','admin_email'=>'owner-merge@example.test','plan_id'=>$plan->id,'branch_code'=>'MAIN','branch_name'=>'Main Branch','timezone'=>'UTC','currency'=>'GHS','deployment_mode'=>'hosted','conflicts'=>['1'=>['action'=>'merge','email'=>'']]];
  app(LegacyClinicImportService::class)->commit($batch,$options);$batch->refresh();

  $this->assertDatabaseHas('clinic_user',['clinic_id'=>$batch->clinic_id,'user_id'=>$existing->id,'is_default'=>0]);
  $this->assertDatabaseHas('clinic_user',['clinic_id'=>$homeClinic,'user_id'=>$existing->id,'is_default'=>1]);
  $this->assertSame(1,DB::table('clinic_user')->where('user_id',$existing->id)->where('is_default',1)->count());
 }
}
