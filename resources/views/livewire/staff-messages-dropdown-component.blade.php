{{-- Staff messages menu in the clinic layout's top bar. --}}
<div x-data="{ open: false }" class="relative" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
    <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-haspopup="true" aria-label="Messages"
            class="relative flex h-9 w-9 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100">
        <i class="far fa-comments text-base" aria-hidden="true"></i>
        <span id="msg-badge" @class(['absolute -right-0.5 -top-0.5 min-w-[1.1rem] rounded-full bg-red-500 px-1 text-center text-[10px] font-bold leading-[1.1rem] text-white', 'hidden' => $unreadCount === 0])>{{ $unreadCount }}</span>
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.100ms
         class="absolute right-0 z-30 mt-1 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-slate-200 bg-white text-sm shadow-lg">
        <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-4 py-2">
            <span class="font-semibold text-slate-800">Messages</span>
            <a href="{{ route('staff.messages') }}" class="text-xs font-semibold text-teal-700 no-underline hover:underline">View all</a>
        </div>
        @forelse($messages as $msg)
            <a href="{{ route('staff.messages') }}" class="flex items-start gap-3 border-b border-slate-100 px-4 py-2.5 text-slate-800 no-underline hover:bg-slate-50">
                <span class="min-w-0 flex-1 leading-snug">
                    <span @class(['block', 'font-semibold' => $msg->isUnread()])>{{ $msg->sender->name ?? 'Unknown' }}</span>
                    <span class="block truncate text-slate-600">{{ $msg->subject }}</span>
                    <span class="mt-0.5 block text-xs text-slate-400">{{ $msg->created_at->diffForHumans() }}</span>
                </span>
                @if($msg->isUnread())<span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-teal-600" title="Unread"></span>@endif
            </a>
        @empty
            <p class="px-4 py-6 text-center text-slate-500"><i class="far fa-envelope-open mb-2 block text-2xl opacity-40" aria-hidden="true"></i>No messages</p>
        @endforelse
    </div>

    {{-- The badge number arrives with the page's shared poll (layouts/partials/session-scripts). --}}
    <script>
        (function () {
            if (!window.appPulse || window.clinicMessagesPulse) return;
            window.clinicMessagesPulse = true;
            window.appPulse.on('messages', function (count) {
                var badge = document.getElementById('msg-badge');
                if (!badge) return;
                badge.textContent = count;
                badge.classList.toggle('hidden', !(count > 0));
            });
        })();
    </script>
</div>
