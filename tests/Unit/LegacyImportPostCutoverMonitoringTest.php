<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
class LegacyImportPostCutoverMonitoringTest extends TestCase {
 public function test_priority_ten_monitors_tenant_drift_and_controls_closure():void{
  $root=dirname(__DIR__,2);$service=file_get_contents($root.'/app/Services/LegacyImportPostCutoverMonitor.php');$component=file_get_contents($root.'/app/Livewire/Platform/LegacyImportManagerComponent.php');$view=file_get_contents($root.'/resources/views/livewire/platform/legacy-import-manager-component.blade.php');
  foreach(['clinic_active','default_branch','retention_','relationship_','branch_ownership','attention_required'] as $check)$this->assertStringContainsString($check,$service);
  $this->assertStringContainsString("monitoring_status==='healthy'",$service);
  $this->assertStringContainsString('legacy_import.monitoring_run',$component);
  $this->assertStringContainsString('legacy_import.migration_closed',$component);
  $this->assertStringContainsString('Post-cutover Monitoring &amp; Closure',$view);
  $this->assertStringContainsString('Close Migration Lifecycle',$view);
 }
}
