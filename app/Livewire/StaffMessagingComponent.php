<?php

namespace App\Livewire;

use App\Models\StaffMessage;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;

class StaffMessagingComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public string $activeView = 'inbox'; // inbox | sent | thread
    public ?int   $activeThreadId = null;

    // Compose
    public ?int   $recipientId      = null;
    public string $composeSubject   = '';
    public string $composeBody      = '';

    // Reply
    public string $replyBody = '';

    // Search
    public string $search = '';

    public function updatedSearch(): void { $this->resetPage(); }

    public function switchView(string $view): void
    {
        $this->activeView     = $view;
        $this->activeThreadId = null;
        $this->resetPage();
    }

    public function openCompose(): void
    {
        $this->dispatch('show-composeModal-form');
    }

    public function openThread(int $threadId): void
    {
        $thread = StaffMessage::whereNull('parent_id')->find($threadId);
        if (!$thread) return;

        $userId = Auth::id();
        if ($thread->sender_id !== $userId && $thread->recipient_id !== $userId) return;

        $this->activeThreadId = $threadId;
        $this->activeView     = 'thread';
        $this->replyBody      = '';

        // Mark all unread messages in this thread that the current user received
        StaffMessage::where(function ($q) use ($threadId) {
                $q->where('id', $threadId)->orWhere('parent_id', $threadId);
            })
            ->where('recipient_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function backToInbox(): void
    {
        $this->activeView     = 'inbox';
        $this->activeThreadId = null;
    }

    public function sendMessage(): void
    {
        $this->validate([
            'recipientId'    => 'required|exists:users,id',
            'composeSubject' => 'required|string|max:255',
            'composeBody'    => 'required|string|max:5000',
        ]);

        StaffMessage::create([
            'sender_id'    => Auth::id(),
            'recipient_id' => $this->recipientId,
            'subject'      => $this->composeSubject,
            'body'         => $this->composeBody,
            'parent_id'    => null,
        ]);

        \App\Services\NotificationService::send(
            $this->recipientId,
            'staff_message',
            'New message: ' . $this->composeSubject,
            Auth::user()->name . ' sent you a message.',
            'far fa-envelope',
            'text-info',
            route('staff.messages')
        );

        $this->reset(['recipientId', 'composeSubject', 'composeBody']);
        $this->dispatch('close-compose-modal');
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Message sent.']);
    }

    public function sendReply(): void
    {
        $this->validate(['replyBody' => 'required|string|max:5000']);

        $thread = StaffMessage::whereNull('parent_id')->find($this->activeThreadId);
        if (!$thread) return;

        $userId = Auth::id();
        if ($thread->sender_id !== $userId && $thread->recipient_id !== $userId) return;

        $recipientId = $thread->sender_id === $userId ? $thread->recipient_id : $thread->sender_id;

        StaffMessage::create([
            'sender_id'    => $userId,
            'recipient_id' => $recipientId,
            'subject'      => 'Re: ' . $thread->subject,
            'body'         => $this->replyBody,
            'parent_id'    => $this->activeThreadId,
        ]);

        \App\Services\NotificationService::send(
            $recipientId,
            'staff_message',
            'Reply: ' . $thread->subject,
            Auth::user()->name . ' replied to your message.',
            'far fa-envelope',
            'text-info',
            route('staff.messages')
        );

        $this->replyBody = '';
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Reply sent.']);
    }

    public function deleteThread(int $threadId): void
    {
        $thread = StaffMessage::whereNull('parent_id')->find($threadId);
        if (!$thread || $thread->sender_id !== Auth::id()) return;

        StaffMessage::where('parent_id', $threadId)->delete();
        $thread->delete();

        if ($this->activeThreadId === $threadId) {
            $this->activeView     = 'inbox';
            $this->activeThreadId = null;
        }

        $this->dispatch('notify', ...['type' => 'info', 'message' => 'Thread deleted.']);
    }

    private function layoutForUser(): string
    {
        $user = Auth::user();

        return match(true) {
            $user->hasRole(['Super Admin', 'Manager']) => 'layouts.admin.admin-layout',
            $user->hasRole('Doctor')                   => 'layouts.doctor.doctor-layout',
            $user->hasRole('Secretary')                => 'layouts.secretary.secretary-layout',
            default                                    => 'layouts.secretary.secretary-layout',
        };
    }

    public function render()
    {
        $userId     = Auth::id();
        $staffUsers = User::where('id', '!=', $userId)->orderBy('name')->get(['id', 'name']);

        $unreadCount = StaffMessage::where('recipient_id', $userId)->whereNull('read_at')->count();

        $threadData = null;
        $threads    = null;

        if ($this->activeView === 'thread' && $this->activeThreadId) {
            $threadData = StaffMessage::with(['sender', 'recipient', 'replies.sender', 'replies.recipient'])
                ->whereNull('parent_id')
                ->find($this->activeThreadId);

            if (!$threadData || ($threadData->sender_id !== $userId && $threadData->recipient_id !== $userId)) {
                $threadData = null;
                // Render will fall through to inbox view below since activeView stays 'thread' but threadData is null
            }
        }

        if (in_array($this->activeView, ['inbox', 'thread'])) {
            $threads = StaffMessage::with(['sender', 'replies'])
                ->whereNull('parent_id')
                ->where('recipient_id', $userId)
                ->when($this->search, fn($q) => $q->where(function ($inner) {
                    $inner->where('subject', 'like', '%' . $this->search . '%')
                          ->orWhere('body', 'like', '%' . $this->search . '%')
                          ->orWhereHas('sender', fn($p) => $p->where('name', 'like', '%' . $this->search . '%'));
                }))
                ->latest()
                ->paginate(10);
        }

        if ($this->activeView === 'sent') {
            $threads = StaffMessage::with(['recipient', 'replies'])
                ->whereNull('parent_id')
                ->where('sender_id', $userId)
                ->when($this->search, fn($q) => $q->where(function ($inner) {
                    $inner->where('subject', 'like', '%' . $this->search . '%')
                          ->orWhere('body', 'like', '%' . $this->search . '%')
                          ->orWhereHas('recipient', fn($p) => $p->where('name', 'like', '%' . $this->search . '%'));
                }))
                ->latest()
                ->paginate(10);
        }

        return view('livewire.staff-messaging-component', compact('staffUsers', 'threads', 'threadData', 'unreadCount'))
            ->layout($this->layoutForUser());
    }
}
