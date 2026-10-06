<div class="clinic-ui ui-page">
<div class="w-full">

    {{-- Tabs --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0 mb-4">
        <div class="card-body p-4 py-2">
            <div class="flex flex-wrap mx-0 items-center">
                <div class="w-full md:w-6/12 px-2">
                    <div class="inline-flex flex-wrap gap-1">
                        <button wire:click="switchTab('pending')"
                            class="btn ui-button {{ $activeTab === 'pending' ? 'ui-button-secondary text-slate-900' : 'ui-button-secondary' }}">
                            <i class="fas fa-clock mr-1"></i> Pending
                            @if($pendingCount > 0)
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-800 text-white ml-1">{{ $pendingCount }}</span>
                            @endif
                        </button>
                        <button wire:click="switchTab('approved')"
                            class="btn ui-button {{ $activeTab === 'approved' ? 'ui-button-primary' : 'ui-button-secondary' }}">
                            <i class="fas fa-thumbs-up mr-1"></i> Approved
                        </button>
                        <button wire:click="switchTab('history')"
                            class="btn ui-button {{ $activeTab === 'history' ? 'ui-button-secondary' : 'ui-button-secondary' }}">
                            <i class="fas fa-history mr-1"></i> History
                        </button>
                    </div>
                </div>
                <div class="w-full md:w-3/12 px-1">
                    <input wire:model.live.debounce.300ms="search" type="text"
                           class="form-control ui-input ui-input-sm shadow-none"
                           placeholder="Search TXN or reason…">
                </div>
                <div class="w-full md:w-3/12 px-1">
                    <div class="flex items-stretch">
                        <x-date-range from="fromDate" to="toDate" presets="activity" clearable align="right" />
                    </div>
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
                        <th class="pl-6 border-0">Transaction</th>
                        <th class="border-0">Requested By</th>
                        <th class="border-0">Reason</th>
                        <th class="border-0">Date</th>
                        <th class="border-0 text-center">Status</th>
                        <th class="pr-6 border-0 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td class="pl-6 py-4">
                                <span class="font-semibold text-teal-700 text-sm">
                                    {{ optional($log->sale)->transaction_id ?? 'N/A' }}
                                </span>
                                <div class="text-sm text-slate-500">
                                    {{ currency() }} {{ number_format(optional($log->sale)->total_amount ?? 0, 2) }}
                                </div>
                            </td>
                            <td class="text-sm">{{ optional($log->initiator)->name ?? '—' }}</td>
                            <td class="text-sm text-slate-500" style="max-width:220px; word-break:break-word;">
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600 border border-slate-200 mr-1">
                                    {{ strtoupper($log->request_type ?? 'refund') }}
                                </span>
                                @if($log->reason_code)
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-sky-100 text-sky-800">
                                        {{ \App\Models\RefundLog::REASON_CODES[$log->reason_code] ?? $log->reason_code }}
                                    </span>
                                @endif
                                <div class="mt-1">{{ Str::limit($log->reason, 90) }}</div>
                                @if($log->status === 'rejected' && $log->rejection_reason)
                                    <div class="text-red-700 mt-1">
                                        <i class="fas fa-times-circle mr-1"></i>
                                        <em>{{ Str::limit($log->rejection_reason, 80) }}</em>
                                    </div>
                                @endif
                            </td>
                            <td class="text-sm whitespace-nowrap">{{ $log->created_at->format('M d, Y H:i') }}</td>
                            <td class="text-center">
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $log->status_color }} px-2 py-1">
                                    {{ $log->status_label }}
                                </span>
                                @if($log->approved_at)
                                    <div class="text-sm text-slate-500 mt-1">
                                        by {{ optional($log->approvedBy)->name ?? '—' }}
                                    </div>
                                @endif
                                @if($log->processed_at)
                                    <div class="text-sm text-slate-500 mt-1">
                                        by {{ optional($log->processedBy)->name ?? '—' }}
                                    </div>
                                @endif
                            </td>
                            <td class="pr-6 text-right whitespace-nowrap">
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
                                            class="btn ui-button ui-button-sm ui-button-primary shadow-none mr-1"
                                            title="Approve refund request">
                                        <i class="fas fa-check mr-1"></i> Approve
                                    </button>
                                    <button wire:click="openRejectModal({{ $log->id }})"
                                            class="btn ui-button ui-button-sm ui-button-danger shadow-none"
                                            title="Reject">
                                        <i class="fas fa-times"></i>
                                    </button>
                                @elseif($log->status === 'approved')
                                    <button type="button"
                                            wire:click="process({{ $log->id }})"
                                            wire:confirm="Process this refund? This marks the sale as refunded and restores stock."
                                            class="btn ui-button ui-button-sm ui-button-primary shadow-none"
                                            title="Execute refund — restores stock and marks sale as refunded">
                                        <i class="fas fa-undo mr-1"></i> Process
                                    </button>
                                @elseif($log->status === 'processed')
                                    <a href="{{ route('refunds.receipt', $log) }}"
                                       target="_blank"
                                       class="btn ui-button ui-button-sm ui-button-secondary shadow-none">
                                        <i class="fas fa-receipt mr-1"></i> Receipt
                                    </a>
                                @else
                                    <span class="text-slate-500 text-sm">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-12 text-slate-500">
                                <i class="fas fa-undo-alt fa-2x mb-2 block opacity-50"></i>
                                No refund requests found.
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

