<li class="nav-item dropdown" id="notif-bell-wrapper">
    {{-- Badge count is refreshed every 15 s via the JS block below --}}
    <a class="nav-link" data-toggle="dropdown" href="#" role="button" aria-label="Notifications">
        <i class="far fa-bell"></i>
        <span id="notif-badge"
              class="badge badge-danger navbar-badge"
              style="{{ $unreadCount > 0 ? '' : 'display:none' }}">
            {{ $unreadCount > 99 ? '99+' : $unreadCount }}
        </span>
    </a>

    <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right p-0">

        {{-- Header --}}
        <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom bg-light">
            <span class="font-weight-bold text-sm">
                @if($unreadCount > 0)
                    <span id="notif-header-count">{{ $unreadCount }}</span>
                    unread notification{{ $unreadCount === 1 ? '' : 's' }}
                @else
                    Notifications
                @endif
            </span>
            @if($unreadCount > 0)
                <button wire:click="markAllRead"
                        class="btn btn-sm btn-link p-0 text-muted text-xs"
                        title="Mark all as read">
                    Mark all read
                </button>
            @endif
        </div>

        {{-- List --}}
        <div style="max-height:360px;overflow-y:auto;">
            @forelse($notifications as $n)
                <a href="#"
                   wire:click.prevent="readAndGo({{ $n->id }})"
                   class="dropdown-item border-bottom py-2 {{ $n->isUnread() ? 'bg-light' : '' }}"
                   style="white-space:normal;">
                    <div class="d-flex align-items-start gap-2">
                        <div class="mr-2 pt-1" style="flex-shrink:0;width:22px;text-align:center;">
                            <i class="{{ $n->icon }} {{ $n->icon_color }}"></i>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <p class="mb-0 text-sm {{ $n->isUnread() ? 'font-weight-bold' : '' }}"
                               style="line-height:1.3;">
                                {{ $n->title }}
                            </p>
                            <p class="mb-0 text-xs text-muted" style="line-height:1.3;">
                                {{ $n->body }}
                            </p>
                            <p class="mb-0 text-xs text-muted mt-1">
                                <i class="far fa-clock mr-1"></i>{{ $n->created_at->diffForHumans() }}
                            </p>
                        </div>
                        @if($n->isUnread())
                            <span style="width:8px;height:8px;border-radius:50%;background:#007bff;
                                         flex-shrink:0;margin-top:4px;display:inline-block;"
                                  title="Unread"></span>
                        @endif
                    </div>
                </a>
            @empty
                <div class="text-center text-muted py-4 px-3">
                    <i class="far fa-bell-slash d-block mb-2" style="font-size:1.6rem;opacity:.35;"></i>
                    <small>No notifications yet</small>
                </div>
            @endforelse
        </div>

    </div>

{{-- Lightweight JS poller — updates only the badge number every 15 s without disturbing the Livewire component or the Bootstrap dropdown state --}}
<script>
(function () {
    function refreshBadge() {
        fetch('/notifications/unread-count', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (!data) return;
            var badge  = document.getElementById('notif-badge');
            var header = document.getElementById('notif-header-count');
            if (!badge) return;
            if (data.count > 0) {
                badge.textContent  = data.count > 99 ? '99+' : data.count;
                badge.style.display = '';
                if (header) header.textContent = data.count > 99 ? '99+' : data.count;
            } else {
                badge.style.display = 'none';
            }
        })
        .catch(function () { /* silently ignore network errors */ });
    }

    // Poll every 15 seconds after the page has loaded
    document.addEventListener('DOMContentLoaded', function () {
        setInterval(refreshBadge, 15000);
    });
})();
</script>
</li>
