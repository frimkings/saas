<div data-livewire-root>
<div class="container-fluid py-4">

    {{-- Header --}}
    <div class="row mb-4 align-items-center">
        <div class="col-md-7">
            <h4 class="font-weight-bold text-primary mb-1">Clearance Revoke Approvals</h4>
            <p class="text-muted small mb-0">Review and approve or reject clearance revoke requests.</p>
        </div>
        <div class="col-md-5 text-md-right">
            @if($pendingCount > 0)
                <span class="badge badge-warning px-3 py-2" style="font-size:0.85rem;">
                    <i class="fas fa-clock mr-1"></i> {{ $pendingCount }} pending
                </span>
            @endif
        </div>
    </div>

    {{-- Tabs + Filters --}}
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2">
            <div class="row no-gutters align-items-center">
                <div class="col-md-5">
                    <div class="btn-group btn-group-sm">
                        <button wire:click="switchTab('pending')"
                            class="btn {{ $activeTab === 'pending' ? 'btn-warning text-dark' : 'btn-outline-secondary' }}">
                            <i class="fas fa-clock mr-1"></i> Pending
                            @if($pendingCount > 0)
                                <span class="badge badge-dark ml-1">{{ $pendingCount }}</span>
                            @endif
                        </button>
                        <button wire:click="switchTab('history')"
                            class="btn {{ $activeTab === 'history' ? 'btn-secondary' : 'btn-outline-secondary' }}">
                            <i class="fas fa-history mr-1"></i> History
                        </button>
                    </div>
                </div>
                <div class="col-md-4 px-1">
                    <input wire:model.live.debounce.300ms="search" type="text"
                           class="form-control form-control-sm shadow-none"
                           placeholder="Search patient or reason…">
                </div>
                @if($activeTab === 'history')
                    <div class="col-md-3 px-1">
                        <div class="input-group input-group-sm">
                            <x-date-range from="fromDate" to="toDate" presets="activity" align="right" />
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="card shadow-sm border-0">
        <div class="table-responsive" wire:loading.class="opacity-50">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr class="small text-uppercase font-weight-bold text-muted">
                        <th class="pl-4 border-0">Patient</th>
                        <th class="border-0">Clearance Date</th>
                        <th class="border-0">Requested By</th>
                        <th class="border-0">Reason</th>
                        <th class="border-0">Requested At</th>
                        <th class="border-0 text-center">Status</th>
                        <th class="pr-4 border-0 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        @php
                            $patient   = optional(optional($log->clearance)->patient);
                            $clearance = $log->clearance;
                        @endphp
                        <tr>
                            <td class="pl-4 py-3">
                                <span class="font-weight-bold small">{{ $patient->name ?? '—' }}</span>
                                @if($patient->pxnumber ?? false)
                                    <div class="small text-muted">{{ $patient->pxnumber }}</div>
                                @endif
                            </td>
                            <td class="small text-nowrap">
                                {{ optional($clearance)->clearance_date ?? '—' }}
                            </td>
                            <td class="small">{{ optional($log->requestedBy)->name ?? '—' }}</td>
                            <td class="small text-muted" style="max-width:220px; word-break:break-word;">
                                {{ Str::limit($log->reason, 90) }}
                                @if($log->status === 'rejected' && $log->rejection_reason)
                                    <div class="text-danger mt-1">
                                        <i class="fas fa-times-circle mr-1"></i>
                                        <em>{{ Str::limit($log->rejection_reason, 80) }}</em>
                                    </div>
                                @endif
                            </td>
                            <td class="small text-nowrap">
                                {{ $log->requested_at?->format('M d, Y H:i') ?? '—' }}
                            </td>
                            <td class="text-center">
                                <span class="badge badge-{{ $log->status_color }} px-2 py-1">
                                    {{ $log->status_label }}
                                </span>
                                @if($log->approved_at)
                                    <div class="small text-muted mt-1">
                                        by {{ optional($log->approvedBy)->name ?? '—' }}
                                    </div>
                                @endif
                                @if($log->rejected_at)
                                    <div class="small text-muted mt-1">
                                        by {{ optional($log->rejectedBy)->name ?? '—' }}
                                    </div>
                                @endif
                            </td>
                            <td class="pr-4 text-right text-nowrap">
                                @if($log->status === 'pending')
                                    @if($clearance && $clearance->consultation()->exists())
                                        <span class="badge badge-secondary small px-2 py-1"
                                              title="A consultation is now linked — cannot revoke">
                                            <i class="fas fa-lock mr-1"></i>Blocked
                                        </span>
                                    @else
                                        <button type="button"
                                                class="btn btn-sm btn-success shadow-none mr-1"
                                                wire:click="approve({{ $log->id }})"
                                                onclick="return confirm('Approve this clearance revoke request? The clearance will be permanently removed.')">
                                            <i class="fas fa-check mr-1"></i>Approve
                                        </button>
                                        <button wire:click="openRejectModal({{ $log->id }})"
                                                class="btn btn-sm btn-outline-danger shadow-none">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    @endif
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-undo-alt fa-2x mb-2 d-block opacity-50"></i>
                                No revoke requests found.
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

{{-- Reject Modal --}}
<div class="modal fade {{ $showRejectModal ? 'show' : '' }}" id="rejectRevokeModal" tabindex="-1" role="dialog" style="{{ $showRejectModal ? 'display:block; background:rgba(0,0,0,.45);' : 'display:none;' }}" aria-modal="{{ $showRejectModal ? 'true' : 'false' }}">
    <div class="modal-dialog" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-times-circle mr-2"></i>Reject Revoke Request</h5>
                <button type="button" class="close text-white" wire:click="closeRejectModal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="form-group mb-0">
                    <label class="font-weight-bold small">Reason for Rejection <span class="text-danger">*</span></label>
                    <textarea wire:model="rejectionReason"
                              class="form-control @error('rejectionReason') is-invalid @enderror"
                              rows="3"
                              placeholder="Explain why this revoke request is being rejected…"></textarea>
                    @error('rejectionReason')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" wire:click="closeRejectModal">Cancel</button>
                <button type="button" class="btn btn-danger" wire:click="confirmReject">
                    <i class="fas fa-times mr-1"></i> Confirm Rejection
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    function confirmApproveRevoke(logId, patientName) {
        Swal.fire({
            title: 'Approve Revoke?',
            html: 'Approve revoke for <strong>' + patientName + '</strong>?<br><span class="text-danger small">The clearance will be permanently removed.</span>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-check mr-1"></i> Yes, Approve',
            cancelButtonText: 'Cancel',
        }).then((result) => {
            if (result.isConfirmed) {
                @this.call('approve', logId);
            }
        });
    }
</script>
</div>
