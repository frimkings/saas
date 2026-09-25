<div>
<div class="content p-3" style="background:#f0f2f5; min-height:100vh;">
<div class="container-fluid">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 font-weight-bold" style="color:#2c3e50;">
                <i class="fas fa-shopping-cart mr-2 text-success"></i>Purchase Orders
            </h3>
            <small class="text-muted text-uppercase font-weight-bold" style="letter-spacing:.05em;">
                PO &amp; GRN Workflow
            </small>
        </div>
        <button wire:click="openCreate" class="btn btn-success btn-sm">
            <i class="fas fa-plus mr-1"></i>New Purchase Order
        </button>
    </div>

    {{-- Filters --}}
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-md-4 mb-2 mb-md-0">
                    <input wire:model.live.debounce.300ms="search" type="text"
                           class="form-control form-control-sm"
                           placeholder="Search by PO number or supplier…">
                </div>
                <div class="col-md-3 mb-2 mb-md-0">
                    <select wire:model.live="status" class="form-control form-control-sm">
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="ordered">Ordered</option>
                        <option value="partial">Partial</option>
                        <option value="received">Received</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2 mb-2 mb-md-0">
                    <select wire:model.live="supplierId" class="form-control form-control-sm">
                        <option value="">All Suppliers</option>
                        @foreach($suppliers as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 mb-2 mb-md-0">
                    <select wire:model.live="invoiceStatus" class="form-control form-control-sm">
                        <option value="">All Invoice Status</option>
                        <option value="none">No Invoice</option>
                        <option value="invoiced">Invoiced</option>
                        <option value="partial">Partial Paid</option>
                        <option value="paid">Fully Paid</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <select wire:model.live="perPage" class="form-control form-control-sm">
                        <option value="15">15</option>
                        <option value="30">30</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="font-size:.85rem;">
                    <thead class="thead-light">
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
                                <td class="font-weight-bold">{{ $po->po_number }}</td>
                                <td>{!! $po->supplier?->name ?? '<em class="text-muted">No supplier</em>' !!}</td>
                                <td>{{ $po->order_date->format('d M Y') }}</td>
                                <td>{{ $po->expected_date?->format('d M Y') ?? '—' }}</td>
                                <td class="font-weight-bold">{{ currency() }} {{ number_format($po->total_amount, 2) }}</td>
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
                                    <span class="badge badge-{{ $badge }}">{{ ucfirst($po->status) }}</span>
                                </td>
                                {{-- Invoice status cell --}}
                                <td style="white-space:nowrap;">
                                    <span class="badge {{ $po->invoiceStatusBadgeClass() }}">
                                        {{ match($po->invoice_status) {
                                            'invoiced' => 'Invoiced',
                                            'partial'  => 'Part. Paid',
                                            'paid'     => 'Paid',
                                            default    => '—',
                                        } }}
                                    </span>
                                    @if($po->is_overdue)
                                        <span class="badge badge-danger ml-1" title="Due: {{ $po->invoice_due_date->format('d M Y') }}">
                                            Overdue
                                        </span>
                                    @endif
                                    @if($po->invoice_status !== 'none')
                                        <div class="text-muted small">
                                            Bal: {{ currency() }} {{ number_format($po->invoice_balance_due, 2) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="text-center" style="white-space:nowrap;">
                                    @if(!in_array($po->status, ['received','cancelled']))
                                        <button wire:click="openGrn({{ $po->id }})"
                                                class="btn btn-xs btn-success"
                                                title="Receive Goods">
                                            <i class="fas fa-truck-loading mr-1"></i>GRN
                                        </button>
                                    @endif
                                    @if(in_array($po->status, ['draft','ordered']))
                                        <button wire:click="openEdit({{ $po->id }})"
                                                class="btn btn-xs btn-outline-primary ml-1"
                                                title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    @endif
                                    {{-- Invoice button: show for non-cancelled POs --}}
                                    @if($po->status !== 'cancelled')
                                        <button wire:click="openInvoiceModal({{ $po->id }})"
                                                class="btn btn-xs btn-outline-info ml-1"
                                                title="{{ $po->invoice_status === 'none' ? 'Record Invoice' : 'Edit Invoice' }}">
                                            <i class="fas fa-file-invoice"></i>
                                        </button>
                                    @endif
                                    {{-- Payment button: only when invoice exists and not fully paid --}}
                                    @if(in_array($po->invoice_status, ['invoiced', 'partial']))
                                        <button wire:click="openPaymentModal({{ $po->id }})"
                                                class="btn btn-xs btn-outline-success ml-1"
                                                title="Record Payment">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </button>
                                    @endif
                                    <a href="{{ route('admin.purchase-orders.pdf', $po->id) }}"
                                       target="_blank"
                                       class="btn btn-xs btn-outline-dark ml-1"
                                       title="Download PDF">
                                        <i class="fas fa-file-pdf"></i>
                                    </a>
                                    @if(!in_array($po->status, ['received','cancelled']))
                                        <button wire:click="confirmCancel({{ $po->id }})"
                                                class="btn btn-xs btn-outline-danger ml-1"
                                                title="Cancel">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No purchase orders found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($orders->hasPages())
            <div class="card-footer border-0 bg-white">{{ $orders->links() }}</div>
        @endif
    </div>

</div>{{-- /container-fluid --}}
</div>{{-- /content --}}

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- Create / Edit PO Modal                                      --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
@if($showModal)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-shopping-cart mr-2"></i>
                    {{ $isEditing ? 'Edit Purchase Order' : 'New Purchase Order' }}
                </h5>
                <button type="button" class="close text-white" wire:click="$set('showModal',false)">&times;</button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">SUPPLIER</label>
                            <select wire:model="supplier_id" class="form-control form-control-sm">
                                <option value="">— No Supplier —</option>
                                @foreach($suppliers as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">STATUS</label>
                            <select wire:model="status_field" class="form-control form-control-sm">
                                <option value="draft">Draft</option>
                                <option value="ordered">Ordered</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">ORDER DATE *</label>
                            <input type="date" wire:model="order_date"
                                   class="form-control form-control-sm @error('order_date') is-invalid @enderror">
                            @error('order_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">EXPECTED DELIVERY</label>
                            <input type="date" wire:model="expected_date" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">NOTES</label>
                            <textarea wire:model="notes" rows="4"
                                      class="form-control form-control-sm" placeholder="Internal notes…"></textarea>
                        </div>
                    </div>
                </div>

                <hr class="my-2">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0 font-weight-bold">Items</h6>
                    <button type="button" wire:click="addLine" class="btn btn-xs btn-outline-success">
                        <i class="fas fa-plus mr-1"></i>Add Line
                    </button>
                </div>

                @error('items')<div class="alert alert-danger py-1 small">{{ $message }}</div>@enderror

                <div class="table-responsive">
                    <table class="table table-sm mb-0" style="font-size:.82rem; min-width:600px;">
                        <thead class="thead-light">
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
                                    <td class="position-relative">
                                        <input type="text"
                                               wire:model.live.debounce.300ms="items.{{ $i }}.description"
                                               class="form-control form-control-sm @error('items.'.$i.'.description') is-invalid @enderror"
                                               placeholder="Description or search product…"
                                               autocomplete="off">
                                        @if(!empty($productResults) && $productLineIdx === $i)
                                            <div class="list-group position-absolute shadow-sm" style="z-index:9999; top:100%; left:0; right:0; min-width:220px;">
                                                @foreach($productResults as $p)
                                                    <button type="button" wire:click="selectProduct({{ $p['id'] }})"
                                                            class="list-group-item list-group-item-action py-1 px-2"
                                                            style="font-size:.8rem;">
                                                        {{ $p['name'] }}
                                                        <small class="text-muted float-right">Cost: {{ currency() }} {{ number_format($p['cost_price'], 2) }}</small>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                        @error('items.'.$i.'.description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </td>
                                    <td>
                                        <input type="number" wire:model.blur="items.{{ $i }}.quantity_ordered"
                                               class="form-control form-control-sm text-right @error('items.'.$i.'.quantity_ordered') is-invalid @enderror"
                                               min="0.01" step="0.01">
                                        @error('items.'.$i.'.quantity_ordered')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </td>
                                    <td>
                                        <input type="number" wire:model.blur="items.{{ $i }}.unit_cost"
                                               class="form-control form-control-sm text-right @error('items.'.$i.'.unit_cost') is-invalid @enderror"
                                               min="0" step="0.01">
                                        @error('items.'.$i.'.unit_cost')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </td>
                                    <td class="align-middle font-weight-bold text-right">
                                        {{ currency() }} {{ number_format($item['subtotal'], 2) }}
                                    </td>
                                    <td class="align-middle text-center">
                                        @if(count($items) > 1)
                                            <button type="button" wire:click="removeLine({{ $i }})"
                                                    class="btn btn-xs btn-outline-danger">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-right font-weight-bold">TOTAL</td>
                                <td class="text-right font-weight-bold text-success">
                                    {{ currency() }} {{ number_format($total, 2) }}
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" wire:click="$set('showModal',false)">Cancel</button>
                <button type="button" wire:click="save" class="btn btn-success btn-sm">
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
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-truck-loading mr-2"></i>Receive Goods (GRN)
                </h5>
                <button type="button" class="close text-white" wire:click="$set('showGrnModal',false)">&times;</button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">Enter the quantity actually received for each item. Leave zero for items not yet delivered.</p>
                <div class="table-responsive">
                    <table class="table table-sm" style="font-size:.85rem;">
                        <thead class="thead-light">
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
                                    <td class="text-right text-muted">{{ number_format($line['quantity_received'], 2) }}</td>
                                    <td class="text-right" style="width:110px;">
                                        <input type="number"
                                               wire:model="grnLines.{{ $i }}.receive_qty"
                                               class="form-control form-control-sm text-right @error('grnLines.'.$i.'.receive_qty') is-invalid @enderror"
                                               min="0" step="0.01"
                                               max="{{ $line['quantity_ordered'] - $line['quantity_received'] }}">
                                        @error('grnLines.'.$i.'.receive_qty')<div class="invalid-feedback" style="font-size:.7rem;">{{ $message }}</div>@enderror
                                    </td>
                                    <td>
                                        <input type="text"
                                               wire:model="grnLines.{{ $i }}.batch_number"
                                               class="form-control form-control-sm"
                                               placeholder="Batch…">
                                    </td>
                                    <td>
                                        <input type="date"
                                               wire:model="grnLines.{{ $i }}.expiry_date"
                                               class="form-control form-control-sm">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-info py-2 small mt-2">
                    <i class="fas fa-info-circle mr-1"></i>
                    Products with a linked product will automatically update stock levels.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" wire:click="$set('showGrnModal',false)">Cancel</button>
                <button type="button" wire:click="receiveGoods" class="btn btn-primary btn-sm">
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
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px;">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-file-invoice mr-2"></i>Record Supplier Invoice
                </h5>
                <button type="button" class="close text-white" wire:click="$set('showInvoiceModal',false)">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="small font-weight-bold text-muted">INVOICE NUMBER</label>
                    <input type="text" wire:model="inv_number" class="form-control form-control-sm"
                           placeholder="Supplier's invoice ref…">
                </div>
                <div class="row">
                    <div class="col-6">
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">INVOICE DATE *</label>
                            <input type="date" wire:model="inv_date"
                                   class="form-control form-control-sm @error('inv_date') is-invalid @enderror">
                            @error('inv_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">DUE DATE</label>
                            <input type="date" wire:model="inv_due_date" class="form-control form-control-sm">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="small font-weight-bold text-muted">INVOICE AMOUNT *</label>
                    <div class="input-group input-group-sm">
                        <div class="input-group-prepend"><span class="input-group-text">{{ currency() }}</span></div>
                        <input type="number" wire:model="inv_amount" step="0.01" min="0.01"
                               class="form-control @error('inv_amount') is-invalid @enderror" placeholder="0.00">
                        @error('inv_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" wire:click="$set('showInvoiceModal',false)">Cancel</button>
                <button type="button" wire:click="saveInvoice" class="btn btn-info btn-sm">
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
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-money-bill-wave mr-2"></i>Record Payment
                </h5>
                <button type="button" class="close text-white" wire:click="$set('showPaymentModal',false)">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="small font-weight-bold text-muted">PAYMENT DATE *</label>
                    <input type="date" wire:model="pay_date"
                           class="form-control form-control-sm @error('pay_date') is-invalid @enderror">
                    @error('pay_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label class="small font-weight-bold text-muted">AMOUNT PAID *</label>
                    <div class="input-group input-group-sm">
                        <div class="input-group-prepend"><span class="input-group-text">{{ currency() }}</span></div>
                        <input type="number" wire:model="pay_amount" step="0.01" min="0.01"
                               class="form-control @error('pay_amount') is-invalid @enderror" placeholder="0.00">
                        @error('pay_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="form-group">
                    <label class="small font-weight-bold text-muted">PAYMENT METHOD</label>
                    <select wire:model="pay_method" class="form-control form-control-sm">
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                        <option value="momo">Mobile Money</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="small font-weight-bold text-muted">REFERENCE / CHEQUE NO.</label>
                    <input type="text" wire:model="pay_reference" class="form-control form-control-sm"
                           placeholder="Transaction ref, cheque number…">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" wire:click="$set('showPaymentModal',false)">Cancel</button>
                <button type="button" wire:click="savePayment" class="btn btn-success btn-sm">
                    <i class="fas fa-check mr-1"></i>Record Payment
                </button>
            </div>
        </div>
    </div>
</div>
@endif

<script>
    window.addEventListener('show-po-confirm', event => {
        Swal.fire({
            title: 'Are you sure?',
            text: event.detail.message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Yes, cancel it',
        }).then(result => {
            if (result.isConfirmed) {
                @this.call(event.detail.action, event.detail.id);
            }
        });
    });
</script>
</div>{{-- single Livewire root --}}
