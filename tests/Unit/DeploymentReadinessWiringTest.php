<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
class DeploymentReadinessWiringTest extends TestCase {
 public function test_release_gate_covers_required_production_controls():void{$root=dirname(__DIR__,2);$service=file_get_contents($root.'/app/Services/DeploymentReadinessService.php');$command=file_get_contents($root.'/app/Console/Commands/DeploymentReadinessCommand.php');foreach(['environment','debug','app_key','https','tenancy','database_user','migrations','queue_worker','session_driver','cache_driver','logging','scheduler','failed_jobs','disk_space','backup','mail','platform_admin'] as $check)$this->assertStringContainsString("'{$check}'",$service);$this->assertStringContainsString("'storage'=>storage_path()",$service);$this->assertStringContainsString('platform:deployment-readiness',$command);$this->assertStringContainsString('--strict',$command);$this->assertStringContainsString('--json',$command);}
}
