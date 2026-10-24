<?php

namespace App\Livewire;

use App\Models\StaffMessage;
use Livewire\Component;

class StaffMessagesDropdownComponent extends Component
{
    public int $unreadCount = 0;

    public function mount(): void
    {
        $this->syncCount();
    }

    public function syncCount(): void
    {
        $this->unreadCount = auth()->check() && \App\Services\LicenseService::has(\App\Support\Feature::STAFF_MESSAGES)
            ? StaffMessage::where('recipient_id', auth()->id())->whereNull('read_at')->count()
            : 0;
    }

    public function render()
    {
        // The staff inbox comes with Feature::STAFF_MESSAGES; without it the top-bar icon is left out.
        if (!\App\Services\LicenseService::has(\App\Support\Feature::STAFF_MESSAGES)) {
            return '<div></div>';
        }
        $messages = auth()->check()
            ? StaffMessage::with('sender')
                ->whereNull('parent_id')
                ->where('recipient_id', auth()->id())
                ->latest()
                ->limit(5)
                ->get()
            : collect();

        return view('livewire.staff-messages-dropdown-component', compact('messages'));
    }
}
