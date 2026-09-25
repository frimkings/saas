<div>
<div class="content p-3" style="background:#f0f2f5; min-height:100vh;">
<div class="container-fluid">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 font-weight-bold" style="color:#2c3e50;">
                <i class="fas fa-file-invoice mr-2 text-primary"></i>Quotations
            </h3>
            <small class="text-muted text-uppercase font-weight-bold" style="letter-spacing:.05em;">
                Proforma &amp; Estimates
            </small>
        </div>
        <button wire:click="openCreate" class="btn btn-primary btn-sm">
            <i class="fas fa-plus mr-1"></i>New Quotation
        </button>
    </div>

    {{-- Filters --}}
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-md-5 mb-2 mb-md-0">
                    <input wire:model.live.debounce.300ms="search" type="text"
                           class="form-control form-control-sm"
                           placeholder="Search by number or patient name…">
                </div>
                <div class="col-md-3 mb-2 mb-md-0">
                    <select wire:model.live="status" class="form-control form-control-sm">
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="sent">Sent</option>
                        <option value="accepted">Accepted</option>
                        <option value="expired">Expired</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select wire:model.live="perPage" class="form-control form-control-sm">
                        <option value="15">15 / page</option>
                        <option value="30">30 / page</option>
                        <option value="50">50 / page</option>
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
                            <th>Number</th>
                            <th>Patient</th>
                            <th>Issue Date</th>
                            <th>Valid Until</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($quotations as $q)
                            <tr>
                                <td class="font-weight-bold">{{ $q->quotation_number }}</td>
                                <td>
                                    {{ $q->patient_name }}
                                    @if($q->patient_phone)
                                        <small class="text-muted d-block">{{ $q->patient_phone }}</small>
                                    @endif
                                </td>
                                <td>{{ $q->issue_date->format('d M Y') }}</td>
                                <td class="{{ $q->valid_until->isPast() && $q->status === 'draft' ? 'text-danger font-weight-bold' : '' }}">
                                    {{ $q->valid_until->format('d M Y') }}
                                </td>
                                <td class="font-weight-bold">{{ currency() }} {{ number_format($q->total_amount, 2) }}</td>
                                <td>
                                    @php
                                        $badge = match($q->status) {
                                            'draft'     => 'secondary',
                                            'sent'      => 'info',
                                            'accepted'  => 'success',
                                            'expired'   => 'warning',
                                            'cancelled' => 'danger',
                                            default     => 'secondary',
                                        };
                                    @endphp
                                    <span class="badge badge-{{ $badge }}">{{ ucfirst($q->status) }}</span>
                                </td>
                                <td class="text-center" style="white-space:nowrap;">
                                    {{-- Status change --}}
                                    <div class="dropdown d-inline-block">
                                        <button class="btn btn-xs btn-outline-secondary dropdown-toggle" data-toggle="dropdown">
                                            <i class="fas fa-exchange-alt"></i>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-right">
                                            @foreach(['draft','sent','accepted','expired','cancelled'] as $s)
                                                @if($s !== $q->status)
                                                    <a class="dropdown-item" wire:click="updateStatus({{ $q->id }}, '{{ $s }}')">
                                                        Mark {{ ucfirst($s) }}
                                                    </a>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                    <button wire:click="openEdit({{ $q->id }})"
                                            class="btn btn-xs btn-outline-primary ml-1"
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="{{ route('admin.quotations.pdf', $q->id) }}"
                                       target="_blank"
                                       class="btn btn-xs btn-outline-dark ml-1"
                                       title="Download PDF">
                                        <i class="fas fa-file-pdf"></i>
                                    </a>
                                    @hasrole('Super Admin')
                                    <button wire:click="confirmDelete({{ $q->id }})"
                                            class="btn btn-xs btn-outline-danger ml-1"
                                            title="Delete (soft)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    @endhasrole
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No quotations found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($quotations->hasPages())
            <div class="card-footer border-0 bg-white">
                {{ $quotations->links() }}
            </div>
        @endif
    </div>

