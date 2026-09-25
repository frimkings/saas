<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LegacyImportValidationSamplingTest extends TestCase
{
    public function test_priority_eight_sampling_is_connected_to_import_and_review_ui(): void
    {
        $root=dirname(__DIR__,2);
        $service=file_get_contents($root.'/app/Services/LegacyClinicImportService.php');
        $view=file_get_contents($root.'/resources/views/livewire/platform/legacy-import-manager-component.blade.php');

        $this->assertStringContainsString('buildValidationSampling($legacy,$maps,$clinicId,$branchId)', $service);
        $this->assertStringContainsString("['sales','total_amount','Sales total']", $service);
        $this->assertStringContainsString("['payment_transactions','amount','Payment total']", $service);
        $this->assertStringContainsString("['products','quantity','Product stock balance']", $service);
        $this->assertStringContainsString("'sample_strategy'=>'first, middle and last mapped row'", $service);
        $this->assertStringContainsString("['sampling_passed']", $service);
        $this->assertStringContainsString('Automated Validation Sampling', $view);
        $this->assertStringContainsString('SAMPLING REVIEW REQUIRED', $view);
    }
}
