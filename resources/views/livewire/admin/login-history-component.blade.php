<div class="content p-3">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="mb-0 text-primary font-weight-bold">Login History</h3>
                <small class="text-muted text-uppercase font-weight-bold">Staff access log</small>
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
                    <div class="col-md-4 mb-2">
                        <label class="small font-weight-bold text-muted">Search</label>
                        <input type="text" class="form-control" wire:model.live.debounce.400ms="search" placeholder="User, email, IP, browser...">
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="small font-weight-bold text-muted">User</label>
                        <select class="form-control" wire:model.live="userId">
                            <option value="">All users</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-2"><label class="small font-weight-bold text-muted d-block">Date</label><x-date-range from="fromDate" to="toDate" presets="activity" clearable /></div>
                    <div class="col-md-1 mb-2 d-flex align-items-end">
                        <button class="btn btn-light border btn-block" wire:click="resetFilters"><i class="fas fa-undo"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>User</th>
                            <th>IP Address</th>
                            <th>Browser / Device</th>
                            <th class="text-right">Login Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs as $log)
                            <tr>
                                <td>
                                    <strong>{{ $log->user->name ?? 'Unknown user' }}</strong><br>
                                    <small class="text-muted">{{ $log->user->email ?? '' }}</small>
                                </td>
                                <td><code>{{ $log->ip_address }}</code></td>
                                <td><small>{{ \Illuminate\Support\Str::limit($log->user_agent, 90) }}</small></td>
                                <td class="text-right">{{ optional($log->login_at)->format('M d, Y h:i A') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">No login records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white">{{ $logs->links() }}</div>
        </div>
    </div>
</div>
