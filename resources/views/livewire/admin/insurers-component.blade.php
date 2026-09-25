<div>
  {{-- Page Header --}}
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0"><i class="fas fa-building mr-2 text-info"></i>Insurers</h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.insurance.claims') }}">Insurance</a></li>
            <li class="breadcrumb-item active">Insurers</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="content">
    <div class="container-fluid">

      {{-- Filters + Actions --}}
      <div class="card card-outline card-info shadow-sm">
        <div class="card-header flex-wrap" style="gap:8px;">
          <div class="d-flex align-items-center flex-wrap w-100" style="gap:8px;">
            <div class="input-group" style="max-width:260px;">
              <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
              <input wire:model.live.debounce.300ms="search" type="text" class="form-control" placeholder="Search name or code…">
            </div>
            <select wire:model.live="schemeFilter" class="form-control" style="max-width:160px;">
              <option value="">All Schemes</option>
              <option value="NHIS">NHIS</option>
              <option value="Private">Private</option>
              <option value="Corporate">Corporate</option>
            </select>
            <div class="ml-auto">
              <button wire:click="openCreate" class="btn btn-info btn-sm">
                <i class="fas fa-plus mr-1"></i>Add Insurer
              </button>
            </div>
          </div>
        </div>

        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
              <thead class="thead-light">
                <tr>
                  <th>Name</th>
                  <th>Code</th>
                  <th>Scheme</th>
                  <th>Contact</th>
                  <th class="text-center">Claims</th>
                  <th class="text-center">Status</th>
                  <th class="text-center">Actions</th>
                </tr>
              </thead>
              <tbody wire:loading.class="opacity-50">
                @forelse($insurers as $insurer)
                <tr>
                  <td class="font-weight-bold">{{ $insurer->name }}</td>
                  <td class="text-muted small">{{ $insurer->code ?: '—' }}</td>
                  <td>
                    <span class="badge {{ $insurer->schemeBadgeClass() }}">{{ $insurer->scheme_type }}</span>
                  </td>
                  <td class="small">
                    @if($insurer->contact_person)
                      {{ $insurer->contact_person }}
                      @if($insurer->contact_phone)
                        <br><span class="text-muted">{{ $insurer->contact_phone }}</span>
                      @endif
                    @else
                      <span class="text-muted">—</span>
                    @endif
                  </td>
                  <td class="text-center">
                    <span class="badge badge-secondary">{{ $insurer->claims_count }}</span>
                  </td>
                  <td class="text-center">
                    <span class="badge {{ $insurer->active ? 'badge-success' : 'badge-light text-muted' }}">
                      {{ $insurer->active ? 'Active' : 'Inactive' }}
                    </span>
                  </td>
                  <td class="text-center" style="white-space:nowrap;">
                    <button wire:click="openEdit({{ $insurer->id }})" class="btn btn-xs btn-outline-secondary" title="Edit">
                      <i class="fas fa-edit"></i>
                    </button>
                    <button wire:click="toggleActive({{ $insurer->id }})" class="btn btn-xs btn-outline-{{ $insurer->active ? 'warning' : 'success' }}" title="{{ $insurer->active ? 'Deactivate' : 'Activate' }}">
                      <i class="fas fa-{{ $insurer->active ? 'ban' : 'check' }}"></i>
                    </button>
                    <button wire:click="delete({{ $insurer->id }})"
                            onclick="return confirm('Delete {{ addslashes($insurer->name) }}? This cannot be undone.')"
                            class="btn btn-xs btn-outline-danger" title="Delete">
                      <i class="fas fa-trash"></i>
                    </button>
                  </td>
                </tr>
                @empty
                <tr>
                  <td colspan="7" class="text-center py-5 text-muted">
                    <i class="fas fa-building fa-3x mb-3 d-block text-secondary"></i>
                    <h5>No insurers found</h5>
                    <button wire:click="openCreate" class="btn btn-info btn-sm mt-2">
                      <i class="fas fa-plus mr-1"></i>Add your first insurer
                    </button>
                  </td>
                </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>

        @if($insurers->hasPages())
        <div class="card-footer">{{ $insurers->links() }}</div>
        @endif
      </div>

    </div>
  </div>

  {{-- Create / Edit Modal --}}
  @if($showModal)
  <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background:rgba(0,0,0,.5);">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header bg-info text-white">
          <h5 class="modal-title">
            <i class="fas fa-building mr-2"></i>
            {{ $isEditing ? 'Edit Insurer' : 'Add Insurer' }}
          </h5>
          <button wire:click="$set('showModal', false)" type="button" class="close text-white"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>Insurer Name <span class="text-danger">*</span></label>
            <input wire:model="state.name" type="text" class="form-control @error('state.name') is-invalid @enderror" placeholder="e.g. National Health Insurance Scheme">
            @error('state.name')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="row">
            <div class="col-md-6">
              <div class="form-group">
                <label>Code</label>
                <input wire:model="state.code" type="text" class="form-control" placeholder="e.g. NHIS">
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-group">
                <label>Scheme Type <span class="text-danger">*</span></label>
                <select wire:model="state.scheme_type" class="form-control @error('state.scheme_type') is-invalid @enderror">
                  <option value="NHIS">NHIS</option>
                  <option value="Private">Private</option>
                  <option value="Corporate">Corporate</option>
                </select>
                @error('state.scheme_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6">
              <div class="form-group">
                <label>Contact Person</label>
                <input wire:model="state.contact_person" type="text" class="form-control" placeholder="Name">
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-group">
                <label>Contact Phone</label>
                <input wire:model="state.contact_phone" type="text" class="form-control" placeholder="Phone number">
              </div>
            </div>
          </div>
          <div class="form-group">
            <label>Notes</label>
            <textarea wire:model="state.notes" class="form-control" rows="2" placeholder="Any additional notes…"></textarea>
          </div>
          <div class="form-group mb-0">
            <div class="custom-control custom-switch">
              <input wire:model="state.active" type="checkbox" class="custom-control-input" id="insurerActive">
              <label class="custom-control-label" for="insurerActive">Active</label>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button wire:click="$set('showModal', false)" class="btn btn-secondary">Cancel</button>
          <button wire:click="save" class="btn btn-info">
            <i class="fas fa-save mr-1"></i>{{ $isEditing ? 'Update' : 'Add Insurer' }}
          </button>
        </div>
      </div>
    </div>
  </div>
  @endif
</div>
