<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ConfirmDialogTest extends TestCase
{
    public function test_layouts_replace_the_browser_confirm_with_the_styled_dialog(): void
    {
        $html = view('layouts.partials.confirm-dialog')->render();

        $this->assertStringContainsString('window.appConfirm', $html);
        $this->assertStringContainsString("hook('directive.init'", $html);
        foreach (['admin/admin-layout', 'doctor/doctor-layout', 'secretary/secretary-layout', 'platform', 'optical'] as $layout) {
            $this->assertStringContainsString("@include('layouts.partials.confirm-dialog')", File::get(resource_path("views/layouts/{$layout}.blade.php")), $layout);
        }
    }

    /** `onclick="return confirm()"` does not stop Livewire's own click handler, so Cancel still ran the action. */
    public function test_no_view_uses_the_native_confirm_box(): void
    {
        $offenders = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'))
            ->filter(fn ($file) => preg_match('/(?<![\w.])confirm\(/', File::get($file->getPathname())))
            ->map(fn ($file) => str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getPathname()))
            ->reject(fn ($path) => str_contains($path, 'confirm-dialog'))
            ->values()->all();

        $this->assertSame([], $offenders, 'Use wire:confirm or appConfirm() instead of confirm().');
    }
}
