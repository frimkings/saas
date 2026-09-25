<?php
namespace Tests\Feature;
use App\Models\User;
use App\Livewire\Platform\LegacyImportManagerComponent;
use App\Services\LegacyImportAnalyzer;
use App\Services\LegacyClinicImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
class LegacyImportManagerTest extends TestCase {
 use RefreshDatabase;
 public function test_import_notifications_only_report_success_for_completed_imports():void
 {
     $admin=User::factory()->create(['is_platform_admin'=>true]);
     $batch=\App\Models\LegacyImportBatch::create(['uuid'=>\Illuminate\Support\Str::uuid(),'created_by'=>$admin->id,'original_filename'=>'clinic.sql','stored_path'=>'legacy-imports/test.sql','checksum'=>str_repeat('a',64),'status'=>'queued','analysis'=>[]]);
     $component=Livewire::actingAs($admin)->test(LegacyImportManagerComponent::class)->set('selectedBatchId',$batch->id);
     $component->call('refreshImport')->assertNotDispatched('legacy-import-notice');
     $batch->update(['status'=>'committed']);
     $component->call('refreshImport')->assertDispatched('legacy-import-notice',icon:'success');
     $batch->update(['status'=>'review_required']);
     $component->call('refreshImport')->assertDispatched('legacy-import-notice',icon:'warning');
     $batch->update(['status'=>'failed']);
     $component->call('refreshImport')->assertDispatched('legacy-import-notice',icon:'error');
 }
 public function test_nullable_unique_keys_do_not_block_preflight():void
 {
     $file=tempnam(sys_get_temp_dir(),'legacy');
     file_put_contents($file,"CREATE TABLE users (id int, staff_id varchar(30) NULL, UNIQUE KEY staff_unique (staff_id)); INSERT INTO users VALUES (1,NULL),(2,NULL),(3,'STAFF-1'); CREATE TABLE patients (id int);");
     try {
         $result=app(LegacyImportAnalyzer::class)->analyze($file);
         $duplicates=collect($result['compatibility']['issues'])->where('code','duplicate_unique_value');
         $this->assertCount(0,$duplicates);
     } finally { unlink($file); }
 }
 public function test_temporary_upload_accepts_dumps_above_twelve_mb_but_rejects_above_two_hundred_mb():void
 {
     $rules=\Livewire\Features\SupportFileUploads\FileUploadConfiguration::rules();
     $this->assertTrue(validator(['file'=>UploadedFile::fake()->create('clinic.sql',13000)],['file'=>$rules])->passes());
     $this->assertTrue(validator(['file'=>UploadedFile::fake()->create('clinic.sql',204801)],['file'=>$rules])->fails());
 }
 public function test_invalid_extension_displays_validation_error():void
 {
     Livewire::actingAs(User::factory()->create(['is_platform_admin'=>true]))->test(LegacyImportManagerComponent::class)
         ->set('adminEmail','owner@example.test')->set('sqlFile',UploadedFile::fake()->createWithContent('clinic.txt','invalid'))
         ->call('analyzeImport')->assertHasErrors('sqlFile')->assertSee('Only .sql database dumps are accepted.');
 }
 public function test_analysis_failure_is_visible_and_removes_stored_dump():void
 {
     Storage::fake('local');
     $this->mock(LegacyImportAnalyzer::class)->shouldReceive('analyze')->once()->andThrow(new \RuntimeException('Invalid dump'));
     Livewire::actingAs(User::factory()->create(['is_platform_admin'=>true]))->test(LegacyImportManagerComponent::class)
         ->set('adminEmail','owner@example.test')->set('sqlFile',UploadedFile::fake()->createWithContent('clinic.sql','invalid'))
         ->call('analyzeImport')->assertHasErrors('sqlFile')->assertSee('The SQL dump could not be analyzed.');
     $this->assertSame([],Storage::disk('local')->files('legacy-imports'));
 }
 protected function setUp():void{parent::setUp();config()->set('legacy_import.database_username','root');config()->set('legacy_import.database_password','');}
 public function test_only_platform_administrators_can_open_import_manager():void{$user=User::factory()->create();$this->actingAs($user)->get(route('platform.imports'))->assertForbidden();$user->forceFill(['is_platform_admin'=>true])->save();$this->actingAs($user)->withSession(['workspace_mode'=>'platform'])->get(route('platform.imports'))->assertOk()->assertSee('Legacy Clinic Import Manager');}
 public function test_analyzer_detects_tables_counts_metadata_and_identity_conflicts():void{$user=User::factory()->create(['email'=>'existing@example.test']);$file=tempnam(sys_get_temp_dir(),'legacy').'.sql';file_put_contents($file,"CREATE TABLE `settings` (`id` int, `clinic_name` varchar(255)); INSERT INTO `settings` VALUES (1, 'Example Eye Clinic'); CREATE TABLE `users` (`id` int, `email` varchar(255)); INSERT INTO `users` (`id`,`email`) VALUES (1,'existing@example.test'),(2,'new@example.test'),(3,'new@example.test');");$result=app(LegacyImportAnalyzer::class)->analyze($file);@unlink($file);$this->assertContains('users',$result['tables']);$this->assertSame(3,$result['counts']['users']);$this->assertSame('Example Eye Clinic',$result['clinic_name']);$this->assertSame('staging_database',$result['count_source']);$this->assertContains($user->email,$result['existing_emails']);$this->assertCount(3,$result['identities']);$this->assertFalse($result['compatibility']['passed']);$this->assertGreaterThan(0,$result['compatibility']['error_count']);$codes=collect($result['compatibility']['issues'])->pluck('code')->all();$this->assertContains('required_table_missing',$codes);$this->assertContains('duplicate_business_key',$codes);}
 public function test_sql_upload_runs_dry_run_and_creates_batch():void{$admin=User::factory()->create(['is_platform_admin'=>true]);$sql="CREATE TABLE `settings` (`id` int, `clinic_name` varchar(255)); INSERT INTO `settings` VALUES (1, 'Uploaded Eye Clinic'); CREATE TABLE `users` (`id` int); INSERT INTO `users` VALUES (1);";$test=Livewire::actingAs($admin)->test(LegacyImportManagerComponent::class)->set('adminEmail','owner@example.test')->set('sqlFile',UploadedFile::fake()->createWithContent('clinic.sql',$sql))->call('analyzeImport')->assertHasNoErrors();$batch=\App\Models\LegacyImportBatch::firstOrFail();$this->assertSame('analyzed',$batch->status);$this->assertSame('Uploaded Eye Clinic',$batch->clinic_name);$this->assertSame('staging_database',$batch->analysis['count_source']);Storage::disk('local')->delete($batch->stored_path);}
 public function test_validation_report_exposes_differences_and_relationship_failures():void{$batch=new \App\Models\LegacyImportBatch(['analysis'=>['counts'=>['users'=>3,'patients'=>4,'sales'=>2]]]);$detail=['legacy_row_id'=>44,'column'=>'consultation_id','missing_parent_table'=>'consultations','missing_parent_legacy_id'=>9,'action'=>'set_null'];$report=app(LegacyClinicImportService::class)->buildValidationReport($batch,['counts'=>['users'=>3,'patients'=>3,'sales'=>2],'relationship_failures'=>['sales'=>1],'relationship_failure_details'=>['sales'=>[$detail]]]);$this->assertFalse($report['passed']);$this->assertSame(-1,$report['tables']['patients']['difference']);$this->assertSame('FAIL',$report['tables']['patients']['status']);$this->assertSame(1,$report['tables']['sales']['relationship_failures']);$this->assertSame($detail,$report['tables']['sales']['relationship_failure_details'][0]);$this->assertFalse($report['tables']['sales']['details_truncated']);$this->assertSame('PASS',$report['tables']['users']['status']);}
 public function test_import_orders_and_remaps_clearance_sale_relationships():void{$service=file_get_contents(app_path('Services/LegacyClinicImportService.php'));$salesPosition=strpos($service,'$copy(\'sales\'');$clearancePosition=strpos($service,'$copy(\'cashier_patient_clearances\'');$consultationPosition=strpos($service,'$copy(\'consultations\'');$cartPosition=strpos($service,'$copy(\'carts\'');$saleItemPosition=strpos($service,'$copy(\'sale_items\'');$this->assertNotFalse($salesPosition);$this->assertNotFalse($clearancePosition);$this->assertLessThan($salesPosition,$clearancePosition);$this->assertLessThan($salesPosition,$consultationPosition);$this->assertLessThan($saleItemPosition,$cartPosition);$this->assertStringContainsString("['sale_id'=>'sales']",$service);$this->assertStringContainsString("'consultation_id'=>'consultations'",$service);$this->assertStringContainsString("'cart_id'=>'carts'",$service);$this->assertStringContainsString("'finalized_by'=>'users'",$service);$this->assertStringContainsString('$row[\'home_branch_id\']=$branchId',$service);$this->assertStringContainsString("'source_counts'=>\$sourceCounts",$service);}
 public function test_priority_two_operational_modules_are_supported():void{$service=file_get_contents(app_path('Services/LegacyClinicImportService.php'));foreach(['insurance_claims','quotations','quotation_items','purchase_orders','purchase_order_items','refund_logs','referrals','patient_documents','app_notifications','sms_logs','inventory_lots','branch_inventory_items','stock_transfers','stock_transfer_items','discount_approval_requests','clearance_revoke_logs','sale_adjustments','report_deliveries','staff_messages'] as $table){$this->assertStringContainsString('$copy(\''.$table.'\'',$service,$table.' is not copied');$this->assertStringContainsString("'{$table}'",$service,$table.' is not validated');}$this->assertStringContainsString('$resolveDeferred(\'staff_messages\')',$service);}
}
