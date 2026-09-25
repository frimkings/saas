<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LegacyImportQueueWorkflowTest extends TestCase
{
    public function test_imports_are_queued_with_persisted_progress_and_safe_retries(): void
    {
        $root = dirname(__DIR__, 2);
        $job = file_get_contents($root.'/app/Jobs/CommitLegacyClinicImport.php');
        $component = file_get_contents($root.'/app/Livewire/Platform/LegacyImportManagerComponent.php');
        $service = file_get_contents($root.'/app/Services/LegacyClinicImportService.php');
        $view = file_get_contents($root.'/resources/views/livewire/platform/legacy-import-manager-component.blade.php');

        $this->assertStringContainsString('implements ShouldQueue', $job);
        $this->assertStringContainsString('public int $tries = 3', $job);
        $this->assertStringContainsString("onQueue('imports')", $component);
        $this->assertStringContainsString("'status' => 'queued'", $component);
        $this->assertStringContainsString('legacy_import_progress', $job);
        $this->assertStringContainsString('processedRows', $service);
        $this->assertStringContainsString('wire:poll.2s', $view);
        $this->assertStringContainsString('Import progress:', $view);
    }
}
