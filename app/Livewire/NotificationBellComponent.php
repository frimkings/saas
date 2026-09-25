<?php

namespace App\Livewire;

use App\Models\AppNotification;
use Livewire\Component;

class NotificationBellComponent extends Component
{
    public int $unreadCount = 0;

    public function mount(): void
    {
        $this->syncCount();
    }

    public function syncCount(): void
    {
        $this->unreadCount = auth()->check()
            ? AppNotification::forUser(auth()->id())->unread()->count()
            : 0;
    }

    /**
     * Mark a single notification as read; navigate if it has an action URL.
     */
    public function readAndGo(int $id): mixed
    {
        $notification = AppNotification::where('user_id', auth()->id())->find($id);

        if ($notification) {
            $notification->markRead();
            $this->syncCount();

            if ($notification->action_url) {
                return redirect($notification->action_url);
            }
        }

        return null;
    }

    public function markAllRead(): void
    {
        AppNotification::forUser(auth()->id())->unread()->update(['read_at' => now()]);
        $this->syncCount();
    }

    public function render()
    {
        $notifications = auth()->check()
            ? AppNotification::forUser(auth()->id())->latest()->limit(15)->get()
            : collect();

        return view('livewire.notification-bell-component', compact('notifications'));
    }
}
