<?php
namespace Tests\Feature;
use App\Livewire\Platform\LegacyImportManagerComponent;
use App\Models\LegacyImportBatch;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\LegacyClinicImportService;
use App\Services\LegacyImportAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LegacyImportRollbackTest extends TestCase
{
 use RefreshDatabase;
 private User $platform;
 protected function setUp():void{parent::setUp();config()->set('legacy_import.database_username','root');config()->set('legacy_import.database_password','');config()->set('database.connections.mysql.username','root');config()->set('database.connections.mysql.password','');DB::purge('mysql');Storage::fake('local');$this->platform=User::factory()->create();$this->platform->forceFill(['is_platform_admin'=>true])->save();}

 private function committedBatch():LegacyImportBatch
 {
  Storage::disk('local')->put('legacy-imports/rollback.sql',file_get_contents(base_path('tests/Fixtures/legacy-clinic.sql')));$path=Storage::disk('local')->path('legacy-imports/rollback.sql');
  $plan=SubscriptionPlan::create(['name'=>'Rollback Plan','code'=>'rollback-'.Str::lower(Str::random(8)),'included_branches'=>1,'base_price'=>0,'annual_price'=>0,'additional_branch_price'=>0,'billing_interval'=>'monthly','is_active'=>1]);
  $analysis=app(LegacyImportAnalyzer::class)->analyze($path);
  $batch=LegacyImportBatch::create(['uuid'=>Str::uuid(),'created_by'=>$this->platform->id,'original_filename'=>'rollback.sql','stored_path'=>'legacy-imports/rollback.sql','checksum'=>hash_file('sha256',$path),'status'=>'analyzed','clinic_name'=>'Rollback Eye Clinic','clinic_slug'=>'rollback-eye-clinic','admin_email'=>'owner-rollback@example.test','plan_id'=>$plan->id,'analysis'=>$analysis,'conflicts'=>$analysis['identities'],'analyzed_at'=>now()]);
  app(LegacyClinicImportService::class)->commit($batch,['clinic_name'=>'Rollback Eye Clinic','clinic_slug'=>'rollback-eye-clinic','admin_email'=>'owner-rollback@example.test','plan_id'=>$plan->id,'branch_code'=>'MAIN','branch_name'=>'Main Branch','timezone'=>'UTC','currency'=>'GHS','deployment_mode'=>'hosted','conflicts'=>['1'=>['action'=>'create','email'=>'']]]);
  return $batch->fresh();
 }

 public function test_rollback_removes_unscoped_child_rows_and_keeps_platform_audit_history():void
 {
  $batch=$this->committedBatch();$clinicId=$batch->clinic_id;$branchId=DB::table('branches')->where('clinic_id',$clinicId)->value('id');
  $patientId=DB::table('patients')->where('clinic_id',$clinicId)->value('id');$userId=DB::table('clinic_user')->where('clinic_id',$clinicId)->value('user_id');
  Schema::disableForeignKeyConstraints();
  $diagnosisId=DB::table('diagnoses')->insertGetId(['clinic_id'=>$clinicId,'name'=>'Rollback Diagnosis','created_at'=>now(),'updated_at'=>now()]);
  $consultationId=DB::table('consultations')->insertGetId(['clinic_id'=>$clinicId,'branch_id'=>$branchId,'patient_id'=>$patientId,'user_id'=>$userId,'clearance_id'=>999999,'chiefComplaint'=>'Blurred vision','created_at'=>now(),'updated_at'=>now()]);
  DB::table('consultation_diagnosis')->insert(['consultation_id'=>$consultationId,'diagnosis_id'=>$diagnosisId]);
  Schema::enableForeignKeyConstraints();
  $auditId=DB::table('platform_audit_logs')->insertGetId(['user_id'=>$this->platform->id,'clinic_id'=>$clinicId,'action'=>'legacy_import.readiness_checked','created_at'=>now(),'updated_at'=>now()]);
  $batchAuditId=DB::table('platform_audit_logs')->insertGetId(['user_id'=>$this->platform->id,'action'=>'legacy_import.archived','new_values'=>json_encode(['batch_id'=>$batch->id]),'created_at'=>now(),'updated_at'=>now()]);
  $clinicUuid=DB::table('clinics')->where('id',$clinicId)->value('uuid');
  Storage::fake('public');Storage::disk('public')->put("clinics/{$clinicUuid}/branding/logo.png",'x');Storage::disk('local')->put("clinics/{$clinicUuid}/patients/1/documents/scan.pdf",'x');
  Storage::disk('local')->put("legacy-import-evidence/{$batch->uuid}/manifest.json",'{}');
  $createdUserId=$batch->result['created_user_ids'][0];
  DB::table('model_has_roles')->insert(['role_id'=>Role::firstOrCreate(['name'=>'Staff','guard_name'=>'web'])->id,'model_type'=>User::class,'model_id'=>$createdUserId]);

  $this->assertTrue(Storage::disk('local')->exists('legacy-imports/rollback.sql'));
  $summary=app(LegacyClinicImportService::class)->rollback($batch);
  // The uploaded dump holds the clinic's full data and is deleted with it.
  $this->assertFalse(Storage::disk('local')->exists('legacy-imports/rollback.sql'));

  $this->assertSame(0,DB::table('consultation_diagnosis')->where('consultation_id',$consultationId)->count());
  $this->assertSame(1,$summary['deleted']['consultation_diagnosis']);
  $this->assertDatabaseMissing('clinics',['id'=>$clinicId]);
  // Nothing of the clinic or the batch is kept: rows, audit history, evidence, files, role rows.
  $this->assertDatabaseMissing('legacy_import_batches',['id'=>$batch->id]);
  $this->assertDatabaseMissing('platform_audit_logs',['id'=>$auditId]);
  $this->assertDatabaseMissing('platform_audit_logs',['id'=>$batchAuditId]);
  $this->assertFalse(Storage::disk('local')->exists("legacy-import-evidence/{$batch->uuid}"));
  $this->assertFalse(Storage::disk('public')->exists("clinics/{$clinicUuid}"));
  $this->assertFalse(Storage::disk('local')->exists("clinics/{$clinicUuid}"));
  $this->assertDatabaseMissing('users',['id'=>$createdUserId]);
  $this->assertDatabaseMissing('model_has_roles',['model_id'=>$createdUserId,'model_type'=>User::class]);
 }

 public function test_rollback_requires_typed_phrase_and_writes_audit_entry():void
 {
  $batch=$this->committedBatch();$clinicId=$batch->clinic_id;
  $component=Livewire::actingAs($this->platform)->test(LegacyImportManagerComponent::class)->set('selectedBatchId',$batch->id)
   ->set('rollbackConfirmation','ROLLBACK wrong-slug')->call('rollback',$batch->id)->assertHasErrors('rollback');
  $this->assertDatabaseHas('clinics',['id'=>$clinicId]);

  $component->set('rollbackConfirmation','ROLLBACK rollback-eye-clinic')->call("rollback",$batch->id)->assertHasNoErrors();
  $this->assertDatabaseMissing('clinics',['id'=>$clinicId]);
  $this->assertNull($batch->fresh());
  // One compact audit entry is the only trace left.
  $audit=DB::table('platform_audit_logs')->where('action','legacy_import.rolled_back')->first();
  $this->assertNotNull($audit);$this->assertSame($this->platform->id,(int)$audit->user_id);
  $this->assertSame('rollback-eye-clinic',json_decode($audit->old_values,true)['clinic_slug']);
  $this->assertSame(1,DB::table('platform_audit_logs')->count());
 }

 public function test_discard_and_delete_permanently_remove_uncommitted_batches_and_dumps():void
 {
  Storage::disk('local')->put('legacy-imports/discard.sql','-- dump');Storage::disk('local')->put('legacy-imports/leftover.sql','-- dump');
  $make=fn($status,$file)=>LegacyImportBatch::create(['uuid'=>Str::uuid(),'created_by'=>$this->platform->id,'original_filename'=>$file,'stored_path'=>"legacy-imports/{$file}",'checksum'=>str_repeat('a',64),'status'=>$status,'clinic_name'=>'Discard Clinic','analysis'=>[]]);
  $analyzed=$make('analyzed','discard.sql');$failed=$make('failed','leftover.sql');
  Livewire::actingAs($this->platform)->test(LegacyImportManagerComponent::class)->call('discard',$analyzed->id)->call('purge',$failed->id);
  $this->assertNull($analyzed->fresh());$this->assertNull($failed->fresh());
  $this->assertSame([],Storage::disk('local')->files('legacy-imports'));
 }

 public function test_committed_batches_cannot_be_deleted_without_rollback():void
 {
  $batch=$this->committedBatch();
  $this->expectException(HttpException::class);
  app(LegacyClinicImportService::class)->purge($batch);
 }

 public function test_rollback_after_cutover_is_refused_and_explained():void
 {
  $batch=$this->committedBatch();$batch->update(['cutover_approved_at'=>now()]);
  Livewire::actingAs($this->platform)->test(LegacyImportManagerComponent::class)->set('selectedBatchId',$batch->id)
   ->assertSee('Rollback unavailable')->assertDontSee('Rolling back...')
   ->set('rollbackConfirmation','ROLLBACK rollback-eye-clinic')->call('rollback',$batch->id)->assertHasErrors('rollback');
  $this->assertDatabaseHas('clinics',['id'=>$batch->clinic_id]);
  $this->assertDatabaseMissing('platform_audit_logs',['action'=>'legacy_import.rolled_back']);
 }
}
