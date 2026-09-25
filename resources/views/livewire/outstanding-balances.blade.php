<section>
<div>
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1 font-weight-bold text-dark">
                    <i class="fas fa-clock mr-2 text-warning"></i>Outstanding Balances
                </h4>
                <small class="text-muted">Orders on hold - collect balance to release items to customer</small>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body py-2">
                <div class="row align-items-center">
                    <div class="col-md-6 mb-2 mb-md-0">
                        <div class="input-group input-group-sm">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                            </div>
                            <input type="text"
                                   class="form-control"
                                   placeholder="Search by patient name, PX number or transaction ID..."
                                   wire:model.live.debounce.300ms="searchQuery">
                            @if($searchQuery)
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" wire:click="$set('searchQuery','')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="col-md-2 ml-auto text-right">
                        <select class="form-control form-control-sm" wire:model.live="perPage">
                            <option value="10">10 / page</option>
                            <option value="15">15 / page</option>
                            <option value="25">25 / page</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:.875rem;">
                        <thead class="thead-dark">
                            <tr>
                                <th>Date</th>
                                <th>Transaction ID</th>
                                <th>Patient</th>
                                <th>Items</th>
                                <th class="text-right">Total</th>
                                <th class="text-right">Paid</th>
                                <th class="text-right">Balance</th>
                                <th>Progress</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($balances as $sale)
                                @php
                                    $balance = max(0, (float) $sale->total_amount - (float) $sale->amount_paid);
                                    $pct = $sale->total_amount > 0
                                        ? round(((float) $sale->amount_paid / (float) $sale->total_amount) * 100)
                                        : 0;
                                @endphp
                                <tr>
                                    <td class="text-muted text-nowrap">
                                        {{ $sale->created_at->format('d M Y') }}<br>
                                        <small>{{ $sale->created_at->format('h:i A') }}</small>
                                    </td>
                                    <td><code class="text-primary">{{ $sale->transaction_id }}</code></td>
                                    <td>
                                        @if($sale->patient)
                                            <div class="font-weight-bold">{{ $sale->patient->name }}</div>
                                            <small class="text-muted">{{ $sale->patient->pxnumber }}</small>
                                        @else
                                            <span class="text-muted">Walk-in</span>
                                        @endif
                                    </td>
                                    <td>
                                        @foreach($sale->items->take(2) as $item)
                                            <small class="d-block text-truncate" style="max-width:140px;">
                                                {{ $item->product->name ?? 'N/A' }}
                                                <span class="text-muted">x{{ $item->prescribed_quantity }}</span>
                                            </small>
                                        @endforeach
                                        @if($sale->items->count() > 2)
                                            <small class="text-muted">+{{ $sale->items->count() - 2 }} more</small>
                                        @endif
                                    </td>
                                    <td class="text-right font-weight-bold">{{ currency() }} {{ number_format($sale->total_amount, 2) }}</td>
                                    <td class="text-right text-success font-weight-bold">{{ currency() }} {{ number_format($sale->amount_paid, 2) }}</td>
                                    <td class="text-right text-danger font-weight-bold">{{ currency() }} {{ number_format($balance, 2) }}</td>
                                    <td style="min-width:110px;">
                                        <div class="progress" style="height:8px;">
                                            <div class="progress-bar bg-warning" style="width:{{ min(100, $pct) }}%"></div>
                                        </div>
                                        <small class="text-muted">{{ $pct }}% paid</small>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-info mr-1"
                                                wire:click.prevent="openHistory({{ $sale->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="openHistory({{ $sale->id }})"
                                                title="View payment history">
                                            <i class="fas fa-history"></i>
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-warning"
                                                wire:click.prevent="openCollect({{ $sale->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="openCollect({{ $sale->id }})"
                                                title="Collect payment">
                                            <i class="fas fa-plus-circle mr-1"></i>Collect
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="fas fa-check-circle fa-3x text-success mb-3 d-block"></i>
                                        No outstanding balances.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($balances->hasPages())
                <div class="card-footer">{{ $balances->links() }}</div>
            @endif
        </div>
    </div>

    {{-- ── Payment History Modal ─────────────────────────────────────────── --}}
    @if($showHistoryModal && $historyForSaleId && $historyForSale)
    <div class="modal show d-block outstanding-modal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered outstanding-modal__dialog">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title font-weight-bold">
                        <i class="fas fa-history mr-2"></i>Payment History
                        &mdash; <code class="text-white">{{ $historyForSale->transaction_id }}</code>
                    </h5>
                    <button type="button" class="close text-white" wire:click="closeHistoryModal"><span>&times;</span></button>
                </div>
                <div class="modal-body">

                    {{-- Summary row --}}
                    <div class="row mb-4">
                        <div class="col-md-4 mb-2 mb-md-0">
                            <div class="border bg-light text-center p-3">
                                <div class="h5 font-weight-bold text-dark mb-0">{{ currency() }} {{ number_format($historyForSale->total_amount, 2) }}</div>
                                <small class="text-muted">Order Total</small>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2 mb-md-0">
                            <div class="border text-center p-3" style="background:#eaf7ee;">
                                <div class="h5 font-weight-bold text-success mb-0">{{ currency() }} {{ number_format($historyForSale->amount_paid, 2) }}</div>
                                <small class="text-muted">Total Paid</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border text-center p-3" style="background:#fdecee;">
                                <div class="h5 font-weight-bold text-danger mb-0">{{ currency() }} {{ number_format(max(0, $historyForSale->total_amount - $historyForSale->amount_paid), 2) }}</div>
                                <small class="text-muted">Remaining</small>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <span class="text-muted small font-weight-bold">Patient:</span>
                        <span class="ml-1 font-weight-bold">{{ $historyForSale->customer_display_name }}</span>
                        @if($historyForSale->patient)
                            <span class="text-muted small ml-2">{{ $historyForSale->patient->pxnumber }}</span>
                        @endif
                    </div>

                    {{-- Transactions table --}}
                    @if($historyForSale->paymentTransactions->isEmpty())
                        <div class="alert alert-secondary">
                            <i class="fas fa-info-circle mr-1"></i> No payment transactions recorded yet.
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="thead-light">
                                    <tr class="small text-uppercase text-muted font-weight-bold">
                                        <th>#</th>
                                        <th>Date &amp; Time</th>
                                        <th>Method</th>
                                        <th>Collected By</th>
                                        <th>Notes</th>
                                        <th class="text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($historyForSale->paymentTransactions as $i => $txn)
                                    <tr>
                                        <td class="text-muted">{{ $i + 1 }}</td>
                                        <td class="text-nowrap">
                                            {{ $txn->created_at->format('d M Y') }}<br>
                                            <small class="text-muted">{{ $txn->created_at->format('h:i A') }}</small>
                                        </td>
                                        <td>
                                            <span class="badge badge-secondary">{{ ucfirst($txn->payment_method) }}</span>
                                        </td>
                                        <td>{{ $txn->collectedBy->name ?? '—' }}</td>
                                        <td class="text-muted small">{{ $txn->notes ?? '—' }}</td>
                                        <td class="text-right font-weight-bold text-success">+{{ currency() }} {{ number_format($txn->amount, 2) }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td colspan="5" class="text-right font-weight-bold text-muted small">Total Paid</td>
                                        <td class="text-right font-weight-bold text-success">
                                            {{ currency() }} {{ number_format($historyForSale->paymentTransactions->sum('amount'), 2) }}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" wire:click="closeHistoryModal">Close</button>
                    <button type="button"
                            class="btn btn-warning"
                            wire:click="switchToCollectFromHistory">
                        <i class="fas fa-plus-circle mr-1"></i>Collect Payment
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    @if($showModal && $selectedSaleId && $selectedSale)
    <div class="modal show d-block outstanding-modal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered outstanding-modal__dialog">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title text-dark font-weight-bold">
                        <i class="fas fa-wallet mr-2"></i>Collect Payment
                    </h5>
                    <button type="button" class="close" wire:click="closeModal"><span>&times;</span></button>
                </div>

                <div class="modal-body">
                    <div class="row mb-4">
                        <div class="col-md-4 mb-2 mb-md-0">
                            <div class="border bg-light text-center p-3">
                                <div class="h4 font-weight-bold text-dark mb-0">{{ currency() }} {{ number_format($selectedSale->total_amount, 2) }}</div>
                                <small class="text-muted">Order Total</small>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2 mb-md-0">
                            <div class="border text-center p-3" style="background:#eaf7ee;">
                                <div class="h4 font-weight-bold text-success mb-0">{{ currency() }} {{ number_format($selectedSale->amount_paid, 2) }}</div>
                                <small class="text-muted">Already Paid</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border text-center p-3" style="background:#fdecee;">
                                <div class="h4 font-weight-bold text-danger mb-0">{{ currency() }} {{ number_format(max(0, (float) $selectedSale->total_amount - (float) $selectedSale->amount_paid), 2) }}</div>
                                <small class="text-muted">Balance Due</small>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted mb-1">Patient</label>
                            <div class="font-weight-bold">{{ $selectedSale->customer_display_name }}</div>
                            @if($selectedSale->patient)
                                <small class="text-muted">{{ $selectedSale->patient->pxnumber }}</small>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted mb-1">Transaction ID</label>
                            <div><code class="text-primary">{{ $selectedSale->transaction_id }}</code></div>
                            <small class="text-muted">{{ $selectedSale->created_at->format('d M Y, h:i A') }}</small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted mb-1">Items on Hold</label>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-right">Price</th>
                                        <th class="text-right">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($selectedSale->items as $item)
                                    <tr>
                                        <td>{{ $item->product->name ?? 'N/A' }}</td>
                                        <td class="text-center">{{ $item->prescribed_quantity }}</td>
                                        <td class="text-right">{{ currency() }} {{ number_format($item->selling_price, 2) }}</td>
                                        <td class="text-right">{{ currency() }} {{ number_format($item->subtotal, 2) }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    @if($selectedSale->paymentTransactions->isNotEmpty())
                    <div class="mb-3">
                        <label class="form-label text-muted mb-1">Payment History</label>
                        @foreach($selectedSale->paymentTransactions as $txn)
                            <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                <span class="text-muted" style="font-size:.8rem;">
                                    {{ $txn->created_at->format('d M Y, h:i A') }}
                                    - {{ ucfirst($txn->payment_method) }}
                                    @if($txn->collectedBy)
                                        <span class="text-muted">({{ $txn->collectedBy->name }})</span>
                                    @endif
                                    @if($txn->notes)
                                        <em class="text-muted"> | {{ $txn->notes }}</em>
                                    @endif
                                </span>
                                <span class="font-weight-bold text-success">+{{ currency() }} {{ number_format($txn->amount, 2) }}</span>
                            </div>
                        @endforeach
                    </div>
                    @endif

                    <hr>

                    <div class="row">
                        <div class="col-md-5 mb-3">
                            <label class="form-label font-weight-bold">Amount to Collect <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">{{ currency() }}</span>
                                </div>
                                <input type="number"
                                       class="form-control @error('collectAmount') is-invalid @enderror"
                                       wire:model.live.debounce.300ms="collectAmount"
                                       step="0.01"
                                       min="0.01"
                                       max="{{ max(0, (float) $selectedSale->total_amount - (float) $selectedSale->amount_paid) }}"
                                       placeholder="0.00">
                            </div>
                            @error('collectAmount')
                                <div class="text-danger" style="font-size:.8rem;">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label font-weight-bold">Payment Method</label>
                            <select class="form-control" wire:model.live="paymentMethod">
                                <option value="cash">Cash</option>
                                <option value="momo">Mobile Money</option>
                                <option value="card">Card</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold">Notes</label>
                            <input type="text" class="form-control" wire:model.live.debounce.300ms="paymentNotes" placeholder="Optional">
                        </div>
                    </div>

                    @php
                        $newBalance = max(0, (float) $selectedSale->total_amount - (float) $selectedSale->amount_paid - (float) $collectAmount);
                        $willFullyPay = (float) $collectAmount > 0 && $newBalance <= 0.001;
                    @endphp
                    @if((float) $collectAmount > 0)
                    <div class="alert {{ $willFullyPay ? 'alert-success' : 'alert-info' }} mt-3 py-2">
                        @if($willFullyPay)
                            <i class="fas fa-check-circle mr-1"></i>
                            <strong>Fully paid!</strong> Items will be released to the customer.
                        @else
                            <i class="fas fa-info-circle mr-1"></i>
                            Remaining balance after this payment: <strong>{{ currency() }} {{ number_format($newBalance, 2) }}</strong>
                        @endif
                    </div>
                    @endif
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeModal">Cancel</button>
                    <button type="button"
                            class="btn {{ $willFullyPay ?? false ? 'btn-success' : 'btn-warning' }}"
                            @if($willFullyPay ?? false)
                                onclick="window.releasedReceiptWindow = window.open('about:blank', '_blank', 'width=302,height=650')"
                            @endif
                            wire:click="collectPayment"
                            wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="collectPayment">
                            <i class="fas fa-check mr-1"></i>
                            {{ $willFullyPay ?? false ? 'Record & Release Items' : 'Record Payment' }}
                        </span>
                        <span wire:loading wire:target="collectPayment">
                            <i class="fas fa-spinner fa-spin mr-1"></i>Saving...
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

<script>
document.addEventListener('livewire:init', function () {
    window.addEventListener('print-released-receipt', function (event) {
        const receiptWindow = window.releasedReceiptWindow && !window.releasedReceiptWindow.closed
            ? window.releasedReceiptWindow
            : window.open('about:blank', '_blank', 'width=302,height=650');

        if (!receiptWindow) {
            alert('Please allow popups for this site to print the receipt.');
            return;
        }

        receiptWindow.location.href = event.detail.url;
        receiptWindow.onload = function () {
            setTimeout(function () {
                receiptWindow.focus();
                receiptWindow.print();
                window.releasedReceiptWindow = null;
            }, 500);
        };
    });
});
</script>

<style>
.outstanding-modal {
    background: rgba(0,0,0,0.5);
    z-index: 10500;
    overflow-y: auto;
    padding: 1rem;
}
.outstanding-modal__dialog {
    max-height: calc(100vh - 2rem);
}
.outstanding-modal .modal-content {
    max-height: calc(100vh - 2rem);
    display: flex;
    flex-direction: column;
}
.outstanding-modal .modal-body {
    overflow-y: auto;
}
.outstanding-modal .modal-header,
.outstanding-modal .modal-footer {
    flex: 0 0 auto;
}
</style>
</section>
