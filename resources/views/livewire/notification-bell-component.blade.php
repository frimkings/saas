{{-- Notification bell in the clinic layout's top bar. --}}
@php
    // Notifications store a Bootstrap colour class for their icon.
    $iconColours = ['text-primary' => 'text-blue-600', 'text-success' => 'text-green-600', 'text-warning' => 'text-amber-500', 'text-danger' => 'text-red-600', 'text-info' => 'text-sky-600', 'text-secondary' => 'text-slate-500'];
@endphp
<div x-data="{ open: false }" class="relative" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
    <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-haspopup="true" aria-label="Notifications"
            class="relative flex h-9 w-9 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100">
        <i class="far fa-bell text-base" aria-hidden="true"></i>
        <span id="notif-badge" @class(['absolute -right-0.5 -top-0.5 min-w-[1.1rem] rounded-full bg-red-500 px-1 text-center text-[10px] font-bold leading-[1.1rem] text-white', 'hidden' => $unreadCount === 0])>{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.100ms
         class="absolute right-0 z-30 mt-1 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-slate-200 bg-white text-sm shadow-lg">
        <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-4 py-2">
            <span class="font-semibold text-slate-800">
                @if($unreadCount > 0)<span id="notif-header-count">{{ $unreadCount }}</span> unread @else Notifications @endif
            </span>
            @if($unreadCount > 0)
                <button type="button" wire:click="markAllRead" class="text-xs text-slate-500 hover:text-slate-800">Mark all read</button>
            @endif
        </div>
        <div class="max-h-96 overflow-y-auto">
            @forelse($notifications as $n)
                <button type="button" wire:click="readAndGo({{ $n->id }})"
                        @class(['flex w-full items-start gap-3 border-b border-slate-100 px-4 py-2.5 text-left hover:bg-slate-50', 'bg-slate-50' => $n->isUnread()])>
                    <i class="{{ $n->icon }} {{ $iconColours[$n->icon_color] ?? 'text-slate-500' }} mt-0.5 w-5 shrink-0 text-center" aria-hidden="true"></i>
                    <span class="min-w-0 flex-1 leading-snug">
                        <span @class(['block text-slate-800', 'font-semibold' => $n->isUnread()])>{{ $n->title }}</span>
                        <span class="block text-xs text-slate-500">{{ $n->body }}</span>
                        <span class="mt-0.5 block text-xs text-slate-400">{{ $n->created_at->diffForHumans() }}</span>
                    </span>
                    @if($n->isUnread())<span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-teal-600" title="Unread"></span>@endif
                </button>
            @empty
                <p class="px-4 py-6 text-center text-slate-500"><i class="far fa-bell-slash mb-2 block text-2xl opacity-40" aria-hidden="true"></i>No notifications yet</p>
            @endforelse
        </div>
    </div>

    {{-- The badge number arrives with the page's shared poll (layouts/partials/session-scripts). --}}
    <script>
        (function () {
            if (!window.appPulse || window.clinicBellPulse) return;
            window.clinicBellPulse = true;
            window.appPulse.on('notifications', function (count) {
                var badge = document.getElementById('notif-badge');
                var header = document.getElementById('notif-header-count');
                if (!badge) return;
                badge.textContent = count > 99 ? '99+' : count;
                badge.classList.toggle('hidden', !(count > 0));
                if (header) header.textContent = count > 99 ? '99+' : count;
            });
        })();
    </script>
</div>
