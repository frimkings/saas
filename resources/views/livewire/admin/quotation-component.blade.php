<div class="clinic-ui ui-page">
<div>
<div class="w-full">

    {{-- Header --}}
    <div class="ui-heading">
        <div>
            <h1 class="flex flex-wrap items-center gap-2">Quotations</h1>
            <p class="ui-muted">Proformas and estimates for patients.</p>
        </div>
        <button wire:click="openCreate" class="ui-button ui-button-primary ui-button-sm">
            <i class="fas fa-plus mr-1"></i>New Quotation
        </button>
    </div>

    {{-- Filters --}}
    <div class="ui-panel mb-4">
        <div class="p-5">
            <div class="flex flex-wrap -mx-2 items-center">
                <div class="w-full md:w-5/12 px-2 mb-2 md:mb-0">
                    <input aria-label="Search" wire:model.live.debounce.300ms="search" type="text"
                           class="ui-input ui-input-sm"
                           placeholder="Search by number or patient name…">
                </div>
                <div class="w-full md:w-3/12 px-2 mb-2 md:mb-0">
                    <select wire:model.live="status" aria-label="Status" class="ui-input ui-input-sm">
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="sent">Sent</option>
                        <option value="accepted">Accepted</option>
                        <option value="expired">Expired</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="w-full md:w-2/12 px-2">
                    <select aria-label="Rows per page" wire:model.live="perPage" class="ui-input ui-input-sm">
                        <option value="15">15 / page</option>
                        <option value="30">30 / page</option>
                        <option value="50">50 / page</option>
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
                                <td class="font-semibold">{{ $q->quotation_number }}</td>
                                <td>
                                    {{ $q->patient_name }}
                                    @if($q->patient_phone)
                                        <small class="text-slate-500 block">{{ $q->patient_phone }}</small>
                                    @endif
                                </td>
                                <td>{{ $q->issue_date->format('d M Y') }}</td>
                                <td class="{{ $q->valid_until->isPast() && $q->status === 'draft' ? 'text-red-700 font-semibold' : '' }}">
                                    {{ $q->valid_until->format('d M Y') }}
                                </td>
                                <td class="font-semibold">{{ currency() }} {{ number_format($q->total_amount, 2) }}</td>
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
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $badge }}">{{ ucfirst($q->status) }}</span>
                                </td>
                                <td class="text-center" style="white-space:nowrap;">
                                    {{-- Status change --}}
                                    <div class="dropdown inline-block">
                                        <button class="ui-button ui-button-sm ui-button-secondary dropdown-toggle" data-toggle="dropdown">
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
                                            class="ui-button ui-button-sm ui-button-secondary ml-1"
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="{{ route('admin.quotations.pdf', $q->id) }}"
                                       target="_blank"
                                       class="ui-button ui-button-sm ui-button-secondary ml-1"
                                       title="Download PDF">
                                        <i class="fas fa-file-pdf"></i>
                                    </a>
                                    @hasrole('Super Admin')
                                    <button wire:click="confirmDelete({{ $q->id }})"
                                            class="ui-button ui-button-sm ui-button-danger ml-1"
                                            title="Delete (soft)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    @endhasrole
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-slate-500 py-6">No quotations found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($quotations->hasPages())
            <div class="border-t border-slate-200 px-4 py-2 border-0 bg-white">
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
<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show block" tabindex="-1" style="background:rgba(0,0,0,.5);">
    <div class="mx-auto my-8 w-full max-w-6xl">
        <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-teal-700 text-white">
                <h5 class="text-base font-semibold">
                    <i class="fas fa-file-invoice mr-2"></i>
                    {{ $isEditing ? 'Edit Quotation' : 'New Quotation' }}
                </h5>
                <button type="button" class="text-xl leading-none hover:text-slate-800 text-white" wire:click="$set('showModal',false)">&times;</button>
            </div>
            <div class="p-4">

                <div class="flex flex-wrap -mx-2">
                    {{-- Patient --}}
                    <div class="w-full md:w-5/12 px-2">
                        <div class="mb-4">
                            <label for="quotation-patientsearch" class="text-sm font-semibold text-slate-500">PATIENT (optional)</label>
                            <div class="relative">
                                <input id="quotation-patientsearch" type="text"
                                       wire:model.live.debounce.300ms="patientSearch"
                                       class="ui-input ui-input-sm"
                                       placeholder="Search by name or PX no…"
                                       autocomplete="off">
                                @if(!empty($patientResults))
                                    <div class="overflow-hidden rounded-md border border-slate-200 bg-white absolute w-full shadow-sm" style="z-index:9999; top:100%;">
                                        @foreach($patientResults as $p)
                                            <button type="button"
                                                    wire:click="selectPatient({{ $p['id'] }})"
                                                    class="list-group-item block w-full border-b border-slate-100 text-left hover:bg-slate-50 py-1 px-2"
                                                    style="font-size:.82rem;">
                                                <strong>{{ $p['name'] }}</strong>
                                                <small class="text-slate-500 ml-1">{{ $p['pxnumber'] }}</small>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            @if($patient_id)
                                <small class="text-green-700">
                                    <i class="fas fa-check-circle mr-1"></i>Linked to patient record
                                    <a href="#" wire:click.prevent="clearPatient" class="text-red-700 ml-2">unlink</a>
                                </small>
                            @endif
                        </div>
                        <div class="mb-4">
                            <label for="quotation-patient-name" class="text-sm font-semibold text-slate-500">PATIENT NAME *</label>
                            <input id="quotation-patient-name" type="text" wire:model="patient_name"
                                   class="ui-input ui-input-sm @error('patient_name') is-invalid @enderror">
                            @error('patient_name')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-4">
                            <label for="quotation-patient-phone" class="text-sm font-semibold text-slate-500">PHONE</label>
                            <input id="quotation-patient-phone" type="text" wire:model="patient_phone" class="ui-input ui-input-sm">
                        </div>
                    </div>

                    {{-- Dates & status --}}
                    <div class="w-full md:w-4/12 px-2">
                        <div class="mb-4">
                            <label for="quotation-issue-date" class="text-sm font-semibold text-slate-500">ISSUE DATE *</label>
                            <input id="quotation-issue-date" type="date" wire:model="issue_date"
                                   class="ui-input ui-input-sm @error('issue_date') is-invalid @enderror">
                            @error('issue_date')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-4">
                            <label for="quotation-valid-until" class="text-sm font-semibold text-slate-500">VALID UNTIL *</label>
                            <input id="quotation-valid-until" type="date" wire:model="valid_until"
                                   class="ui-input ui-input-sm @error('valid_until') is-invalid @enderror">
                            @error('valid_until')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-4">
                            <label for="quotation-status-field" class="text-sm font-semibold text-slate-500">STATUS</label>
                            <select id="quotation-status-field" wire:model="status_field" class="ui-input ui-input-sm">
                                <option value="draft">Draft</option>
                                <option value="sent">Sent</option>
                                <option value="accepted">Accepted</option>
                                <option value="expired">Expired</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>

                    {{-- Notes --}}
                    <div class="w-full md:w-3/12 px-2">
                        <div class="mb-4">
                            <label for="quotation-notes" class="text-sm font-semibold text-slate-500">NOTES</label>
                            <textarea id="quotation-notes" wire:model="notes" rows="5"
                                      class="ui-input ui-input-sm"
                                      placeholder="Terms, delivery info…"></textarea>
                        </div>
                    </div>
                </div>

                {{-- Line items --}}
                <hr class="my-2">
                <div class="flex justify-between items-center mb-2">
                    <h6 class="mb-0 font-semibold">Line Items</h6>
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
                                <th style="width:130px;">Unit Price *</th>
                                <th style="width:120px;">Subtotal</th>
                                <th style="width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $i => $item)
                                <tr>
                                    <td class="relative">
                                        <input aria-label="Item description or search product" type="text"
                                               wire:model="items.{{ $i }}.description"
                                               x-on:input.debounce.300ms="$wire.searchProducts({{ $i }}, $event.target.value)"
                                               class="ui-input ui-input-sm @error('items.'.$i.'.description') is-invalid @enderror"
                                               placeholder="Item description or search product…"
                                               autocomplete="off">
                                        @if(!empty($productResults) && $productLineIdx === $i)
                                            <div class="overflow-hidden rounded-md border border-slate-200 bg-white absolute shadow-sm" style="z-index:9999; top:100%; left:0; right:0; min-width:220px;">
                                                @foreach($productResults as $p)
                                                    <button type="button"
                                                            wire:click="selectProduct({{ $p['id'] }})"
                                                            class="list-group-item block w-full border-b border-slate-100 text-left hover:bg-slate-50 py-1 px-2"
                                                            style="font-size:.8rem;">
                                                        {{ $p['name'] }}
                                                        <small class="text-slate-500">{{ currency() }} {{ number_format($p['selling_price'], 2) }}</small>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                        @error('items.'.$i.'.description')<div class="ui-error">{{ $message }}</div>@enderror
                                    </td>
                                    <td>
                                        <input type="number" wire:model.blur="items.{{ $i }}.quantity" aria-label="Quantity"
                                               class="ui-input ui-input-sm text-right @error('items.'.$i.'.quantity') is-invalid @enderror"
                                               style="min-width:70px;"
                                               min="0.01" step="0.01">
                                        @error('items.'.$i.'.quantity')<div class="ui-error">{{ $message }}</div>@enderror
                                    </td>
                                    <td>
                                        <input type="number" wire:model.blur="items.{{ $i }}.unit_price" aria-label="Unit price"
                                               class="ui-input ui-input-sm text-right @error('items.'.$i.'.unit_price') is-invalid @enderror"
                                               style="min-width:100px;"
                                               min="0" step="0.01">
                                        @error('items.'.$i.'.unit_price')<div class="ui-error">{{ $message }}</div>@enderror
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
                    </table>
                </div>

                {{-- Totals --}}
                <div class="flex flex-wrap -mx-2 justify-end mt-4">
                    <div class="w-full md:w-4/12 px-2">
                        <table class="ui-table ui-table-sm mb-0" style="font-size:.88rem;">
                            <tr>
                                <td class="text-slate-500">Subtotal</td>
                                <td class="text-right font-semibold">{{ currency() }} {{ number_format($subtotal, 2) }}</td>
                            </tr>
                            <tr>
                                <td class="text-slate-500">Discount</td>
                                <td class="text-right">
                                    <div class="flex items-stretch" style="max-width:120px; float:right;">
                                        <div class="flex">
                                            <span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600" style="font-size:.75rem;">{{ currency() }}</span>
                                        </div>
                                        <input type="number" wire:model.blur="discount_amount" aria-label="Discount"
                                               class="ui-input ui-input-sm text-right" min="0" step="0.01">
                                    </div>
                                </td>
                            </tr>
                            <tr class="border-t border-slate-200">
                                <td class="font-semibold">TOTAL</td>
                                <td class="text-right font-semibold text-teal-700" style="font-size:1rem;">
                                    {{ currency() }} {{ number_format($total, 2) }}
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

            </div>{{-- /modal-body --}}
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="$set('showModal',false)">Cancel</button>
                <button type="button" wire:click="save" class="ui-button ui-button-primary ui-button-sm">
                    <i class="fas fa-save mr-1"></i>{{ $isEditing ? 'Update' : 'Create' }} Quotation
                </button>
            </div>
        </div>
    </div>
</div>
@endif

<script>
    window.addEventListener('show-confirm', event => {
        window.appConfirm(event.detail.message, { title: 'Are you sure?', confirmText: 'Yes, delete it', danger: true })
            .then(ok => ok && @this.call(event.detail.action, event.detail.id));
    });
</script>
</div>{{-- single Livewire root --}}
