<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LegacyImportCutoverWorkflowTest extends TestCase
{
    public function test_priority_nine_cutover_is_gated_audited_and_visible(): void
    {
        $root=dirname(__DIR__,2);
        $service=file_get_contents($root.'/app/Services/LegacyImportCutoverService.php');
        $component=file_get_contents($root.'/app/Livewire/Platform/LegacyImportManagerComponent.php');
        $view=file_get_contents($root.'/resources/views/livewire/platform/legacy-import-manager-component.blade.php');

        foreach(['record_reconciliation','validation_sampling','default_branch','super_admin','subscription'] as $check) {
            $this->assertStringContainsString("'{$check}'",$service);
        }
        $this->assertStringContainsString("abort_unless(\$checklist['passed']",$service);
        $this->assertStringContainsString('legacy_import.cutover_approved',$component);
        $this->assertStringContainsString('Cutover Readiness &amp; Sign-off',$view);
        $this->assertStringContainsString('Approve Clinic Cutover',$view);
    }
}
