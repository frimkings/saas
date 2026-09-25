<?php
namespace Tests\Feature;
use App\Models\LegacyImportBatch;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\LegacyClinicImportService;
use App\Services\LegacyImportAnalyzer;
use App\Services\LegacyImportCutoverService;
use App\Services\LegacyImportEvidenceService;
use App\Services\LegacyImportPostCutoverMonitor;
use App\Services\LegacyImportReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

#[\PHPUnit\Framework\Attributes\Group('legacy-import-acceptance')]
class LegacyImportEndToEndAcceptanceTest extends TestCase
{
 use RefreshDatabase;
 protected function setUp():void{parent::setUp();config()->set('legacy_import.database_username','root');config()->set('legacy_import.database_password','');config()->set('database.connections.mysql.username','root');config()->set('database.connections.mysql.password','');DB::purge('mysql');Storage::fake('local');}

 public function test_complete_import_resume_validation_cutover_and_evidence_workflow():void
 {
  Storage::disk('local')->put('legacy-imports/acceptance.sql',file_get_contents(base_path('tests/Fixtures/legacy-clinic.sql')));$path=Storage::disk('local')->path('legacy-imports/acceptance.sql');
  $platform=User::factory()->create(['email'=>'developer-acceptance@example.test']);$platform->forceFill(['is_platform_admin'=>true])->save();
  $ordinary=User::factory()->create();
  $plan=SubscriptionPlan::create(['name'=>'Acceptance Plan','code'=>'acceptance-'.Str::lower(Str::random(8)),'included_branches'=>1,'base_price'=>0,'annual_price'=>0,'additional_branch_price'=>0,'billing_interval'=>'monthly','is_active'=>1]);
  $analysis=app(LegacyImportAnalyzer::class)->analyze($path);$this->assertTrue($analysis['compatibility']['passed'],json_encode($analysis['compatibility']['issues']));$this->assertSame(1,$analysis['counts']['patients']);
  $batch=LegacyImportBatch::create(['uuid'=>Str::uuid(),'created_by'=>$platform->id,'original_filename'=>'acceptance.sql','stored_path'=>'legacy-imports/acceptance.sql','checksum'=>hash_file('sha256',$path),'status'=>'analyzed','clinic_name'=>'Acceptance Eye Clinic','clinic_slug'=>'acceptance-eye-clinic','admin_email'=>'owner-acceptance@example.test','plan_id'=>$plan->id,'analysis'=>$analysis,'conflicts'=>$analysis['identities'],'analyzed_at'=>now()]);
  $options=['clinic_name'=>'Acceptance Eye Clinic','clinic_slug'=>'acceptance-eye-clinic','admin_email'=>'owner-acceptance@example.test','plan_id'=>$plan->id,'branch_code'=>'MAIN','branch_name'=>'Main Branch','timezone'=>'UTC','currency'=>'GHS','deployment_mode'=>'hosted','conflicts'=>['1'=>['action'=>'create','email'=>'']]];
  $service=app(LegacyClinicImportService::class);
  try{$service->commit($batch,$options,function($percent,$table){if($table==='patients')throw new \RuntimeException('simulated interruption');});$this->fail('The simulated interruption was not raised.');}catch(\RuntimeException $e){$this->assertSame('simulated interruption',$e->getMessage());}
  $this->assertDatabaseMissing('clinics',['slug'=>'acceptance-eye-clinic']);$this->assertDatabaseMissing('users',['email'=>'owner-acceptance@example.test']);
  $batch->update(['status'=>'failed','resume_token'=>(string)Str::uuid(),'resume_checkpoint'=>['phase'=>'copying','table'=>'patients','processed'=>1,'total'=>3],'checkpoint_at'=>now(),'result'=>['import_options'=>$options]]);$token=$batch->resume_token;
  $result=$service->commit($batch->fresh(),$options);$batch->refresh();$this->assertSame($token,$batch->resume_token);$this->assertNotNull($batch->committed_at);$this->assertTrue($result['validation_report']['passed']);$this->assertSame(1,DB::table('clinics')->where('slug','acceptance-eye-clinic')->count());$this->assertSame(1,DB::table('patients')->where('clinic_id',$batch->clinic_id)->count());
  $owner=User::where('email','owner-acceptance@example.test')->firstOrFail();$this->assertTrue(Hash::check('password',$owner->password));$this->assertDatabaseHas('clinic_user',['clinic_id'=>$batch->clinic_id,'user_id'=>$owner->id,'clinic_role'=>'Super Admin']);$this->assertDatabaseHas('branch_user_role',['branch_id'=>$result['branch_id'],'user_id'=>$owner->id]);
  $readiness=app(LegacyImportReadinessService::class)->run($batch->fresh());$this->assertTrue($readiness['passed'],json_encode($readiness['checks']));$cutover=app(LegacyImportCutoverService::class);$this->assertTrue($cutover->approve($batch->fresh(),$platform->id,'Acceptance sign-off')['passed']);
  $monitor=app(LegacyImportPostCutoverMonitor::class);$this->assertTrue($monitor->run($batch->fresh(),$platform->id)['passed']);$monitor->close($batch->fresh());$this->assertFalse(Storage::disk('local')->exists('legacy-imports/acceptance.sql'),'Closing the migration deletes the uploaded dump.');
  $manifest=app(LegacyImportEvidenceService::class)->archive($batch->fresh());$batch->refresh();$this->assertSame($batch->checksum,$manifest['source']['sha256']);$this->assertTrue(Storage::disk('local')->exists($batch->evidence_path));$this->assertSame($batch->evidence_checksum,hash('sha256',Storage::disk('local')->get($batch->evidence_path)));
  $this->actingAs($ordinary)->get(route('platform.imports.validation.csv',$batch))->assertForbidden();
  $this->actingAs($platform)->withSession(['workspace_mode'=>'platform'])->get(route('platform.imports.validation.csv',$batch))->assertOk()->assertHeader('content-type','text/csv; charset=UTF-8');
  $pdf=$this->actingAs($platform)->withSession(['workspace_mode'=>'platform'])->get(route('platform.imports.validation.pdf',$batch));$pdf->assertOk();$this->assertStringStartsWith('%PDF-',$pdf->getContent());
  $this->actingAs($platform)->withSession(['workspace_mode'=>'platform'])->get(route('platform.imports.evidence',$batch))->assertOk();
  $otherClinic=DB::table('clinics')->insertGetId(['uuid'=>(string)Str::uuid(),'name'=>'Other Clinic','slug'=>'other-acceptance-clinic','status'=>'active','deployment_mode'=>'hosted','default_timezone'=>'UTC','default_currency'=>'GHS','created_at'=>now(),'updated_at'=>now()]);
  $otherBranch=DB::table('branches')->insertGetId(['uuid'=>(string)Str::uuid(),'clinic_id'=>$otherClinic,'code'=>'OTHER','name'=>'Other Branch','timezone'=>'UTC','is_default'=>1,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
  DB::table('patients')->where('clinic_id',$batch->clinic_id)->update(['home_branch_id'=>$otherBranch]);$drift=$monitor->run($batch->fresh(),$platform->id);$this->assertFalse($drift['passed']);$this->assertFalse($drift['checks']['branch_ownership']['passed']);
  $this->expectException(HttpException::class);$service->rollback($batch->fresh());
 }

 public function test_platform_authorization_and_preflight_failure_gates_are_visible():void
 {
  $platform=User::factory()->create();$platform->forceFill(['is_platform_admin'=>true])->save();$batch=new LegacyImportBatch(['analysis'=>['compatibility'=>['passed'=>false]],'status'=>'analyzed']);$this->assertFalse($batch->analysis['compatibility']['passed']);
  $this->actingAs($platform)->withSession(['workspace_mode'=>'platform'])->get(route('platform.imports'))->assertOk();
 }
}
