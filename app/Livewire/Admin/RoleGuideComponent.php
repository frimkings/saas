<?php

namespace App\Livewire\Admin;

use App\Support\Access\Access;
use App\Support\Access\RoleGuide;
use Livewire\Component;

/**
 * Staff → What each role can do: read-only. Roles are built by the Nexus team and are the same
 * for every clinic; one person's access is changed on their Access page instead.
 */
class RoleGuideComponent extends Component
{
    public string $role = '';

    public function mount(): void
    {
        abort_unless(Access::can('roles.view'), 403);
    }

    public function render()
    {
        $labels = RoleGuide::labels();
        $shown = array_key_exists($this->role, $labels) ? [$this->role => $labels[$this->role]] : $labels;

        return view('livewire.admin.role-guide-component', [
            'labels' => $labels,
            'shown' => $shown,
            'matrix' => RoleGuide::matrix(),
        ])->layout('layouts.admin.admin-layout');
    }
}
