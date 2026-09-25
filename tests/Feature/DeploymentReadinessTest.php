<?php
namespace Tests\Feature;
use App\Models\DeploymentReadinessRun;
use App\Models\User;
use App\Services\DeploymentReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
class DeploymentReadinessTest extends TestCase {
 use RefreshDatabase;
 public function test_audit_detects_unsafe_test_environment_and_persists_evidence():void{$user=User::factory()->create();$user->forceFill(['is_platform_admin'=>true])->save();$report=app(DeploymentReadinessService::class)->run(true,$user->id);$this->assertFalse($report['ready']);$this->assertSame('blocked',$report['status']);$this->assertFalse($report['checks']['environment']['passed']);$this->assertFalse($report['checks']['database']['passed']);$this->assertDatabaseHas('deployment_readiness_runs',['run_by'=>$user->id,'ready'=>0,'status'=>'blocked']);}
 public function test_command_supports_json_and_nonzero_release_gate():void{$code=Artisan::call('platform:deployment-readiness',['--json'=>true,'--no-persist'=>true]);$this->assertSame(1,$code);$output=Artisan::output();$this->assertStringContainsString('"ready": false',$output);$this->assertStringContainsString('"checks"',$output);}
 public function test_only_platform_administrators_can_open_dashboard():void{$user=User::factory()->create();$this->actingAs($user)->get(route('platform.deployment-readiness'))->assertForbidden();$user->forceFill(['is_platform_admin'=>true])->save();$this->actingAs($user)->withSession(['workspace_mode'=>'platform'])->get(route('platform.deployment-readiness'))->assertOk()->assertSee('Production Deployment Readiness');}
}
