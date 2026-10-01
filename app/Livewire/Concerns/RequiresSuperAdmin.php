<?php

namespace App\Livewire\Concerns;

/**
 * The Communications pages (messages, SMS credits, WhatsApp, broadcast) are for the clinic's
 * Super Admin, as they were inside Settings. Checked on mount: Livewire only accepts later
 * actions on a component this user was allowed to open.
 */
trait RequiresSuperAdmin
{
    public function mountRequiresSuperAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole('Super Admin'), 403);
    }
}
