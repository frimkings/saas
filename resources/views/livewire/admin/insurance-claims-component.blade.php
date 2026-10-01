<div data-livewire-root>
<div>
  {{-- Page Header --}}
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0"><i class="fas fa-shield-alt mr-2 text-primary"></i>Insurance Claims</h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item active">Insurance Claims</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="content">
    <div class="container-fluid">

      {{-- Stats Row --}}
      <div class="row mb-3">
        <div class="col-6 col-md-3">
          <div class="info-box shadow-sm mb-0">
            <span class="info-box-icon bg-secondary"><i class="fas fa-file-alt"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Pending Submission</span>
              <span class="info-box-number">{{ $draftCount }}</span>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="info-box shadow-sm mb-0">
            <span class="info-box-icon bg-primary"><i class="fas fa-paper-plane"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Awaiting Approval</span>
              <span class="info-box-number">{{ $submittedCount }}</span>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-2">
          <div class="info-box shadow-sm mb-0">
            <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Total Approved</span>
              <span class="info-box-number">{{ currency() }} {{ number_format($approvedSum, 2) }}</span>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-2">
          <div class="info-box shadow-sm mb-0">
            <span class="info-box-icon bg-warning"><i class="fas fa-clock"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Outstanding</span>
              <span class="info-box-number">{{ currency() }} {{ number_format($outstandingSum, 2) }}</span>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-2">
          <div class="info-box shadow-sm mb-0" style="cursor:pointer;"
               wire:click="$set('preAuthFilter', {{ $pendingPreAuth > 0 ? '\'pending\'' : '\'\'' }})">
            <span class="info-box-icon {{ $pendingPreAuth > 0 ? 'bg-orange' : 'bg-light' }}">
              <i class="fas fa-id-card" style="{{ $pendingPreAuth > 0 ? '' : 'color:#aaa;' }}"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text">Pre-Auth Pending</span>
              <span class="info-box-number {{ $pendingPreAuth > 0 ? 'text-orange' : 'text-muted' }}">{{ $pendingPreAuth }}</span>
            </div>
          </div>
        </div>
      </div>

      <div class="card card-outline card-primary shadow-sm">

        {{-- Tabs --}}
        <div class="card-header p-0 border-bottom-0">
          <ul class="nav nav-tabs" style="flex-wrap:nowrap; overflow-x:auto;">
            @foreach(['all' => 'All', 'draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'partially_approved' => 'Part. Approved', 'rejected' => 'Rejected', 'paid' => 'Paid'] as $tab => $label)
            <li class="nav-item">
              <a class="nav-link {{ $activeTab === $tab ? 'active' : '' }}"
                 wire:click="$set('activeTab', '{{ $tab }}')" href="#" style="white-space:nowrap;">
                {{ $label }}
              </a>
            </li>
            @endforeach
          </ul>
        </div>

        {{-- Filters --}}
        <div class="card-header flex-wrap" style="gap:8px; border-top: 1px solid #dee2e6;">
          <div class="d-flex align-items-center flex-wrap w-100" style="gap:8px;">
            <div class="input-group" style="max-width:240px;">
              <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
              <input wire:model.live.debounce.300ms="search" type="text" class="form-control" placeholder="Patient name or Px#…">
            </div>
            <select wire:model.live="insurerFilter" class="form-control" style="max-width:200px;">
              <option value="">All Insurers</option>
              @foreach($insurers as $ins)
                <option value="{{ $ins->id }}">{{ $ins->name }}</option>
              @endforeach
            </select>
            <select wire:model.live="preAuthFilter" class="form-control" style="max-width:170px;">
              <option value="">All Pre-Auth</option>
              <option value="not_required">Not Required</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </select>
            <x-date-range from="fromDate" to="toDate" presets="finance" clearable />
            <div class="ml-auto d-flex" style="gap:6px;">
              <button wire:click="exportCsv" class="btn btn-outline-success btn-sm">
                <i class="fas fa-file-csv mr-1"></i>Export CSV
              </button>
              <button wire:click="openCreate" class="btn btn-primary btn-sm">
                <i class="fas fa-plus mr-1"></i>Log Claim
              </button>
            </div>
          </div>
        </div>

        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
              <thead class="thead-light">
                <tr>
                  <th>#</th>
                  <th>Patient</th>
                  <th>Insurer</th>
                  <th>Member ID</th>
                  <th class="text-right">Claim</th>
                  <th class="text-right">Approved</th>
                  <th class="text-center">Pre-Auth</th>
                  <th class="text-center">Status</th>
                  <th class="text-center">Date</th>
                  <th class="text-center">Actions</th>
                </tr>
              </thead>
              <tbody wire:loading.class="opacity-50">
                @forelse($claims as $claim)
                <tr>
                  <td class="text-muted small">{{ $claim->id }}</td>
                  <td>
                    <div class="font-weight-bold">{{ $claim->patient->name ?? '—' }}</div>
                    <div class="text-muted small">{{ $claim->patient->pxnumber ?? '' }}</div>
                  </td>
                  <td>
                    <div>{{ $claim->insurer->name ?? '—' }}</div>
                    @if($claim->insurer)
                      <span class="badge {{ $claim->insurer->schemeBadgeClass() }} badge-sm">{{ $claim->insurer->scheme_type }}</span>
                    @endif
                  </td>
                  <td class="small text-muted">{{ $claim->member_id ?: '—' }}</td>
                  <td class="text-right font-weight-bold">{{ currency() }} {{ number_format($claim->claim_amount, 2) }}</td>
                  <td class="text-right">
                    @if($claim->approved_amount !== null)
                      {{ currency() }} {{ number_format($claim->approved_amount, 2) }}
                    @else
                      <span class="text-muted">—</span>
                    @endif
                  </td>
                  <td class="text-center">
                    <span class="badge {{ $claim->preAuthBadgeClass() }}" title="{{ $claim->pre_auth_code ? 'Code: '.$claim->pre_auth_code : '' }}">
                      {{ $claim->preAuthLabel() }}
                    </span>
                    @if($claim->pre_auth_expired)
                      <span class="badge badge-danger d-block mt-1" style="font-size:.65rem;">Expired</span>
                    @endif
                  </td>
                  <td class="text-center">
                    <span class="badge {{ $claim->statusBadgeClass() }}">{{ $claim->statusLabel() }}</span>
                  </td>
                  <td class="text-center small text-muted">{{ $claim->created_at->format('d M Y') }}</td>
                  <td class="text-center" style="white-space:nowrap;">
                    {{-- Edit (only draft) --}}
                    @if($claim->status === 'draft')
                    <button wire:click="openEdit({{ $claim->id }})" class="btn btn-xs btn-outline-secondary" title="Edit">
                      <i class="fas fa-edit"></i>
                    </button>
                    @endif
                    {{-- Status transitions --}}
                    @if($claim->status === 'draft')
                      <button wire:click="openStatusModal({{ $claim->id }}, 'submitted')" class="btn btn-xs btn-primary" title="Submit">
                        <i class="fas fa-paper-plane"></i>
                      </button>
                    @elseif($claim->status === 'submitted')
                      <button wire:click="openStatusModal({{ $claim->id }}, 'approved')" class="btn btn-xs btn-success" title="Approve">
                        <i class="fas fa-check"></i>
                      </button>
                      <button wire:click="openStatusModal({{ $claim->id }}, 'partially_approved')" class="btn btn-xs btn-warning" title="Partially Approve">
                        <i class="fas fa-adjust"></i>
                      </button>
                      <button wire:click="openStatusModal({{ $claim->id }}, 'rejected')" class="btn btn-xs btn-danger" title="Reject">
                        <i class="fas fa-times"></i>
                      </button>
                    @endif
                    @if($canRecordPayments && in_array($claim->status, \App\Models\InsuranceClaim::PAYABLE_STATUSES))
                      <a href="{{ route('admin.insurance.payments', ['insurer' => $claim->insurer_id]) }}" class="btn btn-xs btn-dark" title="Record insurer payment">
                        <i class="fas fa-money-bill-wave"></i>
                      </a>
                    @endif
                    {{-- Delete (draft/rejected only) --}}
                    @if(in_array($claim->status, ['draft', 'rejected']))
                    <button wire:click="deleteClaim({{ $claim->id }})"
                            wire:confirm="Delete this claim? This cannot be undone."
                            class="btn btn-xs btn-outline-danger" title="Delete">
                      <i class="fas fa-trash"></i>
                    </button>
                    @endif
                  </td>
                </tr>
                @empty
                <tr>
                  <td colspan="10" class="text-center py-5 text-muted">
                    <i class="fas fa-shield-alt fa-3x mb-3 d-block text-secondary"></i>
                    <h5>No claims found</h5>
                    <button wire:click="openCreate" class="btn btn-primary btn-sm mt-2">
                      <i class="fas fa-plus mr-1"></i>Log a Claim
                    </button>
                  </td>
                </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>

        @if($claims->hasPages())
        <div class="card-footer">{{ $claims->links() }}</div>
        @endif
      </div>

    </div>
  </div>

  {{-- Create / Edit Modal --}}
  @if($showModal)
  <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background:rgba(0,0,0,.5);">
    <div class="modal-dialog insurance-claim-modal" role="document">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">
            <i class="fas fa-shield-alt mr-2"></i>
            {{ $isEditing ? 'Edit Claim' : 'Log Insurance Claim' }}
          </h5>
          <button wire:click="$set('showModal', false)" type="button" class="close text-white"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="row">
            {{-- Patient search --}}
            <div class="col-md-6">
              <div class="form-group">
                <label>Patient <span class="text-danger">*</span></label>
                <div class="position-relative">
                  <input wire:model.live.debounce.300ms="state.patient_search" type="text"
                         class="form-control @error('state.patient_id') is-invalid @enderror"
                         placeholder="Search name or Px#…" autocomplete="off">
                  @if(count($patientResults))
                  <div class="list-group position-absolute w-100 shadow" style="z-index:1050; top:100%;">
                    @foreach($patientResults as $p)
                    <button type="button" class="list-group-item list-group-item-action py-1 small"
                            wire:click="selectPatient({{ $p['id'] }}, '{{ addslashes($p['name']) }}')">
                      <strong>{{ $p['name'] }}</strong> <span class="text-muted">{{ $p['pxnumber'] }}</span>
                    </button>
                    @endforeach
                  </div>
                  @endif
                </div>
                @error('state.patient_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
              </div>
            </div>
            {{-- Insurer --}}
            <div class="col-md-6">
              <div class="form-group">
                <label>Insurer <span class="text-danger">*</span></label>
                <div class="position-relative">
                  <input wire:model.live.debounce.250ms="state.insurer_search" type="text"
                         class="form-control @error('state.insurer_id') is-invalid @enderror"
                         placeholder="Search insurer name, code, or scheme..." autocomplete="off">
                  @if(count($insurerResults))
                  <div class="list-group position-absolute w-100 shadow" style="z-index:1050; top:100%;">
                    @foreach($insurerResults as $ins)
                    <button type="button" class="list-group-item list-group-item-action py-1 small"
                            wire:click="selectInsurer({{ $ins['id'] }})">
                      <strong>{{ $ins['name'] }}</strong>
                      <span class="text-muted">
                        {{ $ins['code'] ? ' - '.$ins['code'] : '' }}
                        {{ $ins['scheme_type'] ? ' ('.$ins['scheme_type'].')' : '' }}
                      </span>
                    </button>
                    @endforeach
                  </div>
                  @endif
                </div>
                <select wire:model="state.insurer_id" class="d-none @error('state.insurer_id') is-invalid @enderror">
                  <option value="">— Select insurer —</option>
                  @foreach($insurers as $ins)
                    <option value="{{ $ins->id }}">{{ $ins->name }}</option>
                  @endforeach
                </select>
                @error('state.insurer_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
              </div>
            </div>
            {{-- Sale --}}
            <div class="col-md-12">
              <div class="form-group">
                <label>Linked Sale <span class="text-muted small">(optional — select a patient first)</span></label>
                <select wire:model.live="state.sale_id" class="form-control" {{ !$state['patient_id'] ? 'disabled' : '' }}>
                  <option value="">— No linked sale —</option>
                  @foreach($patientSales as $ps)
                    <option value="{{ $ps['id'] }}">{{ $ps['label'] }}</option>
                  @endforeach
                </select>
              </div>
            </div>
            {{-- Member details --}}
            <div class="col-md-4">
              <div class="form-group">
                <label>Member ID</label>
                <input wire:model="state.member_id" type="text" class="form-control" placeholder="e.g. NHIS-123456">
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-group">
                <label>Member Name</label>
                <input wire:model="state.member_name" type="text" class="form-control" placeholder="As on card">
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-group">
                <label>Policy Number</label>
                <input wire:model="state.policy_number" type="text" class="form-control" placeholder="Policy #">
              </div>
            </div>
            {{-- Claim amount --}}
            <div class="col-md-4">
              <div class="form-group">
                <label>Claim Amount <span class="text-danger">*</span></label>
                <div class="input-group">
                  <div class="input-group-prepend"><span class="input-group-text">{{ currency() }}</span></div>
                  <input wire:model="state.claim_amount" type="number" step="0.01" min="0"
                         class="form-control @error('state.claim_amount') is-invalid @enderror" placeholder="0.00">
                  @error('state.claim_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
              </div>
            </div>
            {{-- Notes --}}
            <div class="col-md-8">
              <div class="form-group">
                <label>Notes</label>
                <textarea wire:model="state.notes" class="form-control" rows="2" placeholder="Any notes…"></textarea>
              </div>
            </div>
          </div>

          {{-- Pre-Authorisation Section --}}
          <hr class="my-2">
          <h6 class="font-weight-bold text-muted mb-2">
            <i class="fas fa-id-card mr-1 text-info"></i>Pre-Authorisation
          </h6>
          <div class="row">
            <div class="col-md-4">
              <div class="form-group">
                <label>Pre-Auth Status</label>
                <select wire:model.live="state.pre_auth_status" class="form-control @error('state.pre_auth_status') is-invalid @enderror">
                  <option value="not_required">Not Required</option>
                  <option value="pending">Pending</option>
                  <option value="approved">Approved</option>
                  <option value="rejected">Rejected</option>
                </select>
                @error('state.pre_auth_status')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
            </div>
            @if(in_array($state['pre_auth_status'], ['approved', 'rejected', 'pending']))
            <div class="col-md-4">
              <div class="form-group">
                <label>Pre-Auth Code</label>
                <input wire:model="state.pre_auth_code" type="text" class="form-control"
                       placeholder="Authorisation code…">
              </div>
            </div>
            @if($state['pre_auth_status'] === 'approved')
            <div class="col-md-4">
              <div class="form-group">
                <label>Pre-Auth Amount</label>
                <div class="input-group">
                  <div class="input-group-prepend"><span class="input-group-text">{{ currency() }}</span></div>
                  <input wire:model="state.pre_auth_amount" type="number" step="0.01" min="0"
                         class="form-control @error('state.pre_auth_amount') is-invalid @enderror" placeholder="0.00">
                  @error('state.pre_auth_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-group">
                <label>Authorisation Date</label>
                <input wire:model="state.pre_auth_date" type="date" class="form-control">
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-group">
                <label>Expiry Date</label>
                <input wire:model="state.pre_auth_expiry_date" type="date" class="form-control">
              </div>
            </div>
            @endif
            <div class="col-md-{{ $state['pre_auth_status'] === 'approved' ? '4' : '8' }}">
              <div class="form-group">
                <label>Pre-Auth Notes</label>
                <textarea wire:model="state.pre_auth_notes" class="form-control" rows="2"
                          placeholder="Insurer notes, conditions…"></textarea>
              </div>
            </div>
            @endif
          </div>
        </div>
        <div class="modal-footer">
          <button wire:click="$set('showModal', false)" class="btn btn-secondary">Cancel</button>
          <button wire:click="save" class="btn btn-primary">
            <i class="fas fa-save mr-1"></i>{{ $isEditing ? 'Update Claim' : 'Log Claim' }}
          </button>
        </div>
      </div>
    </div>
  </div>
  @endif

  {{-- Status Update Modal --}}
  @if($showStatusModal)
  <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background:rgba(0,0,0,.5);">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header bg-dark text-white">
          <h5 class="modal-title">
            <i class="fas fa-exchange-alt mr-2"></i>
            Update Status:
            <span class="badge badge-light text-dark ml-1">{{ ucfirst(str_replace('_', ' ', $pendingStatus)) }}</span>
          </h5>
          <button wire:click="$set('showStatusModal', false)" type="button" class="close text-white"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          @if($pendingStatus === 'submitted')
            <div class="form-group">
              <label>Submission Date <span class="text-danger">*</span></label>
              <input wire:model="statusState.submission_date" type="date" class="form-control @error('statusState.submission_date') is-invalid @enderror">
              @error('statusState.submission_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
          @elseif(in_array($pendingStatus, ['approved', 'partially_approved']))
            <div class="form-group">
              <label>Approval Date <span class="text-danger">*</span></label>
              <input wire:model="statusState.approval_date" type="date" class="form-control @error('statusState.approval_date') is-invalid @enderror">
              @error('statusState.approval_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-group">
              <label>Approved Amount <span class="text-danger">*</span></label>
              <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">{{ currency() }}</span></div>
                <input wire:model="statusState.approved_amount" type="number" step="0.01" min="0"
                       class="form-control @error('statusState.approved_amount') is-invalid @enderror" placeholder="0.00">
                @error('statusState.approved_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              @if($pendingStatus === 'partially_approved')
                <small class="text-muted">Enter the partial amount approved by the insurer.</small>
              @endif
            </div>
            @if($statusClaim?->sale_id)
              <div class="alert alert-light border small mb-0">
                Claimed {{ currency() }} {{ number_format((float) $statusClaim->claim_amount, 2) }}.
                Anything not approved is {{ $statusClaim->insurer?->shortfall_action === 'write_off' ? 'written off by the clinic' : "added to the patient's balance" }}
                ({{ $statusClaim->insurer?->name }} setting).
              </div>
            @endif
          @elseif($pendingStatus === 'rejected')
            <div class="form-group">
              <label>Rejection Reason <span class="text-danger">*</span></label>
              <textarea wire:model="statusState.rejection_reason"
                        class="form-control @error('statusState.rejection_reason') is-invalid @enderror"
                        rows="3" placeholder="Reason given by the insurer…"></textarea>
              @error('statusState.rejection_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @if($statusClaim?->sale_id)
              <div class="alert alert-light border small mb-0">
                The claimed {{ currency() }} {{ number_format((float) $statusClaim->claim_amount, 2) }} will be
                {{ $statusClaim->insurer?->shortfall_action === 'write_off' ? 'written off by the clinic' : "added to the patient's balance" }}
                ({{ $statusClaim->insurer?->name }} setting).
              </div>
            @endif
          @elseif($pendingStatus === 'paid')
            <div class="form-group">
              <label>Payment Date <span class="text-danger">*</span></label>
              <input wire:model="statusState.payment_date" type="date" class="form-control @error('statusState.payment_date') is-invalid @enderror">
              @error('statusState.payment_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
          @endif
        </div>
        <div class="modal-footer">
          <button wire:click="$set('showStatusModal', false)" class="btn btn-secondary">Cancel</button>
          <button wire:click="applyStatus" class="btn btn-dark">
            <i class="fas fa-check mr-1"></i>Confirm
          </button>
        </div>
      </div>
    </div>
  </div>
  @endif
</div>

<style>
.insurance-claim-modal {
  max-width: 720px;
}
.insurance-claim-modal .modal-header,
.insurance-claim-modal .modal-footer {
  padding: .6rem .9rem;
}
.insurance-claim-modal .modal-title {
  font-size: 1rem;
}
.insurance-claim-modal .modal-body {
  max-height: calc(100vh - 150px);
  overflow-y: auto;
  padding: .8rem .9rem;
}
.insurance-claim-modal .form-group {
  margin-bottom: .6rem;
}
.insurance-claim-modal label {
  font-size: .86rem;
  margin-bottom: .25rem;
}
.insurance-claim-modal .form-control,
.insurance-claim-modal .custom-select,
.insurance-claim-modal .input-group-text {
  height: calc(1.5em + .5rem + 2px);
  padding: .25rem .55rem;
  font-size: .9rem;
}
.insurance-claim-modal textarea.form-control {
  height: auto;
  min-height: 44px;
}
.insurance-claim-modal .btn {
  padding: .28rem .65rem;
  font-size: .86rem;
}
@media (max-width: 767.98px) {
  .insurance-claim-modal {
    max-width: calc(100% - 1rem);
    margin: .5rem auto;
  }
}
</style>
</div>
