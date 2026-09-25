<div wire:poll.30s="syncQueue">
    <style>
        .bg-primary-soft  { background-color: rgba(13,110,253,.08); color:#0d6efd; }
        .bg-success-subtle { background-color:#e6fcf5; color:#087f5b; border:1px solid #c3fae8; }
        .bg-danger-subtle  { background-color:#fff5f5; color:#c92a2a; border:1px solid #ffe3e3; }
        .pulse-dot { width:10px; height:10px; background:#40c057; border-radius:50%; display:inline-block; animation:pulse 2s infinite; }
        @keyframes pulse { 0%{box-shadow:0 0 0 0 rgba(64,192,87,.5);} 70%{box-shadow:0 0 0 8px rgba(64,192,87,0);} 100%{box-shadow:0 0 0 0 rgba(64,192,87,0);} }
        .avatar-box { width:42px; height:42px; background:#f1f3f5; border-radius:8px; display:flex; align-items:center; justify-content:center; font-weight:700; color:#495057; }
    </style>

    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-4 align-items-center">
                <div class="col-sm-6">
                    <h1 class="m-0 fw-bold">
                        Queue Manager
                        <span class="badge bg-primary-soft ms-2" style="font-size:.5em;">{{ $patients->total() }}</span>
                    </h1>
                </div>
                <div class="col-sm-6 text-end">
                    <span class="small text-muted me-3"><span class="pulse-dot me-1"></span> Live Sync</span>
                    <button wire:click="exportCSV" class="btn btn-outline-success rounded-pill px-3 me-2">
                        <i class="fas fa-file-csv me-1"></i> Export
                    </button>
                    <button wire:click="syncQueue" class="btn btn-primary rounded-pill px-4 shadow-sm">
                        <i class="fas fa-sync-alt me-1" wire:loading.class="fa-spin"></i> Sync
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Search Patient</label>
                            <input type="text" wire:model.live.debounce.300ms="searchTerm"
                                   class="form-control" placeholder="Name or Folder #...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted text-uppercase">Date Range (Clearance)</label>
                            <div class="input-group">
                                <x-date-range from="fromDate" to="toDate" presets="activity" />
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted text-uppercase d-block text-center">Doctor Status</label>
                            <div class="btn-group w-100 shadow-sm">
                                <button wire:click="$set('showSeen', false)"
                                        class="btn {{ !$showSeen ? 'btn-danger' : 'btn-outline-danger' }}">
                                    <i class="fas fa-clock mr-1"></i>Unseen
                                </button>
                                <button wire:click="$set('showSeen', true)"
                                        class="btn {{ $showSeen ? 'btn-success' : 'btn-outline-success' }}">
                                    <i class="fas fa-check mr-1"></i>Seen
                                </button>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <button wire:click="resetFilters" class="btn btn-light border w-100">
                                <i class="fas fa-undo mr-1"></i>Reset
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4 py-3 border-0">Patient Details</th>
                                <th class="border-0">PX Number</th>
                                <th class="border-0">Service</th>
                                <th class="border-0 text-center">Clearance Date</th>
                                <th class="border-0 text-center">Status</th>
                                <th class="border-0 text-end pe-4">Action</th>
                            </tr>
                        </thead>
                        <tbody wire:loading.class="opacity-50">
                            @forelse ($patients as $patient)
                                <tr>
                                    <td class="ps-4 py-3">
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-box me-3">{{ substr($patient->patient->name ?? '?', 0, 1) }}</div>
                                            <div>
                                                <div class="fw-bold">{{ $patient->patient->name ?? '—' }}</div>
                                                <small class="text-muted">{{ $patient->patient->contact ?? '' }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            {{ $patient->patient->pxnumber ?? '—' }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($patient->service)
                                            <span class="badge" style="background:#e3f2fd;color:#1565c0;font-size:.8rem;font-weight:600;">
                                                <i class="fas fa-concierge-bell mr-1" style="font-size:.7rem;"></i>
                                                {{ $patient->service->name }}
                                            </span>
                                            <br><small class="text-muted">{{ currency() }} {{ number_format($patient->service->selling_price, 2) }}</small>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </td>
                                    <td class="text-center fw-medium">
                                        {{ \Carbon\Carbon::parse($patient->clearance_date)->format('d M Y') }}
                                    </td>
                                    <td class="text-center">
                                        @if($patient->doctor_status)
                                            <span class="badge bg-success-subtle rounded-pill px-3">Attended</span>
                                        @else
                                            <span class="badge bg-danger-subtle rounded-pill px-3">Awaiting</span>
                                        @endif
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="{{ route('doctor.patient-records', $patient) }}"
                                           class="btn btn-sm btn-dark px-3 rounded-pill shadow-sm">
                                            Open File <i class="fas fa-chevron-right ms-1"></i>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        No records found for the selected criteria.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white border-0 py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted">Last update: {{ now()->format('h:i A') }}</span>
                        {{ $patients->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.addEventListener('play-notification-sound', () => {
            const audio = document.getElementById('clearance-notif-ping');
            if (audio) audio.play().catch(() => {});
        });
    </script>
</div>