</div>{{-- /container-fluid --}}
</div>{{-- /content --}}

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- Create / Edit Modal                                         --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
@if($showModal)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-file-invoice mr-2"></i>
                    {{ $isEditing ? 'Edit Quotation' : 'New Quotation' }}
                </h5>
                <button type="button" class="close text-white" wire:click="$set('showModal',false)">&times;</button>
            </div>
            <div class="modal-body">

                <div class="row">
                    {{-- Patient --}}
                    <div class="col-md-5">
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">PATIENT (optional)</label>
                            <div class="position-relative">
                                <input type="text"
                                       wire:model.live.debounce.300ms="patientSearch"
                                       class="form-control form-control-sm"
                                       placeholder="Search by name or PX no…"
                                       autocomplete="off">
                                @if(!empty($patientResults))
                                    <div class="list-group position-absolute w-100 shadow-sm" style="z-index:9999; top:100%;">
                                        @foreach($patientResults as $p)
                                            <button type="button"
                                                    wire:click="selectPatient({{ $p['id'] }})"
                                                    class="list-group-item list-group-item-action py-1 px-2"
                                                    style="font-size:.82rem;">
                                                <strong>{{ $p['name'] }}</strong>
                                                <small class="text-muted ml-1">{{ $p['pxnumber'] }}</small>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            @if($patient_id)
                                <small class="text-success">
                                    <i class="fas fa-check-circle mr-1"></i>Linked to patient record
                                    <a href="#" wire:click.prevent="clearPatient" class="text-danger ml-2">unlink</a>
                                </small>
                            @endif
                        </div>
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">PATIENT NAME *</label>
                            <input type="text" wire:model="patient_name"
                                   class="form-control form-control-sm @error('patient_name') is-invalid @enderror">
                            @error('patient_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">PHONE</label>
                            <input type="text" wire:model="patient_phone" class="form-control form-control-sm">
                        </div>
                    </div>

                    {{-- Dates & status --}}
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">ISSUE DATE *</label>
                            <input type="date" wire:model="issue_date"
                                   class="form-control form-control-sm @error('issue_date') is-invalid @enderror">
                            @error('issue_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">VALID UNTIL *</label>
                            <input type="date" wire:model="valid_until"
                                   class="form-control form-control-sm @error('valid_until') is-invalid @enderror">
                            @error('valid_until')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">STATUS</label>
                            <select wire:model="status_field" class="form-control form-control-sm">
                                <option value="draft">Draft</option>
                                <option value="sent">Sent</option>
                                <option value="accepted">Accepted</option>
                                <option value="expired">Expired</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>

                    {{-- Notes --}}
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">NOTES</label>
                            <textarea wire:model="notes" rows="5"
                                      class="form-control form-control-sm"
                                      placeholder="Terms, delivery info…"></textarea>
                        </div>
                    </div>
                </div>

                {{-- Line items --}}
                <hr class="my-2">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0 font-weight-bold">Line Items</h6>
                    <button type="button" wire:click="addLine" class="btn btn-xs btn-outline-primary">
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
                                <th style="width:130px;">Unit Price *</th>
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
                                               placeholder="Item description or search product…"
                                               autocomplete="off">
                                        @if(!empty($productResults) && $productLineIdx === $i)
                                            <div class="list-group position-absolute shadow-sm" style="z-index:9999; top:100%; left:0; right:0; min-width:220px;">
                                                @foreach($productResults as $p)
                                                    <button type="button"
                                                            wire:click="selectProduct({{ $p['id'] }})"
                                                            class="list-group-item list-group-item-action py-1 px-2"
                                                            style="font-size:.8rem;">
                                                        {{ $p['name'] }}
                                                        <small class="text-muted float-right">{{ currency() }} {{ number_format($p['selling_price'], 2) }}</small>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                        @error('items.'.$i.'.description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </td>
                                    <td>
                                        <input type="number" wire:model.blur="items.{{ $i }}.quantity"
                                               class="form-control form-control-sm text-right @error('items.'.$i.'.quantity') is-invalid @enderror"
                                               style="min-width:70px;"
                                               min="0.01" step="0.01">
                                        @error('items.'.$i.'.quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </td>
                                    <td>
                                        <input type="number" wire:model.blur="items.{{ $i }}.unit_price"
                                               class="form-control form-control-sm text-right @error('items.'.$i.'.unit_price') is-invalid @enderror"
                                               style="min-width:100px;"
                                               min="0" step="0.01">
                                        @error('items.'.$i.'.unit_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                    </table>
                </div>

                {{-- Totals --}}
                <div class="row justify-content-end mt-3">
                    <div class="col-md-4">
                        <table class="table table-sm table-borderless mb-0" style="font-size:.88rem;">
                            <tr>
                                <td class="text-muted">Subtotal</td>
                                <td class="text-right font-weight-bold">{{ currency() }} {{ number_format($subtotal, 2) }}</td>
                            </tr>
                            <tr>
                                <td class="text-muted">Discount</td>
                                <td class="text-right">
                                    <div class="input-group input-group-sm" style="max-width:120px; float:right;">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text" style="font-size:.75rem;">{{ currency() }}</span>
                                        </div>
                                        <input type="number" wire:model.blur="discount_amount"
                                               class="form-control form-control-sm text-right" min="0" step="0.01">
                                    </div>
                                </td>
                            </tr>
                            <tr class="border-top">
                                <td class="font-weight-bold">TOTAL</td>
                                <td class="text-right font-weight-bold text-primary" style="font-size:1rem;">
                                    {{ currency() }} {{ number_format($total, 2) }}
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

            </div>{{-- /modal-body --}}
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" wire:click="$set('showModal',false)">Cancel</button>
                <button type="button" wire:click="save" class="btn btn-primary btn-sm">
                    <i class="fas fa-save mr-1"></i>{{ $isEditing ? 'Update' : 'Create' }} Quotation
                </button>
            </div>
        </div>
    </div>
</div>
@endif

<script>
    window.addEventListener('show-confirm', event => {
        Swal.fire({
            title: 'Are you sure?',
            text: event.detail.message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Yes, delete it',
        }).then(result => {
            if (result.isConfirmed) {
                @this.call(event.detail.action, event.detail.id);
            }
        });
    });
</script>
</div>{{-- single Livewire root --}}
