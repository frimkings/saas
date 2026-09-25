<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LegacyImportIdentityWorkflowTest extends TestCase
{
    public function test_every_legacy_identity_has_all_supported_resolution_actions(): void
    {
        $root = dirname(__DIR__, 2);
        $component = file_get_contents($root.'/app/Livewire/Platform/LegacyImportManagerComponent.php');
        $view = file_get_contents($root.'/resources/views/livewire/platform/legacy-import-manager-component.blade.php');
        $service = file_get_contents($root.'/app/Services/LegacyClinicImportService.php');

        foreach (['create', 'merge', 'replace', 'skip'] as $action) {
            $this->assertStringContainsString("'{$action}'", $component);
        }
        $this->assertStringContainsString('Create using replacement email', $view);
        $this->assertStringContainsString("['legacy_id']", $component);
        $this->assertStringContainsString("['conflicts'][(string)\$old->id]", $service);
        $this->assertStringContainsString('replacement_email', $component);
    }
}
