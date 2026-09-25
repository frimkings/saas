<li class="nav-item dropdown" id="msg-dropdown-wrapper">
    <a class="nav-link" data-toggle="dropdown" href="#" role="button">
        <i class="far fa-comments"></i>
        <span id="msg-badge"
              class="badge badge-danger navbar-badge"
              style="{{ $unreadCount > 0 ? '' : 'display:none' }}">{{ $unreadCount }}</span>
    </a>

    <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right" wire:ignore>
        <span class="dropdown-item dropdown-header d-flex justify-content-between align-items-center">
            <span>Messages</span>
            <a href="{{ route('staff.messages') }}" class="text-sm text-primary">View All</a>
        </span>
        <div class="dropdown-divider"></div>

        @forelse($messages as $msg)
            <a href="{{ route('staff.messages') }}" class="dropdown-item">
                <div class="media">
                    <div class="media-body">
                        <h3 class="dropdown-item-title">
                            {{ $msg->sender->name ?? 'Unknown' }}
                            @if($msg->isUnread())
                                <span class="float-right text-sm text-primary">
                                    <i class="fas fa-circle" style="font-size:.5rem;vertical-align:middle"></i>
                                </span>
                            @endif
                        </h3>
                        <p class="text-sm">{{ \Illuminate\Support\Str::limit($msg->subject, 45) }}</p>
                        <p class="text-sm text-muted">
                            <i class="far fa-clock mr-1"></i>{{ $msg->created_at->diffForHumans() }}
                        </p>
                    </div>
                </div>
            </a>
            <div class="dropdown-divider"></div>
        @empty
            <div class="dropdown-item text-muted text-center py-3">
                <i class="far fa-envelope-open mr-1"></i>No messages
            </div>
            <div class="dropdown-divider"></div>
        @endforelse

        <a href="{{ route('staff.messages') }}" class="dropdown-item dropdown-footer">
            See All Messages
        </a>
    </div>
</li>

@once
@push('scripts')
<script>
(function () {
    function refreshMsgBadge() {
        fetch('/messages/unread-count', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var badge = document.getElementById('msg-badge');
            if (!badge) return;
            if (data.count > 0) {
                badge.textContent = data.count;
                badge.style.display = '';
            } else {
                badge.style.display = 'none';
            }
        })
        .catch(function () {});
    }
    setInterval(refreshMsgBadge, 15000);
})();
</script>
@endpush
@endonce
