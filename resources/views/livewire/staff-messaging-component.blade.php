<div>
    <div class="container-fluid">

        {{-- Page Header --}}
        <div class="row mb-3">
            <div class="col-12 d-flex justify-content-between align-items-center">
                <h4 class="mb-0">
                    <i class="far fa-envelope mr-2"></i>Messages
                    @if($unreadCount > 0)
                        <span class="badge badge-danger ml-1">{{ $unreadCount }}</span>
                    @endif
                </h4>
                {{-- Use wire:click so Livewire dispatches the browser event that scripts.blade.php listens for --}}
                <button type="button" class="btn btn-primary btn-sm" wire:click="openCompose">
                    <i class="fas fa-plus mr-1"></i>Compose
                </button>
            </div>
        </div>

        {{-- Thread View --}}
        @if($activeView === 'thread' && $threadData)

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        {{-- Use button elements; wire:click on <a href="#"> can conflict with Bootstrap nav --}}
                        <button type="button" wire:click="backToInbox"
                                class="btn btn-sm btn-outline-secondary mr-2">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                        <strong>{{ $threadData->subject }}</strong>
                    </div>
                    @if($threadData->sender_id === auth()->id())
                        <button type="button"
                                wire:click="deleteThread({{ $threadData->id }})"
                                onclick="return confirm('Delete this entire thread?')"
                                class="btn btn-sm btn-outline-danger">
                            <i class="fas fa-trash"></i>
                        </button>
                    @endif
                </div>

                <div class="card-body" style="max-height:55vh;overflow-y:auto;" id="thread-scroll">

                    @php $uid = auth()->id(); @endphp
                    {{-- Root message --}}
                    <div class="d-flex {{ $threadData->sender_id === $uid ? 'justify-content-end' : '' }} mb-3">
                        <div class="rounded p-3 {{ $threadData->sender_id === $uid ? 'bg-primary text-white' : 'bg-light' }}"
                             style="max-width:70%">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <small class="font-weight-bold">{{ $threadData->sender->name ?? 'Unknown' }}</small>
                                <small class="{{ $threadData->sender_id === $uid ? 'text-white-50' : 'text-muted' }} ml-3">
                                    {{ $threadData->created_at->diffForHumans() }}
                                </small>
                            </div>
                            <p class="mb-0" style="white-space:pre-wrap">{{ $threadData->body }}</p>
                        </div>
                    </div>

                    {{-- Replies --}}
                    @foreach($threadData->replies->sortBy('created_at') as $reply)
                        <div class="d-flex {{ $reply->sender_id === $uid ? 'justify-content-end' : '' }} mb-3">
                            <div class="rounded p-3 {{ $reply->sender_id === $uid ? 'bg-primary text-white' : 'bg-light' }}"
                                 style="max-width:70%">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <small class="font-weight-bold">{{ $reply->sender->name ?? 'Unknown' }}</small>
                                    <small class="{{ $reply->sender_id === $uid ? 'text-white-50' : 'text-muted' }} ml-3">
                                        {{ $reply->created_at->diffForHumans() }}
                                    </small>
                                </div>
                                <p class="mb-0" style="white-space:pre-wrap">{{ $reply->body }}</p>
                            </div>
                        </div>
                    @endforeach

                </div>

                {{-- Reply form --}}
                <div class="card-footer">
                    @error('replyBody')
                        <div class="alert alert-danger py-1 mb-2">{{ $message }}</div>
                    @enderror
                    <div class="input-group">
                        <textarea wire:model="replyBody"
                                  class="form-control"
                                  rows="2"
                                  placeholder="Write a reply…"></textarea>
                        <div class="input-group-append">
                            <button type="button" wire:click="sendReply" class="btn btn-primary">
                                <i class="fas fa-reply"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        @else

            {{-- Inbox / Sent List --}}
            <div class="card">
                <div class="card-header pb-0">
                    <ul class="nav nav-tabs card-header-tabs">
                        <li class="nav-item">
                            {{-- <button> avoids Bootstrap anchor-click conflicts with Livewire --}}
                            <button type="button"
                                    wire:click="switchView('inbox')"
                                    class="nav-link btn btn-link {{ $activeView !== 'sent' ? 'active' : '' }}"
                                    style="border-radius:0">
                                <i class="fas fa-inbox mr-1"></i>Inbox
                                @if($unreadCount > 0)
                                    <span class="badge badge-danger ml-1">{{ $unreadCount }}</span>
                                @endif
                            </button>
                        </li>
                        <li class="nav-item">
                            <button type="button"
                                    wire:click="switchView('sent')"
                                    class="nav-link btn btn-link {{ $activeView === 'sent' ? 'active' : '' }}"
                                    style="border-radius:0">
                                <i class="fas fa-paper-plane mr-1"></i>Sent
                            </button>
                        </li>
                    </ul>
                    <div class="mt-2 mb-2">
                        <input wire:model.live.debounce.300ms="search"
                               type="search"
                               class="form-control form-control-sm"
                               placeholder="Search messages…">
                    </div>
                </div>

                <div class="card-body p-0">
                    @if($threads && $threads->count() > 0)
                        <div class="list-group list-group-flush">
                            @foreach($threads as $thread)
                                @php
                                    $isMyUnread = $thread->read_at === null && $thread->recipient_id === auth()->id();
                                    $replyCount = $thread->replies->count();
                                    $latestReply = $thread->replies->sortByDesc('created_at')->first();
                                    $preview = \Illuminate\Support\Str::limit($latestReply ? $latestReply->body : $thread->body, 90);
                                    $other   = $activeView === 'sent' ? $thread->recipient : $thread->sender;
                                    $latestDate = ($latestReply ?? $thread)->created_at;
                                @endphp
                                <div class="list-group-item list-group-item-action {{ $isMyUnread ? 'font-weight-bold' : '' }}"
                                     style="cursor:pointer{{ $isMyUnread ? ';background:#f4f6fa' : '' }}"
                                     wire:click="openThread({{ $thread->id }})">
                                    <div class="d-flex justify-content-between">
                                        <span>
                                            @if($isMyUnread)
                                                <span class="text-primary mr-1" style="font-size:.55rem;vertical-align:middle">&#9679;</span>
                                            @endif
                                            {{ $other->name ?? 'Unknown' }}
                                        </span>
                                        <small class="text-muted">{{ $latestDate->diffForHumans() }}</small>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="{{ $isMyUnread ? '' : 'text-muted' }}">{{ $thread->subject }}</small>
                                        @if($replyCount > 0)
                                            <span class="badge badge-secondary badge-sm">
                                                {{ $replyCount }} {{ $replyCount === 1 ? 'reply' : 'replies' }}
                                            </span>
                                        @endif
                                    </div>
                                    <small class="text-muted d-block text-truncate">{{ $preview }}</small>
                                </div>
                            @endforeach
                        </div>
                        <div class="p-3">{{ $threads->links() }}</div>
                    @else
                        <div class="text-center text-muted py-5">
                            <i class="far fa-envelope-open fa-3x mb-3"></i>
                            <p class="mb-0">No messages found.</p>
                        </div>
                    @endif
                </div>
            </div>

        @endif

    </div>

    {{-- Compose Modal — opened via browser event from scripts.blade.php listener pattern --}}
    <div class="modal fade" id="composeModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-pen mr-2"></i>New Message</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>To</label>
                        <select wire:model.live="recipientId"
                                class="form-control @error('recipientId') is-invalid @enderror">
                            <option value="">Select recipient…</option>
                            @foreach($staffUsers as $staff)
                                <option value="{{ $staff->id }}">{{ $staff->name }}</option>
                            @endforeach
                        </select>
                        @error('recipientId')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input wire:model="composeSubject"
                               type="text"
                               class="form-control @error('composeSubject') is-invalid @enderror"
                               placeholder="Subject">
                        @error('composeSubject')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea wire:model="composeBody"
                                  class="form-control @error('composeBody') is-invalid @enderror"
                                  rows="5"
                                  placeholder="Write your message…"></textarea>
                        @error('composeBody')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" wire:click="sendMessage" class="btn btn-primary">
                        <i class="fas fa-paper-plane mr-1"></i>Send
                    </button>
                </div>
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

            // Livewire dispatches 'show-composeModal-form' from openCompose()
            window.addEventListener('show-composeModal-form', function () {
                $('#composeModal').modal('show');
            });

            // Livewire dispatches 'close-compose-modal' after sendMessage()
            window.addEventListener('close-compose-modal', function () {
                $('#composeModal').modal('hide');
            });
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
