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

class LegacyImportOwnerRemapTest extends TestCase
{
 use RefreshDatabase;
 protected function setUp():void{parent::setUp();config()->set('legacy_import.database_username','root');config()->set('legacy_import.database_password','');config()->set('database.connections.mysql.username','root');config()->set('database.connections.mysql.password','');DB::purge('mysql');Storage::fake('local');}

 public function test_lookup_table_owners_are_remapped_and_missing_owners_fall_back_to_clinic_owner():void
 {
  // Legacy user 1 owns one category; the other belongs to a user absent from the dump.
  $sql=file_get_contents(base_path('tests/Fixtures/legacy-clinic.sql'))."\nCREATE TABLE `categories` (`id` bigint unsigned NOT NULL AUTO_INCREMENT,`user_id` bigint unsigned NOT NULL,`name` varchar(255) NOT NULL,`type` varchar(50) NOT NULL DEFAULT 'product',`is_active` tinyint(1) NOT NULL DEFAULT 1,`created_at` timestamp NULL DEFAULT NULL,`updated_at` timestamp NULL DEFAULT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\nINSERT INTO `categories` VALUES (1,1,'Owned Category','product',1,'2026-01-01 08:00:00','2026-01-01 08:00:00'),(2,42,'Orphan Category','product',1,'2026-01-01 08:00:00','2026-01-01 08:00:00');\n";
  Storage::disk('local')->put('legacy-imports/owners.sql',$sql);$path=Storage::disk('local')->path('legacy-imports/owners.sql');
  $platform=User::factory()->create();$platform->forceFill(['is_platform_admin'=>true])->save();
  $plan=SubscriptionPlan::create(['name'=>'Owner Plan','code'=>'owner-'.Str::lower(Str::random(8)),'included_branches'=>1,'base_price'=>0,'annual_price'=>0,'additional_branch_price'=>0,'billing_interval'=>'monthly','is_active'=>1]);
  $analysis=app(LegacyImportAnalyzer::class)->analyze($path);
  $batch=LegacyImportBatch::create(['uuid'=>Str::uuid(),'created_by'=>$platform->id,'original_filename'=>'owners.sql','stored_path'=>'legacy-imports/owners.sql','checksum'=>hash_file('sha256',$path),'status'=>'analyzed','clinic_name'=>'Owner Eye Clinic','clinic_slug'=>'owner-eye-clinic','admin_email'=>'owner-remap@example.test','plan_id'=>$plan->id,'analysis'=>$analysis,'conflicts'=>$analysis['identities'],'analyzed_at'=>now()]);
  $result=app(LegacyClinicImportService::class)->commit($batch,['clinic_name'=>'Owner Eye Clinic','clinic_slug'=>'owner-eye-clinic','admin_email'=>'owner-remap@example.test','plan_id'=>$plan->id,'branch_code'=>'MAIN','branch_name'=>'Main Branch','timezone'=>'UTC','currency'=>'GHS','deployment_mode'=>'hosted','conflicts'=>['1'=>['action'=>'create','email'=>'']]]);
  $batch->refresh();$owner=User::where('email','owner-remap@example.test')->firstOrFail();

  $this->assertSame($owner->id,(int)DB::table('categories')->where('clinic_id',$batch->clinic_id)->where('name','Owned Category')->value('user_id'));
  $this->assertSame($owner->id,(int)DB::table('categories')->where('clinic_id',$batch->clinic_id)->where('name','Orphan Category')->value('user_id'));
  // Legacy dumps have no home_branch_id column; imported patients must still belong to the new branch.
  $this->assertSame(0,DB::table('patients')->where('clinic_id',$batch->clinic_id)->where(fn($q)=>$q->whereNull('home_branch_id')->orWhere('home_branch_id','<>',$result['branch_id']))->count());
  $details=collect($result['validation_report']['tables']['categories']['relationship_failure_details']??[]);
  $this->assertTrue($details->contains(fn($d)=>$d['missing_parent_legacy_id']===42&&$d['action']==='reassigned_to_owner'),json_encode($details));
 }
}
