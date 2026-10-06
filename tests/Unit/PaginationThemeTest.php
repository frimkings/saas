<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Bootstrap's CSS is gone, so a Bootstrap pager renders as a bare list of links. */
class PaginationThemeTest extends TestCase
{
    public function test_no_livewire_page_uses_the_bootstrap_pager(): void
    {
        $root = dirname(__DIR__, 2).'/app/Livewire';
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match("/paginationTheme\s*=\s*['\"]bootstrap/", file_get_contents($file->getPathname()))) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        $this->assertSame([], $offenders, 'These pages still use the Bootstrap pager.');
    }
}
