<div>
<div class="container-fluid py-4">

    {{-- Header --}}
    <div class="row mb-4 align-items-center">
        <div class="col-md-7">
            <h4 class="font-weight-bold text-primary mb-1">Refund Approvals</h4>
            <p class="text-muted small mb-0">Review, approve, reject and process customer refund requests.</p>
        </div>
        <div class="col-md-5 text-md-right">
            @if($pendingCount > 0)
                <span class="badge badge-warning px-3 py-2" style="font-size:0.85rem;">
                    <i class="fas fa-clock mr-1"></i> {{ $pendingCount }} pending
                </span>
            @endif
        </div>
    </div>

    {{-- Tabs --}}
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2">
            <div class="row no-gutters align-items-center">
                <div class="col-md-6">
                    <div class="btn-group btn-group-sm">
                        <button wire:click="switchTab('pending')"
                            class="btn {{ $activeTab === 'pending' ? 'btn-warning text-dark' : 'btn-outline-secondary' }}">
                            <i class="fas fa-clock mr-1"></i> Pending
                            @if($pendingCount > 0)
                                <span class="badge badge-dark ml-1">{{ $pendingCount }}</span>
                            @endif
                        </button>
                        <button wire:click="switchTab('approved')"
                            class="btn {{ $activeTab === 'approved' ? 'btn-primary' : 'btn-outline-secondary' }}">
                            <i class="fas fa-thumbs-up mr-1"></i> Approved
                        </button>
                        <button wire:click="switchTab('history')"
                            class="btn {{ $activeTab === 'history' ? 'btn-secondary' : 'btn-outline-secondary' }}">
                            <i class="fas fa-history mr-1"></i> History
                        </button>
                    </div>
                </div>
                <div class="col-md-3 px-1">
                    <input wire:model.live.debounce.300ms="search" type="text"
                           class="form-control form-control-sm shadow-none"
                           placeholder="Search TXN or reason…">
                </div>
                <div class="col-md-3 px-1">
                    <div class="input-group input-group-sm">
                        <x-date-range from="fromDate" to="toDate" presets="activity" clearable align="right" />
                    </div>
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
                        <th class="pl-4 border-0">Transaction</th>
                        <th class="border-0">Requested By</th>
                        <th class="border-0">Reason</th>
                        <th class="border-0">Date</th>
                        <th class="border-0 text-center">Status</th>
                        <th class="pr-4 border-0 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td class="pl-4 py-3">
                                <span class="font-weight-bold text-primary small">
                                    {{ optional($log->sale)->transaction_id ?? 'N/A' }}
                                </span>
                                <div class="small text-muted">
                                    {{ currency() }} {{ number_format(optional($log->sale)->total_amount ?? 0, 2) }}
                                </div>
                            </td>
                            <td class="small">{{ optional($log->initiator)->name ?? '—' }}</td>
                            <td class="small text-muted" style="max-width:220px; word-break:break-word;">
                                <span class="badge badge-light border mr-1">
                                    {{ strtoupper($log->request_type ?? 'refund') }}
                                </span>
                                @if($log->reason_code)
                                    <span class="badge badge-info">
                                        {{ \App\Models\RefundLog::REASON_CODES[$log->reason_code] ?? $log->reason_code }}
                                    </span>
                                @endif
                                <div class="mt-1">{{ Str::limit($log->reason, 90) }}</div>
                                @if($log->status === 'rejected' && $log->rejection_reason)
                                    <div class="text-danger mt-1">
                                        <i class="fas fa-times-circle mr-1"></i>
                                        <em>{{ Str::limit($log->rejection_reason, 80) }}</em>
                                    </div>
                                @endif
                            </td>
                            <td class="small text-nowrap">{{ $log->created_at->format('M d, Y H:i') }}</td>
                            <td class="text-center">
                                <span class="badge badge-{{ $log->status_color }} px-2 py-1">
                                    {{ $log->status_label }}
                                </span>
                                @if($log->approved_at)
                                    <div class="small text-muted mt-1">
                                        by {{ optional($log->approvedBy)->name ?? '—' }}
                                    </div>
                                @endif
                                @if($log->processed_at)
                                    <div class="small text-muted mt-1">
                                        by {{ optional($log->processedBy)->name ?? '—' }}
                                    </div>
                                @endif
                            </td>
                            <td class="pr-4 text-right text-nowrap">
                                @if($log->status === 'pending')
                                    {{-- Hidden data container read by openRefundPreview() --}}
                                    <div id="rdata-{{ $log->id }}" style="display:none">
                                        <span class="d-log-id">{{ $log->id }}</span>
                                        <span class="d-txn">{{ optional($log->sale)->transaction_id ?? 'N/A' }}</span>
                                        <span class="d-date">{{ $log->created_at->format('M d, Y h:i A') }}</span>
                                        <span class="d-patient">{{ optional($log->sale?->patient)->name ?? 'Walk-in' }}</span>
                                        <span class="d-contact">{{ optional($log->sale?->patient)->contact ?? '—' }}</span>
                                        <span class="d-requested-by">{{ optional($log->initiator)->name ?? '—' }}</span>
                                        <span class="d-reason">{{ $log->reason }}</span>
                                        <span class="d-total">{{ number_format(optional($log->sale)->total_amount ?? 0, 2) }}</span>
                                        <div class="d-items">
                                            @foreach(optional($log->sale)->items ?? [] as $item)
                                                <div class="d-item">
                                                    <span class="di-name">{{ $item->display_product_name }}</span>
                                                    <span class="di-qty">{{ $item->dispensed_quantity }}</span>
                                                    <span class="di-price">{{ number_format($item->selling_price, 2) }}</span>
                                                    <span class="di-sub">{{ number_format($item->subtotal, 2) }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <button type="button"
                                            wire:click="confirmApprove({{ $log->id }})"
                                            wire:confirm="Approve this refund request? It will be queued for processing."
                                            class="btn btn-sm btn-success shadow-none mr-1"
                                            title="Approve refund request">
                                        <i class="fas fa-check mr-1"></i> Approve
                                    </button>
                                    <button wire:click="openRejectModal({{ $log->id }})"
                                            class="btn btn-sm btn-outline-danger shadow-none"
                                            title="Reject">
                                        <i class="fas fa-times"></i>
                                    </button>
                                @elseif($log->status === 'approved')
                                    <button type="button"
                                            wire:click="process({{ $log->id }})"
                                            wire:confirm="Process this refund? This marks the sale as refunded and restores stock."
                                            class="btn btn-sm btn-primary shadow-none"
                                            title="Execute refund — restores stock and marks sale as refunded">
                                        <i class="fas fa-undo mr-1"></i> Process
                                    </button>
                                @elseif($log->status === 'processed')
                                    <a href="{{ route('refunds.receipt', $log) }}"
                                       target="_blank"
                                       class="btn btn-sm btn-outline-primary shadow-none">
                                        <i class="fas fa-receipt mr-1"></i> Receipt
                                    </a>
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-undo-alt fa-2x mb-2 d-block opacity-50"></i>
                                No refund requests found.
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

{{-- Preview & Approve Modal --}}
{{-- Populated entirely by openRefundPreview() — no Livewire round-trip needed. --}}
<div wire:ignore.self class="modal fade" id="previewModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-eye mr-2"></i>Review Refund Request</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>

            <div class="modal-body p-0">
                {{-- Sale header strip --}}
                <div class="bg-light px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <div class="font-weight-bold text-primary" style="font-size:1.05rem;">
                            #<span id="pm-txn"></span>
                        </div>
                        <div class="small text-muted">
                            <span id="pm-date"></span>
                            &nbsp;&bull;&nbsp;
                            Patient: <strong id="pm-patient"></strong>
                            <span id="pm-contact-wrap">&nbsp;&bull;&nbsp; <span id="pm-contact"></span></span>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="small text-muted">Requested by</div>
                        <div class="font-weight-bold small" id="pm-requested-by"></div>
                    </div>
                </div>

                {{-- Items table --}}
                <div class="px-4 py-3">
                    <p class="small font-weight-bold text-uppercase text-muted mb-2">Items in this sale</p>
                    <table class="table table-sm table-bordered mb-0 small">
                        <thead class="bg-light">
                            <tr>
                                <th>Product</th>
                                <th class="text-center">Qty</th>
                                <th class="text-right">Unit Price</th>
                                <th class="text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody id="pm-items-tbody">
                        </tbody>
                        <tfoot>
                            <tr class="font-weight-bold bg-light">
                                <td colspan="3" class="text-right">Total</td>
                                <td class="text-right">{{ currency() }} <span id="pm-total"></span></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- Refund reason --}}
                <div class="px-4 pb-3">
                    <p class="small font-weight-bold text-uppercase text-muted mb-1">Reason for Refund</p>
                    <div class="p-3 bg-light border rounded small" id="pm-reason"></div>
                </div>

                {{-- Warning --}}
                <div class="px-4 pb-3">
                    <div class="alert alert-warning border-0 small mb-0">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        Approving queues this refund for processing. Stock is restored and the sale is
                        marked <strong>REFUNDED</strong> only after the <em>Process</em> step.
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="pm-confirm-btn" onclick="submitRefundApproval()">
                    <i class="fas fa-check mr-1"></i> Confirm Approval
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Reject Modal --}}
<div class="modal fade {{ $showRejectModal ? 'show' : '' }}" id="rejectModal" tabindex="-1" role="dialog" style="{{ $showRejectModal ? 'display:block; background:rgba(0,0,0,.45);' : 'display:none;' }}" aria-modal="{{ $showRejectModal ? 'true' : 'false' }}">
    <div class="modal-dialog" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-times-circle mr-2"></i>Reject Refund Request</h5>
                <button type="button" class="close text-white" wire:click="closeRejectModal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="form-group mb-0">
                    <label class="font-weight-bold small">Reason for Rejection <span class="text-danger">*</span></label>
                    <textarea
                        wire:model="rejectionReason"
                        class="form-control @error('rejectionReason') is-invalid @enderror"
                        rows="3"
                        placeholder="Explain why this refund request is being rejected…"></textarea>
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
    let _currentRefundLogId = null;

    function openRefundPreview(dataId) {
        const c = document.getElementById(dataId);
        if (!c) return;

        _currentRefundLogId = c.querySelector('.d-log-id').textContent.trim();

        document.getElementById('pm-txn').textContent          = c.querySelector('.d-txn').textContent.trim();
        document.getElementById('pm-date').textContent         = c.querySelector('.d-date').textContent.trim();
        document.getElementById('pm-patient').textContent      = c.querySelector('.d-patient').textContent.trim();
        document.getElementById('pm-requested-by').textContent = c.querySelector('.d-requested-by').textContent.trim();
        document.getElementById('pm-reason').textContent       = c.querySelector('.d-reason').textContent.trim();
        document.getElementById('pm-total').textContent        = c.querySelector('.d-total').textContent.trim();

        const contact = c.querySelector('.d-contact').textContent.trim();
        const contactWrap = document.getElementById('pm-contact-wrap');
        if (contact && contact !== '—') {
            document.getElementById('pm-contact').textContent = contact;
            contactWrap.style.display = '';
        } else {
            contactWrap.style.display = 'none';
        }

        const tbody = document.getElementById('pm-items-tbody');
        tbody.innerHTML = '';
        const items = c.querySelectorAll('.d-item');
        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-2">No items found.</td></tr>';
        } else {
            items.forEach(function (item) {
                const name    = item.querySelector('.di-name').textContent.trim();
                const qty     = item.querySelector('.di-qty').textContent.trim();
                const price   = item.querySelector('.di-price').textContent.trim();
                const sub     = item.querySelector('.di-sub').textContent.trim();
                tbody.insertAdjacentHTML('beforeend',
                    '<tr>' +
                        '<td>' + name + '</td>' +
                        '<td class="text-center">' + qty + '</td>' +
                        '<td class="text-right">{{ currency() }} ' + price + '</td>' +
                        '<td class="text-right">{{ currency() }} ' + sub + '</td>' +
                    '</tr>'
                );
            });
        }

        $('#previewModal').modal('show');
    }

    function submitRefundApproval() {
        if (!_currentRefundLogId) return;
        const btn = document.getElementById('pm-confirm-btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Approving…';
        @this.call('confirmApprove', parseInt(_currentRefundLogId)).then(function () {
            $('#previewModal').modal('hide');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check mr-1"></i> Confirm Approval';
            _currentRefundLogId = null;
        });
    }

    function confirmRefundProcess(logId, txnId) {
        Swal.fire({
            title: 'Process Refund?',
            html: '<p>Transaction <strong>#' + txnId + '</strong> will be permanently marked <strong>REFUNDED</strong> and stock will be restored.</p>' +
                  '<p class="mt-2 mb-0"><small class="text-danger"><i class="fas fa-exclamation-triangle mr-1"></i>This cannot be undone.</small></p>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-undo mr-1"></i> Yes, Process Refund',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
        }).then(function (result) {
            if (!result.isConfirmed) return;
            Swal.fire({
                title: 'Processing Refund…',
                html: 'Restoring stock and marking sale as refunded.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: function () { Swal.showLoading(); }
            });
            @this.call('process', logId);
        });
    }

    window.addEventListener('refund-receipt-ready', function (event) {
        if (event.detail && event.detail.url) {
            window.open(event.detail.url, '_blank', 'width=420,height=700');
        }
    });

</script>
</div>
