<div class="clinic-ui ui-page">

    {{-- Page header --}}
    <div class="flex flex-wrap -mx-2 mb-6 items-center">
        <div class="w-full md:w-8/12 px-2">
            <h4 class="font-semibold text-teal-700 mb-1">Refund Logs</h4>
            <p class="text-slate-500 text-sm mb-0">
                Showing records from {{ $fromDate }} to {{ $toDate }}
                &mdash; {{ $logs->total() }} {{ Str::plural('entry', $logs->total()) }}
            </p>
        </div>
        <div class="w-full md:w-4/12 px-2 md:text-right">
            <button wire:click="exportCsv" class="btn ui-button ui-button-sm ui-button-primary shadow-none">
                <i class="fas fa-file-csv mr-1"></i> Export CSV
            </button>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0 mb-6">
        <div class="card-body p-4 py-4">
            <div class="flex flex-wrap mx-0">
                <div class="w-full md:w-3/12 px-1">
                    <label class="text-sm font-semibold uppercase text-slate-500">Search</label>
                    <input wire:model.live.debounce.300ms="search" type="text"
                           class="form-control ui-input ui-input-sm shadow-none"
                           placeholder="TXN ID or reason…">
                </div>
                <div class="w-full md:w-3/12 px-1">
                    <label class="text-sm font-semibold uppercase text-slate-500">Date Range</label>
                    <div class="flex items-stretch">
                        <x-date-range from="fromDate" to="toDate" presets="activity" />
                    </div>
                </div>
                <div class="w-full md:w-3/12 px-1">
                    <label class="text-sm font-semibold uppercase text-slate-500">Staff</label>
                    <select wire:model.live="staffId" class="form-control ui-input ui-input-sm">
                        <option value="">All Staff</option>
                        @foreach($staff as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full md:w-2/12 px-1">
                    <label class="text-sm font-semibold uppercase text-slate-500">Per page</label>
                    <select wire:model.live="perPage" class="form-control ui-input ui-input-sm">
                        <option value="15">15</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                </div>
                <div class="w-full md:w-1/12 px-1 flex items-end">
                    <button wire:click="resetFilters" class="btn ui-button ui-button-sm w-full ui-button-secondary shadow-none">
                        Reset
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0">
        <div class="ui-table-wrap" wire:loading.class="opacity-50">
            <table class="table ui-table align-middle mb-0">
                <thead class="bg-slate-50">
                    <tr class="text-sm uppercase font-semibold text-slate-500">
                        <th class="pl-6 border-0">#</th>
                        <th class="border-0">Transaction</th>
                        <th class="border-0">Initiated By</th>
                        <th class="border-0">Approved By</th>
                        <th class="border-0">Processed By</th>
                        <th class="border-0">Status</th>
                        <th class="border-0">Reason</th>
                        <th class="pr-6 border-0">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td class="pl-6 py-4 text-slate-500 text-sm">{{ $log->id }}</td>
                            <td>
                                <span class="font-semibold text-teal-700 text-sm">
                                    {{ optional($log->sale)->transaction_id ?? 'N/A' }}
                                </span>
                            </td>
                            <td class="text-sm">{{ optional($log->initiator)->name ?? '—' }}</td>
                            <td class="text-sm">{{ optional($log->approvedBy)->name ?? '—' }}</td>
                            <td class="text-sm">{{ optional($log->processedBy)->name ?? '—' }}</td>
                            <td>
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $log->status_color }} px-2 py-1">
                                    {{ $log->status }}
                                </span>
                            </td>
                            <td class="text-sm text-slate-500" style="max-width:220px; word-break:break-word;">
                                {{ Str::limit($log->reason, 100) }}
                            </td>
                            <td class="pr-6 text-sm whitespace-nowrap">
                                {{ $log->created_at->format('M d, Y H:i') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-12 text-slate-500">
                                <i class="fas fa-undo-alt fa-2x mb-2 block opacity-50"></i>
                                No refund logs found for the selected filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
            <div class="border-t px-4 bg-white border-slate-200 py-2">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

</div>
