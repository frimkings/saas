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
        $this->unreadCount = auth()->check()
            ? StaffMessage::where('recipient_id', auth()->id())->whereNull('read_at')->count()
            : 0;
    }

    public function render()
    {
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
