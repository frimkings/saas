<div class="clinic-ui ui-page">
<div>
<div class="w-full">

    {{-- Header --}}
    <div class="ui-heading">
        <div>
            <h1 class="flex flex-wrap items-center gap-2">Purchase Orders</h1>
            <p class="ui-muted">Orders to suppliers, from draft to received.</p>
        </div>
        <button wire:click="openCreate" class="ui-button ui-button-primary ui-button-sm">
            <i class="fas fa-plus mr-1"></i>New Purchase Order
        </button>
    </div>

    {{-- Filters --}}
    <div class="ui-panel mb-4">
        <div class="p-5">
            <div class="flex flex-wrap -mx-2 items-center">
                <div class="w-full md:w-4/12 px-2 mb-2 md:mb-0">
                    <input aria-label="Search" wire:model.live.debounce.300ms="search" type="text"
                           class="ui-input ui-input-sm"
                           placeholder="Search by PO number or supplier…">
                </div>
                <div class="w-full md:w-3/12 px-2 mb-2 md:mb-0">
                    <select wire:model.live="status" aria-label="Status" class="ui-input ui-input-sm">
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="ordered">Ordered</option>
                        <option value="partial">Partial</option>
                        <option value="received">Received</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="w-full md:w-2/12 px-2 mb-2 md:mb-0">
                    <select wire:model.live="supplierId" aria-label="Supplier" class="ui-input ui-input-sm">
                        <option value="">All Suppliers</option>
                        @foreach($suppliers as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full md:w-2/12 px-2 mb-2 md:mb-0">
                    <select wire:model.live="invoiceStatus" aria-label="Invoice status" class="ui-input ui-input-sm">
                        <option value="">All Invoice Status</option>
                        <option value="none">No Invoice</option>
                        <option value="invoiced">Invoiced</option>
                        <option value="partial">Partial Paid</option>
                        <option value="paid">Fully Paid</option>
                    </select>
                </div>
                <div class="w-full md:w-1/12 px-2">
                    <select aria-label="Rows per page" wire:model.live="perPage" class="ui-input ui-input-sm">
                        <option value="15">15</option>
                        <option value="30">30</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="ui-panel">
        <div class="">
            <div class="ui-table-wrap">
                <table class="ui-table mb-0" style="font-size:.85rem;">
                    <thead class="">
                        <tr>
                            <th>PO Number</th>
                            <th>Supplier</th>
                            <th>Order Date</th>
                            <th>Expected</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Invoice</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($orders as $po)
                            <tr>
                                <td class="font-semibold">{{ $po->po_number }}</td>
                                <td>{!! $po->supplier?->name ?? '<em class="text-slate-500">No supplier</em>' !!}</td>
                                <td>{{ $po->order_date->format('d M Y') }}</td>
                                <td>{{ $po->expected_date?->format('d M Y') ?? '—' }}</td>
                                <td class="font-semibold">{{ currency() }} {{ number_format($po->total_amount, 2) }}</td>
                                <td>
                                    @php
                                        $badge = match($po->status) {
                                            'draft'     => 'secondary',
                                            'ordered'   => 'primary',
                                            'partial'   => 'warning',
                                            'received'  => 'success',
                                            'cancelled' => 'danger',
                                            default     => 'secondary',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $badge }}">{{ ucfirst($po->status) }}</span>
                                </td>
                                {{-- Invoice status cell --}}
                                <td style="white-space:nowrap;">
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $po->invoiceStatusBadgeClass() }}">
                                        {{ match($po->invoice_status) {
                                            'invoiced' => 'Invoiced',
                                            'partial'  => 'Part. Paid',
                                            'paid'     => 'Paid',
                                            default    => '—',
                                        } }}
                                    </span>
                                    @if($po->is_overdue)
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800 ml-1" title="Due: {{ $po->invoice_due_date->format('d M Y') }}">
                                            Overdue
                                        </span>
                                    @endif
                                    @if($po->invoice_status !== 'none')
                                        <div class="text-slate-500 text-sm">
                                            Bal: {{ currency() }} {{ number_format($po->invoice_balance_due, 2) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="text-center" style="white-space:nowrap;">
                                    @if(in_array($po->status, ['ordered','partial']))
                                        <button wire:click="openGrn({{ $po->id }})"
                                                class="ui-button ui-button-sm ui-button-primary"
                                                title="Receive Goods">
                                            <i class="fas fa-truck-loading mr-1"></i>GRN
                                        </button>
                                    @endif
                                    @if(in_array($po->status, ['draft','ordered']))
                                        <button wire:click="openEdit({{ $po->id }})"
                                                class="ui-button ui-button-sm ui-button-secondary ml-1"
                                                title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    @endif
                                    {{-- Invoice button: show for non-cancelled POs --}}
                                    @if($po->status !== 'cancelled')
                                        <button wire:click="openInvoiceModal({{ $po->id }})"
                                                class="ui-button ui-button-sm ui-button-secondary ml-1"
                                                title="{{ $po->invoice_status === 'none' ? 'Record Invoice' : 'Edit Invoice' }}">
                                            <i class="fas fa-file-invoice"></i>
                                        </button>
                                    @endif
                                    {{-- Payment button: only when invoice exists and not fully paid --}}
                                    @if(in_array($po->invoice_status, ['invoiced', 'partial']))
                                        <button wire:click="openPaymentModal({{ $po->id }})"
                                                class="ui-button ui-button-sm ui-button-secondary ml-1"
                                                title="Record Payment">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </button>
                                    @endif
                                    <a href="{{ route('admin.purchase-orders.pdf', $po->id) }}"
                                       target="_blank"
                                       class="ui-button ui-button-sm ui-button-secondary ml-1"
                                       title="Download PDF">
                                        <i class="fas fa-file-pdf"></i>
                                    </a>
                                    @if(!in_array($po->status, ['received','cancelled']))
                                        <button wire:click="confirmCancel({{ $po->id }})"
                                                class="ui-button ui-button-sm ui-button-danger ml-1"
                                                title="Cancel">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-slate-500 py-6">No purchase orders found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($orders->hasPages())
            <div class="border-t border-slate-200 px-4 py-2 border-0 bg-white">{{ $orders->links() }}</div>
        @endif
    </div>

</div>{{-- /container-fluid --}}
</div>{{-- /content --}}

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- Create / Edit PO Modal                                      --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
@if($showModal)
<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show block" tabindex="-1" style="background:rgba(0,0,0,.5);">
    <div class="mx-auto my-8 w-full max-w-6xl">
        <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-green-600 text-white">
                <h5 class="text-base font-semibold">
                    <i class="fas fa-shopping-cart mr-2"></i>
                    {{ $isEditing ? 'Edit Purchase Order' : 'New Purchase Order' }}
                </h5>
                <button type="button" class="text-xl leading-none hover:text-slate-800 text-white" wire:click="$set('showModal',false)">&times;</button>
            </div>
            <div class="p-4">
                <div class="flex flex-wrap -mx-2">
                    <div class="w-full md:w-4/12 px-2">
                        <div class="mb-4">
                            <label for="purchase-ord-supplier-id" class="text-sm font-semibold text-slate-500">SUPPLIER</label>
                            <select id="purchase-ord-supplier-id" wire:model="supplier_id" class="ui-input ui-input-sm">
                                <option value="">— No Supplier —</option>
                                @foreach($suppliers as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-4">
                            <label for="purchase-ord-status-field" class="text-sm font-semibold text-slate-500">STATUS</label>
                            <select id="purchase-ord-status-field" wire:model="status_field" class="ui-input ui-input-sm">
                                <option value="draft">Draft</option>
                                <option value="ordered">Ordered</option>
                            </select>
                        </div>
                    </div>
                    <div class="w-full md:w-4/12 px-2">
                        <div class="mb-4">
                            <label for="purchase-ord-order-date" class="text-sm font-semibold text-slate-500">ORDER DATE *</label>
                            <input id="purchase-ord-order-date" type="date" wire:model="order_date"
                                   class="ui-input ui-input-sm @error('order_date') is-invalid @enderror">
                            @error('order_date')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-4">
                            <label for="purchase-ord-expected-date" class="text-sm font-semibold text-slate-500">EXPECTED DELIVERY</label>
                            <input id="purchase-ord-expected-date" type="date" wire:model="expected_date" class="ui-input ui-input-sm">
                        </div>
                    </div>
                    <div class="w-full md:w-4/12 px-2">
                        <div class="mb-4">
                            <label for="purchase-ord-notes" class="text-sm font-semibold text-slate-500">NOTES</label>
                            <textarea id="purchase-ord-notes" wire:model="notes" rows="4"
                                      class="ui-input ui-input-sm" placeholder="Internal notes…"></textarea>
                        </div>
                    </div>
                </div>

                <hr class="my-2">
                <div class="flex justify-between items-center mb-2">
                    <h6 class="mb-0 font-semibold">Items</h6>
                    <button type="button" wire:click="addLine" class="ui-button ui-button-sm ui-button-secondary">
                        <i class="fas fa-plus mr-1"></i>Add Line
                    </button>
                </div>

                @error('items')<div class="rounded-lg border px-3 text-sm border-red-200 bg-red-50 text-red-800 py-1">{{ $message }}</div>@enderror

                <div class="ui-table-wrap">
                    <table class="ui-table ui-table-sm mb-0" style="font-size:.82rem; min-width:600px;">
                        <thead class="">
                            <tr>
                                <th>Description *</th>
                                <th style="width:90px;">Qty *</th>
                                <th style="width:130px;">Unit Cost *</th>
                                <th style="width:120px;">Subtotal</th>
                                <th style="width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $i => $item)
                                <tr>
                                    <td class="relative">
                                        <input aria-label="Description or search product" type="text"
                                               wire:model="items.{{ $i }}.description"
                                               x-on:input.debounce.300ms="$wire.searchProducts({{ $i }}, $event.target.value)"
                                               class="ui-input ui-input-sm @error('items.'.$i.'.description') is-invalid @enderror"
                                               placeholder="Description or search product…"
                                               autocomplete="off">
                                        @if(!empty($productResults) && $productLineIdx === $i)
                                            <div class="overflow-hidden rounded-md border border-slate-200 bg-white absolute shadow-sm" style="z-index:9999; top:100%; left:0; right:0; min-width:220px;">
                                                @foreach($productResults as $p)
                                                    <button type="button" wire:click="selectProduct({{ $p['id'] }})"
                                                            class="list-group-item block w-full border-b border-slate-100 text-left hover:bg-slate-50 py-1 px-2"
                                                            style="font-size:.8rem;">
                                                        {{ $p['name'] }}
                                                        <small class="text-slate-500">Cost: {{ currency() }} {{ number_format($p['cost_price'], 2) }}</small>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                        @error('items.'.$i.'.description')<div class="ui-error">{{ $message }}</div>@enderror
                                    </td>
                                    <td>
                                        <input type="number" wire:model.blur="items.{{ $i }}.quantity_ordered" aria-label="Quantity ordered"
                                               class="ui-input ui-input-sm text-right @error('items.'.$i.'.quantity_ordered') is-invalid @enderror"
                                               min="0.01" step="0.01">
                                        @error('items.'.$i.'.quantity_ordered')<div class="ui-error">{{ $message }}</div>@enderror
                                    </td>
                                    <td>
                                        <input type="number" wire:model.blur="items.{{ $i }}.unit_cost" aria-label="Unit cost"
                                               class="ui-input ui-input-sm text-right @error('items.'.$i.'.unit_cost') is-invalid @enderror"
                                               min="0" step="0.01">
                                        @error('items.'.$i.'.unit_cost')<div class="ui-error">{{ $message }}</div>@enderror
                                    </td>
                                    <td class="align-middle font-semibold text-right">
                                        {{ currency() }} {{ number_format($item['subtotal'], 2) }}
                                    </td>
                                    <td class="align-middle text-center">
                                        @if(count($items) > 1)
                                            <button type="button" wire:click="removeLine({{ $i }})"
                                                    class="ui-button ui-button-sm ui-button-danger">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-right font-semibold">TOTAL</td>
                                <td class="text-right font-semibold text-green-700">
                                    {{ currency() }} {{ number_format($total, 2) }}
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="$set('showModal',false)">Cancel</button>
                <button type="button" wire:click="save" class="ui-button ui-button-primary ui-button-sm">
                    <i class="fas fa-save mr-1"></i>{{ $isEditing ? 'Update' : 'Create' }} PO
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- GRN Modal                                                   --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
@if($showGrnModal)
<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show block" tabindex="-1" style="background:rgba(0,0,0,.5);">
    <div class="mx-auto my-8 w-full max-w-3xl">
        <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-teal-700 text-white">
                <h5 class="text-base font-semibold">
                    <i class="fas fa-truck-loading mr-2"></i>Receive Goods (GRN)
                </h5>
                <button type="button" class="text-xl leading-none hover:text-slate-800 text-white" wire:click="$set('showGrnModal',false)">&times;</button>
            </div>
            <div class="p-4">
                <p class="text-slate-500 text-sm mb-4">Enter the quantity actually received for each item. Leave zero for items not yet delivered.</p>
                {{-- Problems with the receipt as a whole (more than is outstanding, lines from another order). --}}
                @error('grnLines')<div class="ui-panel ui-panel--danger mb-3 px-3 py-2 text-sm text-red-800" role="alert">{{ $message }}</div>@enderror
                <div class="ui-table-wrap">
                    <table class="ui-table ui-table-sm" style="font-size:.85rem;">
                        <thead class="">
                            <tr>
                                <th>Item</th>
                                <th class="text-right">Ordered</th>
                                <th class="text-right">Prev. Received</th>
                                <th class="text-right">Receive Now *</th>
                                <th>Batch No.</th>
                                <th>Expiry Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($grnLines as $i => $line)
                                <tr>
                                    <td>{{ $line['description'] }}</td>
                                    <td class="text-right">{{ number_format($line['quantity_ordered'], 2) }}</td>
                                    <td class="text-right text-slate-500">{{ number_format($line['quantity_received'], 2) }}</td>
                                    <td class="text-right" style="width:110px;">
                                        <input type="number"
                                               wire:model="grnLines.{{ $i }}.receive_qty" aria-label="Quantity received"
                                               class="ui-input ui-input-sm text-right @error('grnLines.'.$i.'.receive_qty') is-invalid @enderror"
                                               min="0" step="0.01"
                                               max="{{ $line['quantity_ordered'] - $line['quantity_received'] }}">
                                        @error('grnLines.'.$i.'.receive_qty')<div class="ui-error" style="font-size:.7rem;">{{ $message }}</div>@enderror
                                    </td>
                                    <td>
                                        <input aria-label="Batch" type="text"
                                               wire:model="grnLines.{{ $i }}.batch_number"
                                               class="ui-input ui-input-sm"
                                               placeholder="Batch…">
                                    </td>
                                    <td>
                                        <input type="date"
                                               wire:model="grnLines.{{ $i }}.expiry_date" aria-label="Expiry date"
                                               class="ui-input ui-input-sm @error('grnLines.'.$i.'.expiry_date') is-invalid @enderror">
                                        @error('grnLines.'.$i.'.expiry_date')<div class="ui-error" style="font-size:.7rem;">{{ $message }}</div>@enderror
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="rounded-lg border px-3 text-sm border-sky-200 bg-sky-50 text-sky-900 py-2 mt-2">
                    <i class="fas fa-info-circle mr-1"></i>
                    Products with a linked product will automatically update stock levels.
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="$set('showGrnModal',false)">Cancel</button>
                <button type="button" wire:click="receiveGoods" class="ui-button ui-button-primary ui-button-sm">
                    <i class="fas fa-check mr-1"></i>Confirm Receipt
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- Invoice Modal                                               --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
@if($showInvoiceModal)
<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show block" tabindex="-1" style="background:rgba(0,0,0,.5);">
    <div class="mx-auto my-8 w-full max-w-lg" style="max-width:480px;">
        <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-sky-600 text-white">
                <h5 class="text-base font-semibold">
                    <i class="fas fa-file-invoice mr-2"></i>Record Supplier Invoice
                </h5>
                <button type="button" class="text-xl leading-none hover:text-slate-800 text-white" wire:click="$set('showInvoiceModal',false)">&times;</button>
            </div>
            <div class="p-4">
                <div class="mb-4">
                    <label for="purchase-ord-inv-number" class="text-sm font-semibold text-slate-500">INVOICE NUMBER</label>
                    <input id="purchase-ord-inv-number" type="text" wire:model="inv_number" class="ui-input ui-input-sm"
                           placeholder="Supplier's invoice ref…">
                </div>
                <div class="flex flex-wrap -mx-2">
                    <div class="w-6/12 px-2">
                        <div class="mb-4">
                            <label for="purchase-ord-inv-date" class="text-sm font-semibold text-slate-500">INVOICE DATE *</label>
                            <input id="purchase-ord-inv-date" type="date" wire:model="inv_date"
                                   class="ui-input ui-input-sm @error('inv_date') is-invalid @enderror">
                            @error('inv_date')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="w-6/12 px-2">
                        <div class="mb-4">
                            <label for="purchase-ord-inv-due-date" class="text-sm font-semibold text-slate-500">DUE DATE</label>
                            <input id="purchase-ord-inv-due-date" type="date" wire:model="inv_due_date" class="ui-input ui-input-sm">
                        </div>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="purchase-ord-inv-amount" class="text-sm font-semibold text-slate-500">INVOICE AMOUNT *</label>
                    <div class="flex items-stretch">
                        <div class="flex"><span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600">{{ currency() }}</span></div>
                        <input id="purchase-ord-inv-amount" type="number" wire:model="inv_amount" step="0.01" min="0.01"
                               class="ui-input @error('inv_amount') is-invalid @enderror" placeholder="0.00">
                        @error('inv_amount')<div class="ui-error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="$set('showInvoiceModal',false)">Cancel</button>
                <button type="button" wire:click="saveInvoice" class="ui-button ui-button-primary ui-button-sm">
                    <i class="fas fa-save mr-1"></i>Save Invoice
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- Payment Modal                                               --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
@if($showPaymentModal)
<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show block" tabindex="-1" style="background:rgba(0,0,0,.5);">
    <div class="mx-auto my-8 w-full max-w-lg" style="max-width:420px;">
        <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-green-600 text-white">
                <h5 class="text-base font-semibold">
                    <i class="fas fa-money-bill-wave mr-2"></i>Record Payment
                </h5>
                <button type="button" class="text-xl leading-none hover:text-slate-800 text-white" wire:click="$set('showPaymentModal',false)">&times;</button>
            </div>
            <div class="p-4">
                <div class="mb-4">
                    <label for="purchase-ord-pay-date" class="text-sm font-semibold text-slate-500">PAYMENT DATE *</label>
                    <input id="purchase-ord-pay-date" type="date" wire:model="pay_date"
                           class="ui-input ui-input-sm @error('pay_date') is-invalid @enderror">
                    @error('pay_date')<div class="ui-error">{{ $message }}</div>@enderror
                </div>
                <div class="mb-4">
                    <label for="purchase-ord-pay-amount" class="text-sm font-semibold text-slate-500">AMOUNT PAID *</label>
                    <div class="flex items-stretch">
                        <div class="flex"><span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600">{{ currency() }}</span></div>
                        <input id="purchase-ord-pay-amount" type="number" wire:model="pay_amount" step="0.01" min="0.01"
                               class="ui-input @error('pay_amount') is-invalid @enderror" placeholder="0.00">
                        @error('pay_amount')<div class="ui-error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="mb-4">
                    <label for="purchase-ord-pay-method" class="text-sm font-semibold text-slate-500">PAYMENT METHOD</label>
                    <select id="purchase-ord-pay-method" wire:model="pay_method" class="ui-input ui-input-sm">
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                        <option value="momo">Mobile Money</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="purchase-ord-pay-reference" class="text-sm font-semibold text-slate-500">REFERENCE / CHEQUE NO.</label>
                    <input id="purchase-ord-pay-reference" type="text" wire:model="pay_reference" class="ui-input ui-input-sm"
                           placeholder="Transaction ref, cheque number…">
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="$set('showPaymentModal',false)">Cancel</button>
                <button type="button" wire:click="savePayment" class="ui-button ui-button-primary ui-button-sm">
                    <i class="fas fa-check mr-1"></i>Record Payment
                </button>
            </div>
        </div>
    </div>
</div>
@endif

<script>
    window.addEventListener('show-po-confirm', event => {
        window.appConfirm(event.detail.message, { title: 'Are you sure?', confirmText: 'Yes, cancel it', danger: true })
            .then(ok => ok && @this.call(event.detail.action, event.detail.id));
    });
</script>
</div>{{-- single Livewire root --}}
