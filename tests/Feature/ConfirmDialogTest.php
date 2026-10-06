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
        $this->assertStringNotContainsString('Swal', $html, 'Every layout uses the built-in dialog, not SweetAlert.');
        // admin/admin-layout renders the clinic layout.
        foreach (['clinic', 'platform', 'optical'] as $layout) {
            $this->assertStringContainsString("@include('layouts.partials.confirm-dialog')", File::get(resource_path("views/layouts/{$layout}.blade.php")), $layout);
        }
    }

    /** SweetAlert is being retired; only the till checkout, discount approval and session warning still use it. */
    public function test_sweetalert_is_left_only_in_the_shared_till_scripts(): void
    {
        $users = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php') && str_contains(File::get($file->getPathname()), 'Swal.'))
            ->map(fn ($file) => str_replace(DIRECTORY_SEPARATOR, '/', str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getPathname())))
            ->values()->all();

        // The session (idle warning, discount approvals) and till (POS checkout) scripts.
        $this->assertEqualsCanonicalizing(['layouts/partials/session-scripts.blade.php', 'layouts/partials/till-scripts.blade.php'], $users);
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
