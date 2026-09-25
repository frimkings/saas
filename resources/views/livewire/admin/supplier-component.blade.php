<div>
    {{-- Page header --}}
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2 align-items-center">
                <div class="col-sm-6">
                    <h1 class="m-0">
                        <i class="fas fa-truck mr-2 text-primary"></i>Supplier Management
                    </h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Suppliers</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">

            {{-- Stats + controls --}}
            <div class="row mb-3">
                <div class="col-md-3">
                    <div class="info-box shadow-sm mb-0">
                        <span class="info-box-icon bg-primary"><i class="fas fa-truck"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Total Suppliers</span>
                            <span class="info-box-number">{{ \App\Models\Supplier::count() }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="info-box shadow-sm mb-0">
                        <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Active</span>
                            <span class="info-box-number">{{ \App\Models\Supplier::where('is_active', true)->count() }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-outline card-primary shadow-sm">
                <div class="card-header">
                    <div class="d-flex align-items-center flex-wrap" style="gap:8px;">
                        <div class="input-group" style="max-width:320px;">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                            </div>
                            <input wire:model.live.debounce.300ms="searchTerm"
                                type="text" class="form-control border-left-0"
                                placeholder="Search name, contact, phone…">
                            @if($searchTerm)
                                <div class="input-group-append">
                                    <button wire:click="$set('searchTerm', '')" class="btn btn-outline-secondary">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            @endif
                        </div>
                        <button wire:click="openCreate" class="btn btn-primary ml-auto">
                            <i class="fas fa-plus mr-1"></i>Add Supplier
                        </button>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="thead-light">
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
                                        <td class="text-muted">{{ $suppliers->firstItem() + $loop->index }}</td>
                                        <td>
                                            <div class="font-weight-bold">{{ $supplier->name }}</div>
                                            @if($supplier->address)
                                                <small class="text-muted">{{ Str::limit($supplier->address, 40) }}</small>
                                            @endif
                                        </td>
                                        <td>{{ $supplier->contact_person ?: '—' }}</td>
                                        <td>
                                            @if($supplier->phone)
                                                <a href="tel:{{ $supplier->phone }}">{{ $supplier->phone }}</a>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($supplier->email)
                                                <a href="mailto:{{ $supplier->email }}" class="text-truncate d-inline-block" style="max-width:160px;">{{ $supplier->email }}</a>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($supplier->lead_time_days)
                                                <span class="badge badge-info">{{ $supplier->lead_time_days }} day(s)</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <button wire:click="toggleActive({{ $supplier->id }})"
                                                class="badge badge-{{ $supplier->is_active ? 'success' : 'secondary' }} border-0"
                                                style="cursor:pointer; font-size:11px; padding:4px 8px;"
                                                title="Click to toggle">
                                                {{ $supplier->is_active ? 'Active' : 'Inactive' }}
                                            </button>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <button wire:click="openEdit({{ $supplier->id }})"
                                                    class="btn btn-outline-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button wire:click="confirmDelete({{ $supplier->id }})"
                                                    class="btn btn-outline-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center py-5 text-muted">
                                            <i class="fas fa-truck fa-3x mb-3 d-block text-secondary"></i>
                                            @if($searchTerm)
                                                <h5>No suppliers match "{{ $searchTerm }}"</h5>
                                            @else
                                                <h5>No suppliers added yet</h5>
                                                <button wire:click="openCreate" class="btn btn-primary btn-sm mt-2">
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
                    <div class="card-footer">
                        {{ $suppliers->links() }}
                    </div>
                @endif
            </div>

        </div>
    </section>

    {{-- Create / Edit Modal --}}
    @if($showModal)
    <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background:rgba(0,0,0,.5);">
        <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-truck mr-2"></i>
                        {{ $isEditing ? 'Edit Supplier' : 'Add New Supplier' }}
                    </h5>
                    <button wire:click="$set('showModal', false)" type="button" class="close text-white">
                        <span>&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    <div class="row">
                        {{-- Name --}}
                        <div class="col-md-6 form-group">
                            <label>Supplier Name <span class="text-danger">*</span></label>
                            <input wire:model="state.name" type="text"
                                class="form-control @error('state.name') is-invalid @enderror"
                                placeholder="e.g. Luxottica Ghana Ltd">
                            @error('state.name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        {{-- Contact Person --}}
                        <div class="col-md-6 form-group">
                            <label>Contact Person</label>
                            <input wire:model="state.contact_person" type="text"
                                class="form-control @error('state.contact_person') is-invalid @enderror"
                                placeholder="e.g. Ama Asante">
                            @error('state.contact_person')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        {{-- Phone --}}
                        <div class="col-md-6 form-group">
                            <label>Phone</label>
                            <input wire:model="state.phone" type="text"
                                class="form-control @error('state.phone') is-invalid @enderror"
                                placeholder="e.g. 0244000000">
                            @error('state.phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        {{-- Email --}}
                        <div class="col-md-6 form-group">
                            <label>Email</label>
                            <input wire:model="state.email" type="email"
                                class="form-control @error('state.email') is-invalid @enderror"
                                placeholder="supplier@example.com">
                            @error('state.email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        {{-- Address --}}
                        <div class="col-md-8 form-group">
                            <label>Address</label>
                            <input wire:model="state.address" type="text"
                                class="form-control @error('state.address') is-invalid @enderror"
                                placeholder="Street / Area / City">
                            @error('state.address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        {{-- Lead Time --}}
                        <div class="col-md-4 form-group">
                            <label>Lead Time (days)</label>
                            <input wire:model="state.lead_time_days" type="number"
                                min="1" max="365"
                                class="form-control @error('state.lead_time_days') is-invalid @enderror"
                                placeholder="e.g. 7">
                            @error('state.lead_time_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        {{-- Notes --}}
                        <div class="col-md-12 form-group">
                            <label>Notes</label>
                            <textarea wire:model="state.notes" rows="2"
                                class="form-control @error('state.notes') is-invalid @enderror"
                                placeholder="Payment terms, delivery conditions, etc."></textarea>
                            @error('state.notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        {{-- Active toggle --}}
                        <div class="col-md-12 form-group mb-0">
                            <div class="custom-control custom-switch">
                                <input wire:model="state.is_active"
                                    type="checkbox" class="custom-control-input" id="supplierActive">
                                <label class="custom-control-label" for="supplierActive">Active supplier</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button wire:click="$set('showModal', false)" type="button" class="btn btn-secondary">Cancel</button>
                    <button wire:click="save" type="button" class="btn btn-primary">
                        <i class="fas fa-save mr-1"></i>
                        {{ $isEditing ? 'Update Supplier' : 'Save Supplier' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>
