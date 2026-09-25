<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PatientsRegistryFilterViewTest extends TestCase
{
    public function test_registry_calendar_updates_livewire_when_a_date_is_selected(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/livewire/secretary/patients-component.blade.php'
        );

        $this->assertStringContainsString('wire:model.live.debounce.350ms="fromDateDisplay"', $view);
        $this->assertStringContainsString('wire:model.live.debounce.350ms="toDateDisplay"', $view);
        $this->assertStringContainsString("dispatchEvent(new Event('input', { bubbles: true }))", $view);
        $this->assertStringContainsString("dispatchEvent(new Event('change', { bubbles: true }))", $view);
    }
}
