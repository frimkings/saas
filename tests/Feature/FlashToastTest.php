<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class FlashToastTest extends TestCase
{
    public function test_flash_messages_become_one_notify_toast_each(): void
    {
        session()->flash('success', 'Stock received.');
        session()->flash('status', 'Active branch changed to Main.');
        session()->flash('panel_message', 'Clinic suspended.');

        // A page and its layout both render the component; each message still fires once.
        $html = Blade::render('<x-ui.flash :map="[\'panel_message\' => \'warning\']" link="/matrix" link-label="View matrix" /><x-ui.flash />');

        $this->assertSame(1, substr_count($html, 'Stock received.'));
        $this->assertSame(1, substr_count($html, 'Active branch changed to Main.'));
        $this->assertStringContainsString('data-type="warning"', $html);
        $this->assertStringContainsString('data-link="/matrix"', $html);
        $this->assertStringContainsString("\$dispatch('notify'", $html);
    }

    public function test_nothing_renders_without_a_flash_message(): void
    {
        $this->assertSame('', trim(Blade::render('<x-ui.flash />')));
    }
}
