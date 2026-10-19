<div class="clinic-ui ui-page">
    {{-- Page header --}}
    <x-ui.page-header title="Suppliers" description="The companies you buy from." />

    <section class="content">
        <div class="w-full">

            {{-- Stats + controls --}}
            <div class="ui-tiles ui-tiles--auto mb-4">
                <div>
                    <div class="ui-tile"><span class="ui-tile__label"><i class="fas fa-truck" aria-hidden="true"></i>Total Suppliers</span><span class="ui-tile__value">{{ \App\Models\Supplier::count() }}</span></div>
                </div>
                <div>
                    <div class="ui-tile"><span class="ui-tile__label"><i class="fas fa-check-circle" aria-hidden="true"></i>Active</span><span class="ui-tile__value">{{ \App\Models\Supplier::where('is_active', true)->count() }}</span></div>
                </div>
            </div>

            <div class="ui-panel">
                <div class="ui-section-heading">
                    <div class="flex items-center flex-wrap" style="gap:8px;">
                        <div class="flex items-stretch" style="max-width:320px;">
                            <div class="flex">
                                <span class="flex items-center border border-slate-300 px-2 text-sm text-slate-600 bg-white"><i class="fas fa-search text-slate-500"></i></span>
                            </div>
                            <input aria-label="Search" wire:model.live.debounce.300ms="searchTerm"
                                type="text" class="ui-input border-l-0"
                                placeholder="Search name, contact, phone…">
                            @if($searchTerm)
                                <div class="flex">
                                    <button wire:click="$set('searchTerm', '')" class="ui-button ui-button-secondary">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            @endif
                        </div>
                        <button wire:click="openCreate" class="ui-button ui-button-primary ml-auto">
                            <i class="fas fa-plus mr-1"></i>Add Supplier
                        </button>
                    </div>
                </div>

                <div class="">
                    <div class="ui-table-wrap">
                        <table class="ui-table mb-0">
                            <thead class="">
                                <tr>
                                    <th>#</th>
                                    <th>Supplier Name</th>
                                    <th>Contact Person</th>
                                    <th>Phone</th>
                                    <th>Email</th>
                                    <th class="text-center">Lead Time</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody wire:loading.class="opacity-50">
                                @forelse($suppliers as $supplier)
                                    <tr>
                                        <td class="text-slate-500">{{ $suppliers->firstItem() + $loop->index }}</td>
                                        <td>
                                            <div class="font-semibold">{{ $supplier->name }}</div>
                                            @if($supplier->address)
                                                <small class="text-slate-500">{{ Str::limit($supplier->address, 40) }}</small>
                                            @endif
                                        </td>
                                        <td>{{ $supplier->contact_person ?: '—' }}</td>
                                        <td>
                                            @if($supplier->phone)
                                                <a href="tel:{{ $supplier->phone }}">{{ $supplier->phone }}</a>
                                            @else
                                                <span class="text-slate-500">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($supplier->email)
                                                <a href="mailto:{{ $supplier->email }}" class="truncate inline-block" style="max-width:160px;">{{ $supplier->email }}</a>
                                            @else
                                                <span class="text-slate-500">—</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($supplier->lead_time_days)
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-sky-100 text-sky-800">{{ $supplier->lead_time_days }} day(s)</span>
                                            @else
                                                <span class="text-slate-500">—</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <button wire:click="toggleActive({{ $supplier->id }})"
                                                class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $supplier->is_active ? 'success' : 'secondary' }} border-0"
                                                style="cursor:pointer; font-size:11px; padding:4px 8px;"
                                                title="Click to toggle">
                                                {{ $supplier->is_active ? 'Active' : 'Inactive' }}
                                            </button>
                                        </td>
                                        <td class="text-center">
                                            <div class="inline-flex flex-wrap gap-1">
                                                <button wire:click="openEdit({{ $supplier->id }})"
                                                    class="ui-button ui-button-secondary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button wire:click="confirmDelete({{ $supplier->id }})"
                                                    class="ui-button ui-button-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center py-12 text-slate-500">
                                            <i class="fas fa-truck fa-3x mb-4 block text-slate-500"></i>
                                            @if($searchTerm)
                                                <h5>No suppliers match "{{ $searchTerm }}"</h5>
                                            @else
                                                <h5>No suppliers added yet</h5>
                                                <button wire:click="openCreate" class="ui-button ui-button-primary ui-button-sm mt-2">
                                                    <i class="fas fa-plus mr-1"></i>Add First Supplier
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($suppliers->hasPages())
                    <div class="border-t border-slate-200 bg-slate-50 px-4 py-2">
                        {{ $suppliers->links() }}
                    </div>
                @endif
            </div>

        </div>
    </section>

    {{-- Create / Edit Modal --}}
    @if($showModal)
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show block" tabindex="-1" role="dialog" style="background:rgba(0,0,0,.5);">
        <div class="mx-auto my-8 w-full max-w-3xl" role="document">
            <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-teal-700 text-white">
                    <h5 class="text-base font-semibold">
                        <i class="fas fa-truck mr-2"></i>
                        {{ $isEditing ? 'Edit Supplier' : 'Add New Supplier' }}
                    </h5>
                    <button wire:click="$set('showModal', false)" type="button" class="text-xl leading-none hover:text-slate-800 text-white">
                        <span>&times;</span>
                    </button>
                </div>

                <div class="p-4">
                    <div class="flex flex-wrap -mx-2">
                        {{-- Name --}}
                        <div class="w-full md:w-6/12 px-2 mb-4">
                            <label for="supplier-state-name">Supplier Name <span class="text-red-700">*</span></label>
                            <input id="supplier-state-name" wire:model="state.name" type="text"
                                class="ui-input @error('state.name') is-invalid @enderror"
                                placeholder="e.g. Luxottica Ghana Ltd">
                            @error('state.name')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                        {{-- Contact Person --}}
                        <div class="w-full md:w-6/12 px-2 mb-4">
                            <label for="supplier-state-contact-person">Contact Person</label>
                            <input id="supplier-state-contact-person" wire:model="state.contact_person" type="text"
                                class="ui-input @error('state.contact_person') is-invalid @enderror"
                                placeholder="e.g. Ama Asante">
                            @error('state.contact_person')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                        {{-- Phone --}}
                        <div class="w-full md:w-6/12 px-2 mb-4">
                            <label for="supplier-state-phone">Phone</label>
                            <input id="supplier-state-phone" wire:model="state.phone" type="text"
                                class="ui-input @error('state.phone') is-invalid @enderror"
                                placeholder="e.g. 0244000000">
                            @error('state.phone')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                        {{-- Email --}}
                        <div class="w-full md:w-6/12 px-2 mb-4">
                            <label for="supplier-state-email">Email</label>
                            <input id="supplier-state-email" wire:model="state.email" type="email"
                                class="ui-input @error('state.email') is-invalid @enderror"
                                placeholder="supplier@example.com">
                            @error('state.email')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                        {{-- Address --}}
                        <div class="w-full md:w-8/12 px-2 mb-4">
                            <label for="supplier-state-address">Address</label>
                            <input id="supplier-state-address" wire:model="state.address" type="text"
                                class="ui-input @error('state.address') is-invalid @enderror"
                                placeholder="Street / Area / City">
                            @error('state.address')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                        {{-- Lead Time --}}
                        <div class="w-full md:w-4/12 px-2 mb-4">
                            <label for="supplier-state-lead-time-days">Lead Time (days)</label>
                            <input id="supplier-state-lead-time-days" wire:model="state.lead_time_days" type="number"
                                min="1" max="365"
                                class="ui-input @error('state.lead_time_days') is-invalid @enderror"
                                placeholder="e.g. 7">
                            @error('state.lead_time_days')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                        {{-- Notes --}}
                        <div class="w-full md:w-full px-2 mb-4">
                            <label for="supplier-state-notes">Notes</label>
                            <textarea id="supplier-state-notes" wire:model="state.notes" rows="2"
                                class="ui-input @error('state.notes') is-invalid @enderror"
                                placeholder="Payment terms, delivery conditions, etc."></textarea>
                            @error('state.notes')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                        {{-- Active toggle --}}
                        <div class="w-full md:w-full px-2 mb-0">
                            <div class="flex items-center gap-2">
                                <input wire:model="state.is_active"
                                    type="checkbox" class="rounded border-slate-300 text-teal-700" id="supplierActive">
                                <label class="" for="supplierActive">Active supplier</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                    <button wire:click="$set('showModal', false)" type="button" class="ui-button ui-button-secondary">Cancel</button>
                    <button wire:click="save" type="button" class="ui-button ui-button-primary">
                        <i class="fas fa-save mr-1"></i>
                        {{ $isEditing ? 'Update Supplier' : 'Save Supplier' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>
