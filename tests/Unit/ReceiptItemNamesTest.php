<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Receipts print item names in full (wrapping onto a second line), never cut off with "...". */
class ReceiptItemNamesTest extends TestCase
{
    public function test_receipt_templates_do_not_shorten_item_names(): void
    {
        $views = dirname(__DIR__, 2).'/resources/views/';
        foreach (['cashier/thermal-receipt.blade.php', 'cashier/visit-receipt.blade.php', 'livewire/receipt.blade.php'] as $file) {
            $this->assertStringNotContainsString('Str::limit', file_get_contents($views.$file), $file);
        }
        $pos = file_get_contents($views.'livewire/pos-component.blade.php');
        $this->assertStringNotContainsString("Str::limit(\$item['name']", $pos);
        $this->assertStringNotContainsString('item.name.substring', $pos);

        // The item column keeps its width and wraps, so quantities and prices stay aligned.
        $styles = file_get_contents($views.'cashier/partials/thermal-styles.blade.php');
        $this->assertMatchesRegularExpression('/\.col-item\s*\{[^}]*width:\s*47%;[^}]*overflow-wrap:\s*anywhere;/', $styles);
    }
}
