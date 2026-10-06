@php
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500';
    $chip = 'inline-flex items-center whitespace-nowrap rounded-full px-3 py-0.5 text-xs font-semibold';
@endphp
<div wire:poll.30s="syncQueue" class="clinic-ui ui-page space-y-5">
    <div class="ui-heading">
        <div>
            <h1>Queue manager <span class="ml-1 rounded-full bg-teal-50 px-2 align-middle text-sm text-teal-800">{{ $patients->total() }}</span></h1>
            <p class="ui-muted"><span class="queue-pulse mr-1" aria-hidden="true"></span>Live sync every 30 seconds</p>
        </div>
        <div class="ui-actions">
            <button type="button" wire:click="exportCSV" class="ui-button ui-button-secondary"><i class="fas fa-file-csv" aria-hidden="true"></i>Export</button>
            <button type="button" wire:click="syncQueue" class="ui-button ui-button-primary"><i class="fas fa-sync-alt" wire:loading.class="fa-spin" aria-hidden="true"></i>Sync</button>
        </div>
    </div>

    <div class="ui-panel grid items-end gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[3fr_4fr_3fr_2fr]">
        <div>
            <label for="queue-search" class="{{ $label }}">Search patient</label>
            <input id="queue-search" type="search" wire:model.live.debounce.300ms="searchTerm" class="ui-input" placeholder="Name or folder #…">
        </div>
        <div>
            <span class="{{ $label }}">Date range (clearance)</span>
            <x-date-range from="fromDate" to="toDate" presets="activity" />
        </div>
        <div>
            <span class="{{ $label }}">Doctor status</span>
            <div class="grid grid-cols-2 overflow-hidden rounded-lg border border-slate-300 text-sm font-semibold" role="group" aria-label="Doctor status">
                <button type="button" wire:click="$set('showSeen', false)" aria-pressed="{{ ! $showSeen ? 'true' : 'false' }}"
                        class="px-3 py-2 {{ ! $showSeen ? 'bg-red-600 text-white' : 'bg-white text-red-700 hover:bg-red-50' }}"><i class="fas fa-clock mr-1" aria-hidden="true"></i>Unseen</button>
                <button type="button" wire:click="$set('showSeen', true)" aria-pressed="{{ $showSeen ? 'true' : 'false' }}"
                        class="border-l border-slate-300 px-3 py-2 {{ $showSeen ? 'bg-green-600 text-white' : 'bg-white text-green-700 hover:bg-green-50' }}"><i class="fas fa-check mr-1" aria-hidden="true"></i>Seen</button>
            </div>
        </div>
        <button type="button" wire:click="resetFilters" class="ui-button ui-button-secondary w-full"><i class="fas fa-undo" aria-hidden="true"></i>Reset</button>
    </div>

    <section class="ui-panel">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>PX number</th>
                        <th>Service</th>
                        <th class="text-center">Clearance date</th>
                        <th class="text-center">Status</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody wire:loading.class="opacity-50">
                    @forelse ($patients as $patient)
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 font-bold text-slate-600" aria-hidden="true">{{ substr($patient->patient->name ?? '?', 0, 1) }}</span>
                                    <div>
                                        <p class="font-semibold">{{ $patient->patient->name ?? '—' }}</p>
                                        <p class="text-xs text-slate-500">{{ $patient->patient->contact ?? '' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td><span class="rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 font-mono text-xs">{{ $patient->patient->pxnumber ?? '—' }}</span></td>
                            <td>
                                @if($patient->service)
                                    <span class="{{ $chip }} !rounded-md bg-blue-50 text-blue-800"><i class="fas fa-concierge-bell mr-1 text-[10px]" aria-hidden="true"></i>{{ $patient->service->name }}</span>
                                    <p class="text-xs text-slate-500">{{ currency() }} {{ number_format($patient->service->selling_price, 2) }}</p>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-center">{{ \Carbon\Carbon::parse($patient->clearance_date)->format('d M Y') }}</td>
                            <td class="text-center">
                                @if($patient->doctor_status)
                                    <span class="{{ $chip }} bg-green-50 text-green-800 ring-1 ring-green-200">Attended</span>
                                @else
                                    <span class="{{ $chip }} bg-red-50 text-red-800 ring-1 ring-red-200">Awaiting</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <a href="{{ route('doctor.patient-records', $patient) }}" class="ui-button ui-button-primary whitespace-nowrap">Open file <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-empty text-slate-500">No records found for the selected criteria.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3">
            <span class="text-xs text-slate-500">Last update: {{ now()->format('h:i A') }}</span>
            {{ $patients->links() }}
        </div>
    </section>

    <style>
        .queue-pulse { width: 10px; height: 10px; background: #40c057; border-radius: 50%; display: inline-block; animation: queue-pulse 2s infinite; }
        @keyframes queue-pulse { 0% { box-shadow: 0 0 0 0 rgba(64,192,87,.5); } 70% { box-shadow: 0 0 0 8px rgba(64,192,87,0); } 100% { box-shadow: 0 0 0 0 rgba(64,192,87,0); } }
        @media (prefers-reduced-motion: reduce) { .queue-pulse { animation: none; } }
    </style>

    <script>
        window.addEventListener('play-notification-sound', () => {
            const audio = document.getElementById('clearance-notif-ping');
            if (audio) audio.play().catch(() => {});
        });
    </script>
</div>
