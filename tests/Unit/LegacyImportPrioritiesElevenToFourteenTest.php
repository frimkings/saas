<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
class LegacyImportPrioritiesElevenToFourteenTest extends TestCase {
 public function test_resume_readiness_exports_and_evidence_are_connected():void{
  $root=dirname(__DIR__,2);$job=file_get_contents($root.'/app/Jobs/CommitLegacyClinicImport.php');$import=file_get_contents($root.'/app/Services/LegacyClinicImportService.php');$ready=file_get_contents($root.'/app/Services/LegacyImportReadinessService.php');$evidence=file_get_contents($root.'/app/Services/LegacyImportEvidenceService.php');$routes=file_get_contents($root.'/routes/web.php');$view=file_get_contents($root.'/resources/views/livewire/platform/legacy-import-manager-component.blade.php');
  foreach(['resume_token','resume_checkpoint','checkpoint_at'] as $field)$this->assertStringContainsString($field,$job);
  $this->assertStringContainsString("'resume_checkpoint'=>['phase'=>'committed'",$import);
  foreach(['super_admin_login','default_branch','branch_roles','settings','subscription','patients','consultations','sales','reports'] as $check)$this->assertStringContainsString("'{$check}'",$ready);
  foreach(['source','schema_summary','import_options','conflict_decisions','operator','timestamps','reconciliation','sampling'] as $field)$this->assertStringContainsString("'{$field}'",$evidence);
  $this->assertStringContainsString('validation.csv',$routes);$this->assertStringContainsString('validation.pdf',$routes);$this->assertStringContainsString('evidence.json',$routes);
  $this->assertStringContainsString('Operational Readiness Checklist',$view);$this->assertStringContainsString('Download PDF',$view);$this->assertStringContainsString('Archive Source Evidence',$view);
 }
}
