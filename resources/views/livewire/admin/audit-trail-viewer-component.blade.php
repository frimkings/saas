<div class="clinic-ui ui-page">
    <div class="w-full">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="!mb-0 text-xl font-semibold text-slate-900">Audit Trail</h1>
                <p class="!mb-0 text-sm text-slate-500">Activity and changes in this clinic. Views and exports are kept for 30 days, changes for a year.</p>
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
                <label for="audit-search" class="{{ $fl }}">Search</label>
                <input id="audit-search" type="search" class="{{ $fs }}" wire:model.live.debounce.400ms="search" placeholder="Event, user, patient…">
            </div>
            <div class="min-w-[9rem] flex-1">
                <label for="audit-area" class="{{ $fl }}">Area</label>
                <select id="audit-area" class="{{ $fs }}" wire:model.live="area">
                    @foreach(\App\Livewire\Admin\AuditTrailViewerComponent::AREAS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="min-w-[9rem] flex-1">
                <label for="audit-event" class="{{ $fl }}">Event</label>
                <select id="audit-event" class="{{ $fs }}" wire:model.live="event">
                    <option value="">All events</option>
                    @foreach($events as $eventName)
                        <option value="{{ $eventName }}">{{ ucwords(str_replace(['.', '_'], [' — ', ' '], $eventName)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[9rem] flex-1">
                <label for="audit-user" class="{{ $fl }}">User</label>
                <select id="audit-user" class="{{ $fs }}" wire:model.live="userId">
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
                    <thead class=""><tr><th>Event</th><th>Description</th><th>User / Patient</th><th>IP</th><th class="text-right">Time</th></tr></thead>
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
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $eventBadge }}">{{ $this->formatEventLabel($audit->event) }}</span>
                                    <div class="text-sm text-slate-500 mt-1">{{ $audit->event }}</div>
                                </td>
                                <td>
                                    <strong>{{ $audit->description }}</strong>
                                    @php($changes = $this->formatAuditChanges($audit))
                                    @if(count($changes))
                                        <div class="mt-2">
                                            @foreach($changes as $change)
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 border border-slate-200 text-slate-500 mr-1 mb-1">{{ $change }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div>{{ $audit->user->name ?? 'System' }}</div>
                                    <small class="text-slate-500">{{ $audit->patient ? $audit->patient->name.' | '.$audit->patient->pxnumber : 'No patient' }}</small>
                                </td>
                                <td><code>{{ $audit->ip_address }}</code></td>
                                <td class="text-right">{{ $audit->created_at->format('M d, Y h:i A') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-slate-500 py-6">No audit events found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-2 bg-white">{{ $audits->links() }}</div>
        </div>
    </div>
</div>
