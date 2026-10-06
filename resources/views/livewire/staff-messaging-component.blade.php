<div class="clinic-ui ui-page">
    <div class="w-full">

        {{-- Page Header --}}
        <div class="flex flex-wrap -mx-2 mb-4">
            <div class="w-full px-2 flex justify-between items-center">
                <h4 class="mb-0">
                    <i class="far fa-envelope mr-2"></i>Messages
                    @if($unreadCount > 0)
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800 ml-1">{{ $unreadCount }}</span>
                    @endif
                </h4>
                {{-- openCompose dispatches the browser event the script below listens for --}}
                <button type="button" class="btn ui-button ui-button-primary ui-button-sm" wire:click="openCompose">
                    <i class="fas fa-plus mr-1"></i>Compose
                </button>
            </div>
        </div>

        {{-- Thread View --}}
        @if($activeView === 'thread' && $threadData)

            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2 flex justify-between items-center">
                    <div>
                        
                        <button type="button" wire:click="backToInbox"
                                class="btn ui-button ui-button-sm ui-button-secondary mr-2">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                        <strong>{{ $threadData->subject }}</strong>
                    </div>
                    @if($threadData->sender_id === auth()->id())
                        <button type="button"
                                wire:click="deleteThread({{ $threadData->id }})"
                                wire:confirm="Delete this entire thread?"
                                class="btn ui-button ui-button-sm ui-button-danger">
                            <i class="fas fa-trash"></i>
                        </button>
                    @endif
                </div>

                <div class="card-body p-4" style="max-height:55vh;overflow-y:auto;" id="thread-scroll">

                    @php $uid = auth()->id(); @endphp
                    {{-- Root message --}}
                    <div class="flex {{ $threadData->sender_id === $uid ? 'justify-end' : '' }} mb-4">
                        <div class="rounded-md p-4 {{ $threadData->sender_id === $uid ? 'bg-teal-700 text-white text-white' : 'bg-slate-50' }}"
                             style="max-width:70%">
                            <div class="flex justify-between items-center mb-1">
                                <small class="font-semibold">{{ $threadData->sender->name ?? 'Unknown' }}</small>
                                <small class="{{ $threadData->sender_id === $uid ? 'text-white/70' : 'text-slate-500' }} ml-4">
                                    {{ $threadData->created_at->diffForHumans() }}
                                </small>
                            </div>
                            <p class="mb-0" style="white-space:pre-wrap">{{ $threadData->body }}</p>
                        </div>
                    </div>

                    {{-- Replies --}}
                    @foreach($threadData->replies->sortBy('created_at') as $reply)
                        <div class="flex {{ $reply->sender_id === $uid ? 'justify-end' : '' }} mb-4">
                            <div class="rounded-md p-4 {{ $reply->sender_id === $uid ? 'bg-teal-700 text-white text-white' : 'bg-slate-50' }}"
                                 style="max-width:70%">
                                <div class="flex justify-between items-center mb-1">
                                    <small class="font-semibold">{{ $reply->sender->name ?? 'Unknown' }}</small>
                                    <small class="{{ $reply->sender_id === $uid ? 'text-white/70' : 'text-slate-500' }} ml-4">
                                        {{ $reply->created_at->diffForHumans() }}
                                    </small>
                                </div>
                                <p class="mb-0" style="white-space:pre-wrap">{{ $reply->body }}</p>
                            </div>
                        </div>
                    @endforeach

                </div>

                {{-- Reply form --}}
                <div class="border-t border-slate-200 bg-slate-50 px-4 py-2">
                    @error('replyBody')
                        <div class="rounded-lg border px-3 text-sm border-red-200 bg-red-50 text-red-800 py-1 mb-2">{{ $message }}</div>
                    @enderror
                    <div class="flex items-stretch">
                        <textarea wire:model="replyBody"
                                  class="form-control ui-input"
                                  rows="2"
                                  placeholder="Write a reply…"></textarea>
                        <div class="flex">
                            <button type="button" wire:click="sendReply" class="btn ui-button ui-button-primary">
                                <i class="fas fa-reply"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        @else

            {{-- Inbox / Sent List --}}
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2 pb-0">
                    <ul class="flex flex-wrap border-b border-slate-200 card-header-tabs">
                        <li class="">
                            {{-- <button> avoids Bootstrap anchor-click conflicts with Livewire --}}
                            <button type="button"
                                    wire:click="switchView('inbox')"
                                    class="block px-3 py-2 btn ui-button ui-button-link {{ $activeView !== 'sent' ? 'active' : '' }}"
                                    style="border-radius:0">
                                <i class="fas fa-inbox mr-1"></i>Inbox
                                @if($unreadCount > 0)
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800 ml-1">{{ $unreadCount }}</span>
                                @endif
                            </button>
                        </li>
                        <li class="">
                            <button type="button"
                                    wire:click="switchView('sent')"
                                    class="block px-3 py-2 btn ui-button ui-button-link {{ $activeView === 'sent' ? 'active' : '' }}"
                                    style="border-radius:0">
                                <i class="fas fa-paper-plane mr-1"></i>Sent
                            </button>
                        </li>
                    </ul>
                    <div class="mt-2 mb-2">
                        <input wire:model.live.debounce.300ms="search"
                               type="search"
                               class="form-control ui-input ui-input-sm"
                               placeholder="Search messages…">
                    </div>
                </div>

                <div class="card-body p-0">
                    @if($threads && $threads->count() > 0)
                        <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
                            @foreach($threads as $thread)
                                @php
                                    $isMyUnread = $thread->read_at === null && $thread->recipient_id === auth()->id();
                                    $replyCount = $thread->replies->count();
                                    $latestReply = $thread->replies->sortByDesc('created_at')->first();
                                    $preview = \Illuminate\Support\Str::limit($latestReply ? $latestReply->body : $thread->body, 90);
                                    $other   = $activeView === 'sent' ? $thread->recipient : $thread->sender;
                                    $latestDate = ($latestReply ?? $thread)->created_at;
                                @endphp
                                <div class="list-group-item block w-full border-b border-slate-100 px-3 py-2 text-left hover:bg-slate-50 {{ $isMyUnread ? 'font-semibold' : '' }}"
                                     style="cursor:pointer{{ $isMyUnread ? ';background:#f4f6fa' : '' }}"
                                     wire:click="openThread({{ $thread->id }})">
                                    <div class="flex justify-between">
                                        <span>
                                            @if($isMyUnread)
                                                <span class="text-teal-700 mr-1" style="font-size:.55rem;vertical-align:middle">&#9679;</span>
                                            @endif
                                            {{ $other->name ?? 'Unknown' }}
                                        </span>
                                        <small class="text-slate-500">{{ $latestDate->diffForHumans() }}</small>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <small class="{{ $isMyUnread ? '' : 'text-slate-500' }}">{{ $thread->subject }}</small>
                                        @if($replyCount > 0)
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700 badge-sm">
                                                {{ $replyCount }} {{ $replyCount === 1 ? 'reply' : 'replies' }}
                                            </span>
                                        @endif
                                    </div>
                                    <small class="text-slate-500 block truncate">{{ $preview }}</small>
                                </div>
                            @endforeach
                        </div>
                        <div class="p-4">{{ $threads->links() }}</div>
                    @else
                        <div class="text-center text-slate-500 py-12">
                            <i class="far fa-envelope-open fa-3x mb-4"></i>
                            <p class="mb-0">No messages found.</p>
                        </div>
                    @endif
                </div>
            </div>

        @endif

    </div>

    {{-- Compose Modal — opened via browser event from scripts.blade.php listener pattern --}}
    {{-- Compose: shown and hidden from the component's browser events (script below). --}}
    <div id="composeModal" wire:ignore.self class="fixed inset-0 z-[1060] hidden items-center justify-center bg-slate-900/50 p-4" onclick="if (event.target === this) window.staffCompose(false)">
        <div class="w-full max-w-lg overflow-hidden rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="compose-title">
            <div class="ui-panel-heading">
                <h2 id="compose-title"><i class="fas fa-pen mr-2 text-teal-700" aria-hidden="true"></i>New message</h2>
                <button type="button" class="ui-button ui-button-secondary" onclick="window.staffCompose(false)" aria-label="Close dialog">Close</button>
            </div>
            <div class="p-4">
                    <div class="mb-4">
                        <label>To</label>
                        <select wire:model.live="recipientId"
                                class="form-control ui-input @error('recipientId') is-invalid @enderror">
                            <option value="">Select recipient…</option>
                            @foreach($staffUsers as $staff)
                                <option value="{{ $staff->id }}">{{ $staff->name }}</option>
                            @endforeach
                        </select>
                        @error('recipientId')
                            <div class="ui-error">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-4">
                        <label>Subject</label>
                        <input wire:model="composeSubject"
                               type="text"
                               class="form-control ui-input @error('composeSubject') is-invalid @enderror"
                               placeholder="Subject">
                        @error('composeSubject')
                            <div class="ui-error">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-4">
                        <label>Message</label>
                        <textarea wire:model="composeBody"
                                  class="form-control ui-input @error('composeBody') is-invalid @enderror"
                                  rows="5"
                                  placeholder="Write your message…"></textarea>
                        @error('composeBody')
                            <div class="ui-error">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-2">
                <button type="button" class="ui-button ui-button-secondary" onclick="window.staffCompose(false)">Cancel</button>
                <button type="button" wire:click="sendMessage" class="ui-button ui-button-primary"><i class="fas fa-paper-plane" aria-hidden="true"></i>Send</button>
            </div>
        </div>
    </div>

    {{--
        Inline scripts — placed inside root div so they execute on initial page load.
        Cannot use @push('scripts') because this layout has no @stack('scripts').
    --}}
    <script>
        (function () {
            if (window._staffMsgListenersRegistered) return;
            window._staffMsgListenersRegistered = true;

            window.staffCompose = function (open) {
                var dialog = document.getElementById('composeModal');
                if (!dialog) return;
                dialog.classList.toggle('hidden', !open);
                dialog.classList.toggle('flex', open);
            };
            // Livewire dispatches 'show-composeModal-form' from openCompose(), 'close-compose-modal' after sendMessage()
            window.addEventListener('show-composeModal-form', function () { window.staffCompose(true); });
            window.addEventListener('close-compose-modal', function () { window.staffCompose(false); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') window.staffCompose(false); });
        })();

        // Scroll thread to bottom whenever Livewire updates the DOM
        (function () {
            function scrollThread() {
                var el = document.getElementById('thread-scroll');
                if (el) el.scrollTop = el.scrollHeight;
            }
            scrollThread();
            document.addEventListener('livewire:init', function () {
                Livewire.hook('morph.updated', scrollThread);
            });
        })();
    </script>
</div>
