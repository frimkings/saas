<div class="container-fluid py-4">

    {{-- Page header --}}
    <div class="row mb-4 align-items-center">
        <div class="col-md-8">
            <h4 class="font-weight-bold text-primary mb-1">Refund Logs</h4>
            <p class="text-muted small mb-0">
                Showing records from {{ $fromDate }} to {{ $toDate }}
                &mdash; {{ $logs->total() }} {{ Str::plural('entry', $logs->total()) }}
            </p>
        </div>
        <div class="col-md-4 text-md-right">
            <button wire:click="exportCsv" class="btn btn-sm btn-success shadow-none">
                <i class="fas fa-file-csv mr-1"></i> Export CSV
            </button>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body py-3">
            <div class="row no-gutters">
                <div class="col-md-3 px-1">
                    <label class="small font-weight-bold text-uppercase text-muted">Search</label>
                    <input wire:model.live.debounce.300ms="search" type="text"
                           class="form-control form-control-sm shadow-none"
                           placeholder="TXN ID or reason…">
                </div>
                <div class="col-md-3 px-1">
                    <label class="small font-weight-bold text-uppercase text-muted">Date Range</label>
                    <div class="input-group input-group-sm">
                        <x-date-range from="fromDate" to="toDate" presets="activity" />
                    </div>
                </div>
                <div class="col-md-3 px-1">
                    <label class="small font-weight-bold text-uppercase text-muted">Staff</label>
                    <select wire:model.live="staffId" class="form-control form-control-sm">
                        <option value="">All Staff</option>
                        @foreach($staff as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 px-1">
                    <label class="small font-weight-bold text-uppercase text-muted">Per page</label>
                    <select wire:model.live="perPage" class="form-control form-control-sm">
                        <option value="15">15</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                </div>
                <div class="col-md-1 px-1 d-flex align-items-end">
                    <button wire:click="resetFilters" class="btn btn-sm btn-block btn-secondary shadow-none">
                        Reset
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="card shadow-sm border-0">
        <div class="table-responsive" wire:loading.class="opacity-50">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr class="small text-uppercase font-weight-bold text-muted">
                        <th class="pl-4 border-0">#</th>
                        <th class="border-0">Transaction</th>
                        <th class="border-0">Initiated By</th>
                        <th class="border-0">Approved By</th>
                        <th class="border-0">Processed By</th>
                        <th class="border-0">Status</th>
                        <th class="border-0">Reason</th>
                        <th class="pr-4 border-0">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td class="pl-4 py-3 text-muted small">{{ $log->id }}</td>
                            <td>
                                <span class="font-weight-bold text-primary small">
                                    {{ optional($log->sale)->transaction_id ?? 'N/A' }}
                                </span>
                            </td>
                            <td class="small">{{ optional($log->initiator)->name ?? '—' }}</td>
                            <td class="small">{{ optional($log->approvedBy)->name ?? '—' }}</td>
                            <td class="small">{{ optional($log->processedBy)->name ?? '—' }}</td>
                            <td>
                                <span class="badge badge-{{ $log->status_color }} px-2 py-1">
                                    {{ $log->status }}
                                </span>
                            </td>
                            <td class="small text-muted" style="max-width:220px; word-break:break-word;">
                                {{ Str::limit($log->reason, 100) }}
                            </td>
                            <td class="pr-4 small text-nowrap">
                                {{ $log->created_at->format('M d, Y H:i') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="fas fa-undo-alt fa-2x mb-2 d-block opacity-50"></i>
                                No refund logs found for the selected filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
            <div class="card-footer bg-white border-top py-2">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

</div>