{{-- Preview & Approve Modal --}}
{{-- Populated entirely by openRefundPreview() — no Livewire round-trip needed. --}}
<div wire:ignore.self class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 hidden" id="previewModal" tabindex="-1" role="dialog">
    <div class="mx-auto my-8 w-full max-w-3xl" role="document">
        <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl border-0 shadow">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-green-600 text-white">
                <h5 class="text-base font-semibold"><i class="fas fa-eye mr-2"></i>Review Refund Request</h5>
                <button type="button" class="text-xl leading-none hover:text-slate-800 text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>

            <div class="p-0">
                {{-- Sale header strip --}}
                <div class="bg-slate-50 px-6 py-4 border-b border-slate-200 flex justify-between items-center">
                    <div>
                        <div class="font-semibold text-teal-700" style="font-size:1.05rem;">
                            #<span id="pm-txn"></span>
                        </div>
                        <div class="text-sm text-slate-500">
                            <span id="pm-date"></span>
                            &nbsp;&bull;&nbsp;
                            Patient: <strong id="pm-patient"></strong>
                            <span id="pm-contact-wrap">&nbsp;&bull;&nbsp; <span id="pm-contact"></span></span>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-slate-500">Requested by</div>
                        <div class="font-semibold text-sm" id="pm-requested-by"></div>
                    </div>
                </div>

                {{-- Items table --}}
                <div class="px-6 py-4">
                    <p class="text-sm font-semibold uppercase text-slate-500 mb-2">Items in this sale</p>
                    <table class="table ui-table ui-table-sm mb-0 text-sm">
                        <thead class="bg-slate-50">
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
                            <tr class="font-semibold bg-slate-50">
                                <td colspan="3" class="text-right">Total</td>
                                <td class="text-right">{{ currency() }} <span id="pm-total"></span></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- Refund reason --}}
                <div class="px-6 pb-4">
                    <p class="text-sm font-semibold uppercase text-slate-500 mb-1">Reason for Refund</p>
                    <div class="p-4 bg-slate-50 border border-slate-200 rounded-md text-sm" id="pm-reason"></div>
                </div>

                {{-- Warning --}}
                <div class="px-6 pb-4">
                    <div class="rounded-lg border px-3 py-2 text-sm border-amber-200 bg-amber-50 text-amber-900 border-0 mb-0">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        Approving queues this refund for processing. Stock is restored and the sale is
                        marked <strong>REFUNDED</strong> only after the <em>Process</em> step.
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-4 py-3 bg-slate-50">
                <button type="button" class="btn ui-button ui-button-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn ui-button ui-button-primary" id="pm-confirm-btn" onclick="submitRefundApproval()">
                    <i class="fas fa-check mr-1"></i> Confirm Approval
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Reject Modal --}}
<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4  {{ $showRejectModal ? 'show' : '' }}" id="rejectModal" tabindex="-1" role="dialog" style="{{ $showRejectModal ? 'display:block; background:rgba(0,0,0,.45);' : 'display:none;' }}" aria-modal="{{ $showRejectModal ? 'true' : 'false' }}">
    <div class="mx-auto my-8 w-full max-w-lg" role="document">
        <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl border-0 shadow">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-red-600 text-white">
                <h5 class="text-base font-semibold"><i class="fas fa-times-circle mr-2"></i>Reject Refund Request</h5>
                <button type="button" class="text-xl leading-none hover:text-slate-800 text-white" wire:click="closeRejectModal"><span>&times;</span></button>
            </div>
            <div class="p-4">
                <div class="mb-0">
                    <label class="font-semibold text-sm">Reason for Rejection <span class="text-red-700">*</span></label>
                    <textarea
                        wire:model="rejectionReason"
                        class="form-control ui-input @error('rejectionReason') is-invalid @enderror"
                        rows="3"
                        placeholder="Explain why this refund request is being rejected…"></textarea>
                    @error('rejectionReason')
                        <div class="ui-error">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-4 py-3 bg-slate-50">
                <button type="button" class="btn ui-button ui-button-secondary" wire:click="closeRejectModal">Cancel</button>
                <button type="button" class="btn ui-button ui-button-danger" wire:click="confirmReject">
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
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-slate-500 py-2">No items found.</td></tr>';
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

        uiModal('previewModal', true);
    }

    function submitRefundApproval() {
        if (!_currentRefundLogId) return;
        const btn = document.getElementById('pm-confirm-btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Approving…';
        @this.call('confirmApprove', parseInt(_currentRefundLogId)).then(function () {
            uiModal('previewModal', false);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check mr-1"></i> Confirm Approval';
            _currentRefundLogId = null;
        });
    }

    function confirmRefundProcess(logId, txnId) {
        window.appConfirm('Transaction #' + txnId + ' will be permanently marked REFUNDED and stock will be restored.\nThis cannot be undone.',
            { title: 'Process refund?', confirmText: 'Yes, process refund', danger: true })
            .then(function (ok) {
                if (!ok) return;
                var done = window.appBusy('Processing refund…', 'Restoring stock and marking the sale as refunded.');
                @this.call('process', logId).finally(done);
            });
    }

    window.addEventListener('refund-receipt-ready', function (event) {
        if (event.detail && event.detail.url) {
            window.open(event.detail.url, '_blank', 'width=420,height=700');
        }
    });

</script>
</div>
