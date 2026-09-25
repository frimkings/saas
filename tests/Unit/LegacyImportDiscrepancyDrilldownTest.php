<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LegacyImportDiscrepancyDrilldownTest extends TestCase
{
    public function test_report_exposes_actionable_relationship_failure_fields(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/app/Services/LegacyClinicImportService.php');
        $view = file_get_contents($root.'/resources/views/livewire/platform/legacy-import-manager-component.blade.php');

        foreach (['legacy_row_id','column','missing_parent_table','missing_parent_legacy_id','action'] as $field) {
            $this->assertStringContainsString("'{$field}'", $service);
        }
        $this->assertStringContainsString('Relationship Failure Details', $view);
        $this->assertStringContainsString('details_truncated', $view);
    }
}
