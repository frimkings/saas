@php
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500';
    $chip = 'inline-flex items-center whitespace-nowrap rounded-md px-2 py-1 text-xs font-semibold';
    $refundTone = $filterRefunded === null ? 'ui-button-secondary' : ($filterRefunded === 1 ? 'ui-button-danger' : 'ui-button-primary');
@endphp
<div class="clinic-ui ui-page space-y-5">
<x-ui.flash />

    <div class="ui-heading">
        <div>
            <h1>{{ ucfirst($businessLine) }} sales records</h1>
            <p class="ui-muted">Viewing records for period: {{ $fromDate }} to {{ $toDate }}</p>
        </div>
        <div class="rounded-xl bg-teal-700 px-6 py-2 text-white">
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-100">Total sales (paid)</p>
            <p class="text-xl font-semibold">{{ currency() }} {{ number_format($totalSales, 2) }}</p>
        </div>
    </div>

    <div class="ui-panel grid items-end gap-2 px-6 py-6 sm:grid-cols-2 lg:grid-cols-[3fr_3fr_4fr_2fr]">
        <div>
            <label for="sales-search" class="{{ $label }}">Live search</label>
            <input id="sales-search" wire:model.live.debounce.300ms="searchTerm" type="search" class="ui-input" placeholder="Name or TXN ID">
        </div>
        <div>
            <span class="{{ $label }}">Date range</span>
            <x-date-range from="fromDate" to="toDate" presets="finance" />
        </div>
        <div>
            <span class="{{ $label }}">Status &amp; export</span>
            <div class="flex gap-1">
                <button type="button" wire:click="toggleRefundFilter" class="ui-button {{ $refundTone }} flex-1" title="Show all, refunded or paid sales">
                    @if($filterRefunded === null) All @elseif($filterRefunded === 1) Refunded @else Paid @endif
                </button>
                <button type="button" wire:click="sortBy('total_amount')" class="ui-button ui-button-secondary flex-1">
                    Amount @if($sortColumn === 'total_amount')<i class="fas fa-sort-{{ $sortDirection === 'asc' ? 'up' : 'down' }}" aria-hidden="true"></i>@endif
                </button>
                <button type="button" wire:click="exportCSV" class="ui-button ui-button-secondary flex-1"><i class="fas fa-file-csv" aria-hidden="true"></i>CSV</button>
            </div>
        </div>
        <button type="button" wire:click="resetFilters" class="ui-button ui-button-secondary w-full">Reset</button>
    </div>

    <section class="ui-panel">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Date / TXN ID</th>
                        <th>Patient</th>
                        <th class="ui-number">Amount</th>
                        <th class="text-center">Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sales as $sale)
                        <tr>
                            <td>
                                <span class="block font-semibold">{{ $sale->created_at->format('M d, Y') }}</span>
                                <span class="font-mono text-xs text-teal-800">{{ $sale->transaction_id }}</span>
                            </td>
                            <td>
                                <span class="block font-semibold">{{ $sale->customer_display_name }}</span>
                                <span class="text-xs text-slate-500">By: {{ $sale->user->name ?? 'N/A' }}</span>
                            </td>
                            <td class="ui-number font-semibold">{{ currency() }} {{ number_format($sale->total_amount, 2) }}</td>
                            <td class="text-center">
                                @if($sale->is_refunded)
                                    <span class="{{ $chip }} bg-red-100 text-red-800">Refunded</span>
                                @else
                                    <span class="{{ $chip }} bg-green-100 text-green-800">Paid</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button" onclick="viewSaleDetails('sale-{{ $sale->id }}')" class="ui-button ui-button-secondary" title="View items" aria-label="View items in {{ $sale->transaction_id }}">
                                        <i class="fas fa-eye" aria-hidden="true"></i>
                                    </button>
                                    @if(!$sale->is_refunded)
                                        @if($sale->pendingRefundLog)
                                            <span class="{{ $chip }} bg-amber-100 text-amber-800" title="Refund awaiting approval"><i class="fas fa-clock mr-1" aria-hidden="true"></i>{{ ucfirst($sale->pendingRefundLog->status) }}</span>
                                        @else
                                            <button type="button" wire:click="initiateRefund({{ $sale->id }})" class="ui-button ui-button-danger" title="Request refund" aria-label="Request refund for {{ $sale->transaction_id }}">
                                                <i class="fas fa-undo" aria-hidden="true"></i>
                                            </button>
                                        @endif
                                    @endif
                                </div>

                                {{-- Sale data read by the View button --}}
                                <div id="sale-{{ $sale->id }}" class="hidden">
                                    <div class="patient">{{ $sale->customer_display_name }}</div>
                                    <div class="transaction-id">{{ $sale->transaction_id }}</div>
                                    <div class="total">{{ number_format($sale->total_amount, 2) }}</div>
                                    <div class="items">
                                        @foreach($sale->items as $item)
                                            <div class="item">
                                                <span class="product-name">{{ $item->display_product_name }}{{ $item->is_on_hold ? ' (on hold until paid)' : '' }}</span>
                                                <span class="quantity">{{ $item->shown_quantity }}</span>
                                                <span class="subtotal">{{ number_format($item->shown_subtotal, 2) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ui-empty italic text-slate-500">No sales found matching your criteria.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 px-6 py-2">{{ $sales->links() }}</div>
    </section>

    {{-- Sale details (filled by viewSaleDetails) --}}
    <div id="viewSaleModal" wire:ignore.self class="fixed inset-0 z-[1060] hidden items-center justify-center bg-slate-900/50 px-6 py-6" onclick="if (event.target === this) closeSaleDetails()">
        <div class="ui-panel w-full max-w-lg bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="sale-details-title">
            <div class="ui-panel-heading">
                <h2 id="sale-details-title">Sale details</h2>
                <button type="button" class="ui-button ui-button-secondary" onclick="closeSaleDetails()" aria-label="Close dialog">Close</button>
            </div>
            <div class="px-6 py-6" id="modalContent"></div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-2">
                <button type="button" class="ui-button ui-button-secondary" onclick="closeSaleDetails()">Close</button>
                @if($businessLine === 'clinic' && \App\Services\Visits\PatientVisits::enabled())
                    <button type="button" onclick="printVisitReceipt()" class="ui-button ui-button-secondary"><i class="fas fa-file-invoice" aria-hidden="true"></i>Visit receipt</button>
                @endif
                <button type="button" onclick="printCurrentReceipt()" class="ui-button ui-button-primary" id="printBtn"><i class="fas fa-print" aria-hidden="true"></i>Print receipt</button>
            </div>
        </div>
    </div>

    {{-- Request refund --}}
    <div id="initiateRefundModal" wire:ignore.self class="fixed inset-0 z-[1060] hidden items-center justify-center bg-slate-900/50 px-6 py-6">
        <div class="ui-panel w-full max-w-lg bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="refund-dialog-title">
            <div class="ui-panel-heading bg-amber-50">
                <h2 id="refund-dialog-title"><i class="fas fa-undo mr-2 text-amber-700" aria-hidden="true"></i>Request refund</h2>
                <button type="button" class="ui-button ui-button-secondary" wire:click="cancelRefundInitiation" aria-label="Close dialog">Close</button>
            </div>
            <div class="space-y-4 px-6 py-6">
                @if($initiatingRefundSale)
                    <p class="rounded-md bg-sky-50 px-2 py-2 text-sm text-sky-900">
                        Submitting a refund request for <strong>#{{ $initiatingRefundSale->transaction_id }}</strong>
                        ({{ currency() }} {{ number_format($initiatingRefundSale->total_amount, 2) }}).
                        A manager must approve before the refund is processed.
                    </p>
                    @if($refundFeeExcluded)
                        <p class="rounded-md bg-amber-50 px-2 py-2 text-sm text-amber-900">
                            <i class="fas fa-user-md mr-1" aria-hidden="true"></i>
                            The consultation fee is not included: the doctor has already saved a consultation for this visit. The other items will be refunded.
                        </p>
                    @endif
                    <div class="grid gap-2 sm:grid-cols-[5fr_7fr]">
                        <div>
                            <label for="refund-type" class="{{ $label }}">Request type</label>
                            <select id="refund-type" wire:model="initiateRefundType" class="ui-input" aria-invalid="{{ $errors->has('initiateRefundType') ? 'true' : 'false' }}">
                                <option value="refund">Refund</option>
                                <option value="void">Void</option>
                            </select>
                            @error('initiateRefundType')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="refund-code" class="{{ $label }}">Reason code</label>
                            <select id="refund-code" wire:model="initiateRefundReasonCode" class="ui-input" aria-invalid="{{ $errors->has('initiateRefundReasonCode') ? 'true' : 'false' }}">
                                <option value="">Select a reason</option>
                                @foreach(\App\Models\RefundLog::REASON_CODES as $code => $reasonLabel)
                                    <option value="{{ $code }}">{{ $reasonLabel }}</option>
                                @endforeach
                            </select>
                            @error('initiateRefundReasonCode')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div>
                        <label for="refund-reason" class="{{ $label }}">Reason <span class="text-red-600">*</span></label>
                        <textarea id="refund-reason" wire:model="initiateRefundReason" class="ui-input" rows="4" placeholder="Describe why the customer is requesting a refund…"
                                  aria-invalid="{{ $errors->has('initiateRefundReason') ? 'true' : 'false' }}" aria-describedby="refund-reason-hint"></textarea>
                        @error('initiateRefundReason')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        <p id="refund-reason-hint" class="mt-1 text-xs text-slate-500">Minimum 10 characters</p>
                    </div>
                @endif
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-2">
                <button type="button" class="ui-button ui-button-secondary" wire:click="cancelRefundInitiation">Cancel</button>
                <button type="button" class="ui-button ui-button-primary" wire:click="submitRefundRequest"><i class="fas fa-paper-plane" aria-hidden="true"></i>Submit request</button>
            </div>
        </div>
    </div>

    <script>
        // Closing tags in strings are written <\/…> so HTML parsers don't end the script early
        // (Livewire's one-root check parses the component with DOMDocument).
        let currentSaleId = null;

        function escapeHtml(value) {
            const div = document.createElement('div');
            div.textContent = value ?? '';
            return div.innerHTML;
        }

        function salesDialog(id, open) {
            const dialog = document.getElementById(id);
            if (!dialog) return;
            dialog.classList.toggle('hidden', !open);
            dialog.classList.toggle('flex', open);
        }

        function closeSaleDetails() { salesDialog('viewSaleModal', false); }

        function viewSaleDetails(saleDataId) {
            const saleDiv = document.getElementById(saleDataId);
            if (!saleDiv) {
                window.appAlert('Sale not found', 'This sale could not be shown. Refresh the page and try again.', 'error');
                return;
            }

            const patient = escapeHtml(saleDiv.querySelector('.patient').textContent);
            const transactionId = escapeHtml(saleDiv.querySelector('.transaction-id').textContent);
            const total = escapeHtml(saleDiv.querySelector('.total').textContent);
            currentSaleId = saleDataId.replace('sale-', '');

            let rows = '';
            saleDiv.querySelectorAll('.item').forEach(item => {
                rows += `<tr>
                    <td>${escapeHtml(item.querySelector('.product-name').textContent)}<\/td>
                    <td class="text-center">${escapeHtml(item.querySelector('.quantity').textContent)}<\/td>
                    <td class="ui-number">{{ currency() }} ${escapeHtml(item.querySelector('.subtotal').textContent)}<\/td>
                <\/tr>`;
            });

            document.getElementById('modalContent').innerHTML = `
                <div class="mb-2 flex justify-between gap-2 text-sm text-slate-600">
                    <span><strong>Patient:<\/strong> ${patient}<\/span>
                    <span><strong>ID:<\/strong> ${transactionId}<\/span>
                <\/div>
                <div class="ui-table-wrap"><table class="ui-table">
                    <thead><tr><th>Item<\/th><th class="text-center">Qty<\/th><th class="ui-number">Subtotal<\/th><\/tr><\/thead>
                    <tbody>${rows}<\/tbody>
                <\/table><\/div>
                <p class="mt-2 text-right text-lg font-semibold text-teal-800">Grand total: {{ currency() }} ${total}<\/p>`;

            salesDialog('viewSaleModal', true);
        }

        function openReceiptWindow(url) {
            const printWindow = window.open(url, '_blank', 'width=302,height=600');
            if (!printWindow) {
                window.appAlert('Pop-up blocked', 'Please allow pop-ups for this site to print receipts.', 'warning');
            }
            return printWindow;
        }

        /** The whole visit this sale belongs to, on one receipt. */
        function printVisitReceipt() {
            if (currentSaleId) openReceiptWindow(`{{ url('/cashier/visit-receipt/sale') }}/${currentSaleId}`);
        }

        function printCurrentReceipt() {
            if (!currentSaleId) return;
            const printWindow = openReceiptWindow(`{{ $businessLine === 'optical' ? url('/optical/receipt') : url('/cashier/receipt') }}/${currentSaleId}?change=0`);
            if (printWindow) {
                printWindow.onload = function () {
                    setTimeout(() => { printWindow.focus(); printWindow.print(); }, 250);
                };
            }
        }

        window.addEventListener('show-initiateRefundModal', () => salesDialog('initiateRefundModal', true));
        window.addEventListener('hide-initiateRefundModal', () => salesDialog('initiateRefundModal', false));
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            const refund = document.getElementById('initiateRefundModal');
            if (refund && !refund.classList.contains('hidden')) @this.cancelRefundInitiation();
            else closeSaleDetails();
        });
    </script>
</div>
