<?php
namespace Tests\Feature;
use App\Livewire\Platform\LegacyImportManagerComponent;
use App\Models\LegacyImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class LegacyImportArchiveTest extends TestCase
{
 use RefreshDatabase;
 private function batch(User $admin,string $name,string $status):LegacyImportBatch
 {
  return LegacyImportBatch::create(['uuid'=>Str::uuid(),'created_by'=>$admin->id,'original_filename'=>'clinic.sql','stored_path'=>'legacy-imports/test.sql','checksum'=>str_repeat('a',64),'status'=>$status,'clinic_name'=>$name,'analysis'=>[]]);
 }

 public function test_finished_batches_can_be_removed_from_the_list_and_restored():void
 {
  $admin=User::factory()->create(['is_platform_admin'=>true]);
  $committed=$this->batch($admin,'Committed Clinic','committed');$failed=$this->batch($admin,'Failed Clinic','failed');
  $component=Livewire::actingAs($admin)->test(LegacyImportManagerComponent::class)->assertSee('Committed Clinic')->assertSee('Failed Clinic');

  $component->call('archive',$committed->id)->call('archive',$failed->id)->assertDontSee('Committed Clinic')->assertDontSee('Failed Clinic')->assertSee('Show removed (2)');
  $this->assertNotNull($committed->fresh()->archived_at);$this->assertSame($admin->id,$committed->fresh()->archived_by);
  $this->assertDatabaseHas('platform_audit_logs',['action'=>'legacy_import.archived','user_id'=>$admin->id]);

  $component->set('showArchived',true)->assertSee('Committed Clinic')->call('restore',$committed->id)->set('showArchived',false)->assertSee('Committed Clinic')->assertDontSee('Failed Clinic');
  $this->assertNull($committed->fresh()->archived_at);
 }

 public function test_running_imports_cannot_be_removed():void
 {
  $admin=User::factory()->create(['is_platform_admin'=>true]);$running=$this->batch($admin,'Running Clinic','importing');
  try{Livewire::actingAs($admin)->test(LegacyImportManagerComponent::class)->call('archive',$running->id);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}
  $this->assertNull($running->fresh()->archived_at);
 }
}
