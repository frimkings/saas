<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PatientsRegistryFilterViewTest extends TestCase
{
    public function test_registry_date_range_picker_updates_the_filter(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/livewire/secretary/patients-component.blade.php'
        );

        // Registered-date filter uses the shared date-range picker, which sets fromDate/toDate.
        $this->assertStringContainsString('<x-date-range from="fromDate" to="toDate"', $view);
        $component = file_get_contents(dirname(__DIR__, 2).'/app/Livewire/Secretary/PatientsComponent.php');
        $this->assertStringContainsString('public function updatedFromDate()', $component);
        $this->assertStringContainsString('public function updatedToDate()', $component);
    }
}
