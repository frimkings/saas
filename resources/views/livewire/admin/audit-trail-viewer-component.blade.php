<div class="content p-3">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="mb-0 text-primary font-weight-bold">Audit Trail</h3>
                <small class="text-muted text-uppercase font-weight-bold">System activity and change history</small>
            </div>
            <button class="btn btn-success" wire:click="exportCsv">
                <i class="fas fa-file-csv mr-1"></i>Export CSV
            </button>
        </div>

        <ul class="nav nav-tabs mb-0">
            <li class="nav-item">
                <a href="#" wire:click.prevent="$set('showArchive', false)"
                   class="nav-link {{ !$showArchive ? 'active font-weight-bold' : 'text-muted' }}">
                    <i class="fas fa-list mr-1"></i> Active
                </a>
            </li>
            <li class="nav-item">
                <a href="#" wire:click.prevent="$set('showArchive', true)"
                   class="nav-link {{ $showArchive ? 'active font-weight-bold' : 'text-muted' }}">
                    <i class="fas fa-archive mr-1"></i> Archived
                </a>
            </li>
        </ul>

        <div class="card shadow-sm border-0 mb-3" style="border-top-left-radius:0">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-2"><label class="small font-weight-bold text-muted">Search</label><input class="form-control" wire:model.live.debounce.400ms="search" placeholder="Event, user, patient..."></div>
                    <div class="col-md-2 mb-2"><label class="small font-weight-bold text-muted">Event</label><select class="form-control" wire:model.live="event"><option value="">All</option>@foreach($events as $eventName)<option value="{{ $eventName }}">{{ ucwords(str_replace(['.','_'], [' — ', ' '], $eventName)) }}</option>@endforeach</select></div>
                    <div class="col-md-3 mb-2">
                        <label class="small font-weight-bold text-muted" for="audit-user-search">User</label>
                        <input id="audit-user-search" type="search" class="form-control mb-1"
                               wire:model.live.debounce.250ms="userSearch" placeholder="Search users..."
                               autocomplete="off" aria-label="Search users">
                        <select class="form-control" wire:model.live="userId" aria-label="Filter audit trail by user">
                            <option value="">All users</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}">{{ $user->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-2"><label class="small font-weight-bold text-muted d-block">Date</label><x-date-range from="fromDate" to="toDate" presets="activity" clearable /></div>
                </div>
                <button class="btn btn-light border btn-sm" wire:click="resetFilters"><i class="fas fa-undo mr-1"></i>Reset</button>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light"><tr><th>Event</th><th>Description</th><th>User / Patient</th><th>IP</th><th class="text-right">Time</th></tr></thead>
                    <tbody>
                        @forelse($audits as $audit)
                            @php
                                $eventBadge = match(true) {
                                    str_contains($audit->event, '.created') || str_contains($audit->event, '.restored') => 'badge-success',
                                    str_contains($audit->event, '.updated') || str_contains($audit->event, '.status_updated') || str_contains($audit->event, '.activated') => 'badge-info',
                                    str_contains($audit->event, '.deleted') || str_contains($audit->event, '.archived') || str_contains($audit->event, '.revoke') => 'badge-danger',
                                    str_contains($audit->event, 'login') || str_contains($audit->event, 'logout') => 'badge-secondary',
                                    str_contains($audit->event, 'report') || str_contains($audit->event, 'export') => 'badge-dark',
                                    default => 'badge-warning',
                                };
                            @endphp
                            <tr>
                                <td>
                                    <span class="badge {{ $eventBadge }}">{{ $this->formatEventLabel($audit->event) }}</span>
                                    <div class="small text-muted mt-1">{{ $audit->event }}</div>
                                </td>
                                <td>
                                    <strong>{{ $audit->description }}</strong>
                                    @php($changes = $this->formatAuditChanges($audit))
                                    @if(count($changes))
                                        <div class="mt-2">
                                            @foreach($changes as $change)
                                                <span class="badge badge-light border text-muted mr-1 mb-1">{{ $change }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div>{{ $audit->user->name ?? 'System' }}</div>
                                    <small class="text-muted">{{ $audit->patient ? $audit->patient->name.' | '.$audit->patient->pxnumber : 'No patient' }}</small>
                                </td>
                                <td><code>{{ $audit->ip_address }}</code></td>
                                <td class="text-right">{{ $audit->created_at->format('M d, Y h:i A') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No audit events found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white">{{ $audits->links() }}</div>
        </div>
    </div>
</div>
