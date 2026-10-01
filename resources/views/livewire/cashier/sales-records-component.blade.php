<div>
<x-ui.flash />
<div class="container-fluid py-4">
    <style>
        .badge-success-light { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .badge-danger-light { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .btn-white { background: #fff; border: 1px solid #ced4da; }
        .btn-white:hover { background: #f8f9fa; }
        .sale-data { display: none; }
    </style>

    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h4 class="font-weight-bold text-primary mb-1">{{ ucfirst($businessLine) }} Sales Records</h4>
            <p class="text-muted small">Viewing records for period: {{ $fromDate }} to {{ $toDate }}</p>
        </div>
        <div class="col-md-6 text-md-right">
            <div class="card bg-primary text-white d-inline-block shadow-sm">
                <div class="card-body py-2 px-3 text-left">
                    <small class="text-uppercase font-weight-bold opacity-75 d-block" style="font-size: 0.65rem;">Total Sales (Paid)</small>
                    <h5 class="mb-0 font-weight-bold">{{ currency() }} {{ number_format($totalSales, 2) }}</h5>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body py-3">
            <div class="row no-gutters">
                <div class="col-md-3 px-1">
                    <label class="small font-weight-bold text-uppercase text-muted">Live Search</label>
                    <input wire:model.live.debounce.300ms="searchTerm" type="text" class="form-control form-control-sm shadow-none" placeholder="Name or TXN ID">
                </div>
                <div class="col-md-3 px-1">
                    <label class="small font-weight-bold text-uppercase text-muted">Date Range</label>
                    <div class="input-group input-group-sm">
                        <x-date-range from="fromDate" to="toDate" presets="finance" />
                    </div>
                </div>
                <div class="col-md-4 px-1">
                    <label class="small font-weight-bold text-uppercase text-muted">Status & Export</label>
                    <div class="btn-group btn-group-sm d-flex">
                        <button wire:click="toggleRefundFilter" class="btn border {{ $filterRefunded === null ? 'btn-light' : ($filterRefunded === 1 ? 'btn-danger' : 'btn-success') }}">
                            @if($filterRefunded === null) All @elseif($filterRefunded === 1) Refunded @else Paid @endif
                        </button>
                        <button wire:click="sortBy('total_amount')" class="btn btn-white text-primary">
                            Amt @if($sortColumn === 'total_amount') <i class="fas fa-sort-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i> @endif
                        </button>
                        <button wire:click="exportCSV" class="btn btn-success shadow-none">
                            <i class="fas fa-file-csv mr-1"></i> CSV
                        </button>
                    </div>
                </div>
                <div class="col-md-2 px-1">
                    <label class="small font-weight-bold text-uppercase text-muted">&nbsp;</label>
                    <button wire:click="resetFilters" class="btn btn-sm btn-block btn-secondary shadow-none">Reset</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr class="small text-uppercase font-weight-bold text-muted">
                        <th class="pl-4 border-0">Date / TXN ID</th>
                        <th class="border-0">Patient Info</th>
                        <th class="border-0">Amount</th>
                        <th class="border-0 text-center">Status</th>
                        <th class="pr-4 border-0 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sales as $sale)
                        <tr>
                            <td class="pl-4 py-3">
                                <span class="d-block font-weight-bold">{{ $sale->created_at->format('M d, Y') }}</span>
                                <small class="text-primary font-italic">{{ $sale->transaction_id }}</small>
                            </td>
                            <td>
                                <span class="d-block font-weight-bold text-dark">{{ $sale->customer_display_name }}</span>
                                <small class="text-muted">By: {{ $sale->user->name ?? 'N/A' }}</small>
                            </td>
                            <td class="font-weight-bold">{{ currency() }} {{ number_format($sale->total_amount, 2) }}</td>
                            <td class="text-center">
                                @if($sale->is_refunded)
                                    <span class="badge badge-pill badge-danger-light px-3 py-1">Refunded</span>
                                @else
                                    <span class="badge badge-pill badge-success-light px-3 py-1">Paid</span>
                                @endif
                            </td>
                            <td class="pr-4 text-right">
                                <button
                                    onclick="viewSaleDetails('sale-{{ $sale->id }}')"
                                    class="btn btn-sm btn-white text-primary border shadow-none mr-1"
                                    title="View Items">
                                    <i class="fas fa-eye"></i>
                                </button>

                                @if(!$sale->is_refunded)
                                    @if($sale->pendingRefundLog)
                                        <span class="badge badge-warning px-2 py-1" title="Refund awaiting approval">
                                            <i class="fas fa-clock mr-1"></i>
                                            {{ ucfirst($sale->pendingRefundLog->status) }}
                                        </span>
                                    @else
                                        <button
                                            wire:click="initiateRefund({{ $sale->id }})"
                                            class="btn btn-sm btn-outline-warning shadow-none"
                                            title="Request Refund">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                    @endif
                                @endif
                                
                                {{-- Hidden div with sale data --}}
                                <div id="sale-{{ $sale->id }}" class="sale-data">
                                    <div class="patient">{{ $sale->customer_display_name }}</div>
                                    <div class="transaction-id">{{ $sale->transaction_id }}</div>
                                    <div class="total">{{ number_format($sale->total_amount, 2) }}</div>
                                    <div class="items">
                                        @foreach($sale->items as $item)
                                            <div class="item">
                                                <span class="product-name">{{ $item->display_product_name }}</span>
                                                <span class="quantity">{{ $item->dispensed_quantity }}</span>
                                                <span class="subtotal">{{ number_format($item->subtotal, 2) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center py-5 text-muted italic">No sales found matching your criteria.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white border-0 py-3">{{ $sales->links() }}</div>
    </div>

    {{-- Modal --}}
    <div class="modal fade" id="viewSaleModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-light py-2">
                    <h6 class="modal-title font-weight-bold text-dark">Sale Details</h6>
                    <button type="button" class="close"
                            onclick="$('#viewSaleModal').modal('hide')">&times;</button>
                </div>
                <div class="modal-body p-3" id="modalContent">
                    {{-- Content will be loaded by JavaScript --}}
                </div>
                <div class="modal-footer bg-light py-2 border-0">
                    <button type="button" class="btn btn-sm btn-secondary"
                            onclick="$('#viewSaleModal').modal('hide')">Close</button>
                    @if($businessLine === 'clinic' && \App\Services\Visits\PatientVisits::enabled())
                        <button onclick="printVisitReceipt()" class="btn btn-sm btn-success px-3 shadow-none">
                            <i class="fas fa-file-invoice mr-1"></i> Visit Receipt
                        </button>
                    @endif
                    <button onclick="printCurrentReceipt()" class="btn btn-sm btn-primary px-4 shadow-none" id="printBtn">
                        <i class="fas fa-print mr-1"></i> Print Receipt
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Initiate Refund Modal --}}
    <div wire:ignore.self class="modal fade" id="initiateRefundModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-undo mr-2"></i>Request Refund</h5>
                    <button type="button" class="close" wire:click="cancelRefundInitiation"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    @if($initiatingRefundSale)
                    <div class="alert alert-info border-0 small">
                        Submitting a refund request for
                        <strong>#{{ $initiatingRefundSale->transaction_id }}</strong>
                        ({{ currency() }} {{ number_format($initiatingRefundSale->total_amount, 2) }}).
                        A manager must approve before the refund is processed.
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-5">
                            <label class="font-weight-bold small">Request Type</label>
                            <select wire:model="initiateRefundType"
                                    class="form-control @error('initiateRefundType') is-invalid @enderror">
                                <option value="refund">Refund</option>
                                <option value="void">Void</option>
                            </select>
                            @error('initiateRefundType')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-group col-md-7">
                            <label class="font-weight-bold small">Reason Code</label>
                            <select wire:model="initiateRefundReasonCode"
                                    class="form-control @error('initiateRefundReasonCode') is-invalid @enderror">
                                <option value="">Select a reason</option>
                                @foreach(\App\Models\RefundLog::REASON_CODES as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('initiateRefundReasonCode')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold small">Reason <span class="text-danger">*</span></label>
                        <textarea
                            wire:model="initiateRefundReason"
                            class="form-control @error('initiateRefundReason') is-invalid @enderror"
                            rows="4"
                            placeholder="Describe why the customer is requesting a refund…"></textarea>
                        @error('initiateRefundReason')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <small class="text-muted">Minimum 10 characters</small>
                    </div>
                    @endif
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" wire:click="cancelRefundInitiation">Cancel</button>
                    <button type="button" class="btn btn-warning" wire:click="submitRefundRequest">
                        <i class="fas fa-paper-plane mr-1"></i> Submit Request
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentSaleId = null;

        function escapeHtml(value) {
            const div = document.createElement('div');
            div.textContent = value ?? '';
            return div.innerHTML;
        }

        function viewSaleDetails(saleDataId) {
            console.log('👁️ View button clicked for:', saleDataId);
            
            // Get the hidden div with sale data
            const saleDiv = document.getElementById(saleDataId);
            
            if (!saleDiv) {
                console.error('Sale data not found');
                alert('Error: Sale data not found');
                return;
            }
            
            // Extract data from hidden div
            const patient = escapeHtml(saleDiv.querySelector('.patient').textContent);
            const transactionId = escapeHtml(saleDiv.querySelector('.transaction-id').textContent);
            const total = escapeHtml(saleDiv.querySelector('.total').textContent);
            const items = saleDiv.querySelectorAll('.item');
            
            // Extract sale ID from the div ID (sale-123 -> 123)
            currentSaleId = saleDataId.replace('sale-', '');
            
            console.log('Patient:', patient);
            console.log('Transaction ID:', transactionId);
            console.log('Items count:', items.length);
            
            // Build modal content
            let html = `
                <div class="d-flex justify-content-between border-bottom pb-2 mb-2 small text-muted">
                    <span><strong>Patient:</strong> ${patient}</span>
                    <span><strong>ID:</strong> ${transactionId}</span>
                </div>
                <table class="table table-sm small table-bordered mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>Product Item</th>
                            <th class="text-center">Qty</th>
                            <th class="text-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            items.forEach(item => {
                const productName = escapeHtml(item.querySelector('.product-name').textContent);
                const quantity = escapeHtml(item.querySelector('.quantity').textContent);
                const subtotal = escapeHtml(item.querySelector('.subtotal').textContent);
                
                html += `
                    <tr>
                        <td>${productName}</td>
                        <td class="text-center">${quantity}</td>
                        <td class="text-right">{{ currency() }} ${subtotal}</td>
                    </tr>
                `;
            });
            
            html += `
                    </tbody>
                </table>
                <div class="mt-3 text-right">
                    <h5 class="font-weight-bold text-primary">Grand Total: {{ currency() }} ${total}</h5>
                </div>
            `;
            
            // Set modal content
            document.getElementById('modalContent').innerHTML = html;
            
            // Show modal
            $('#viewSaleModal').modal('show');
            
            console.log('✅ Modal opened');
        }

        /** The whole visit this sale belongs to, on one receipt. */
        function printVisitReceipt() {
            if (!currentSaleId) {
                alert('No sale selected');
                return;
            }
            if (!window.open(`{{ url('/cashier/visit-receipt/sale') }}/${currentSaleId}`, '_blank', 'width=302,height=600')) {
                alert('Please allow popups for this site to print receipts.');
            }
        }

        function printCurrentReceipt() {
            if (!currentSaleId) {
                alert('No sale selected');
                return;
            }
            
            console.log('🖨️ Printing receipt for sale:', currentSaleId);
            
            const receiptUrl = `{{ $businessLine === 'optical' ? url('/optical/receipt') : url('/cashier/receipt') }}/${currentSaleId}?change=0`;
            
            try {
                const printWindow = window.open(receiptUrl, '_blank', 'width=302,height=600');
                
                if (!printWindow) {
                    alert('Please allow popups for this site to print receipts.');
                    return;
                }
                
                printWindow.onload = function() {
                    setTimeout(() => {
                        printWindow.focus();
                        printWindow.print();
                    }, 250);
                };
            } catch (error) {
                console.error('❌ Print error:', error);
                alert('Failed to print: ' + error.message);
            }
        }

        console.log('✅ Sales Records JavaScript Loaded');

        window.addEventListener('show-initiateRefundModal', () => $('#initiateRefundModal').modal('show'));
        window.addEventListener('hide-initiateRefundModal', () => $('#initiateRefundModal').modal('hide'));
    </script>
</div>
</div>
