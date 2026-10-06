<div class="clinic-ui ui-page">
    <div class="w-full">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="!mb-0 text-xl font-semibold text-slate-900">Login History</h1>
                <p class="!mb-0 text-sm text-slate-500">Who signed in to this clinic, from where and when. Kept for 30 days.</p>
            </div>
            <button type="button" class="ui-button ui-button-secondary" wire:click="exportCsv">
                <i class="fas fa-file-csv" aria-hidden="true"></i>Export CSV
            </button>
        </div>

        @php
            $fl = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500';
            $fs = 'ui-input !py-1.5 !text-sm';
        @endphp

        <div class="mb-4 flex flex-wrap items-end gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm lg:flex-nowrap">
            <div class="min-w-[10rem] flex-[2]">
                <label for="login-search" class="{{ $fl }}">Search</label>
                <input id="login-search" type="search" class="{{ $fs }}" wire:model.live.debounce.400ms="search" placeholder="User, email, IP, browser…">
            </div>
            <div class="min-w-[10rem] flex-[2]">
                <label for="login-user" class="{{ $fl }}">User</label>
                <select id="login-user" class="{{ $fs }}" wire:model.live="userId">
                    <option value="">All users</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-56 shrink-0">
                <span class="{{ $fl }}">Date</span>
                <x-date-range from="fromDate" to="toDate" presets="activity" clearable class="w-full" />
            </div>
            <button type="button" class="ui-button ui-button-secondary shrink-0 !px-2.5 !py-1.5" wire:click="resetFilters" title="Reset filters" aria-label="Reset filters"><i class="fas fa-undo" aria-hidden="true"></i></button>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="ui-table-wrap">
                <table class="table ui-table mb-0">
                    <thead class="">
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
                                    <small class="text-slate-500">{{ $log->user->email ?? '' }}</small>
                                </td>
                                <td><code>{{ $log->ip_address }}</code></td>
                                <td><small>{{ \Illuminate\Support\Str::limit($log->user_agent, 90) }}</small></td>
                                <td class="text-right">{{ optional($log->login_at)->format('M d, Y h:i A') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-slate-500 py-6">No login records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-2 bg-white">{{ $logs->links() }}</div>
        </div>
    </div>
</div>
