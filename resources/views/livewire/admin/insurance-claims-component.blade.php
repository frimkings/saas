<div class="clinic-ui ui-page" data-livewire-root>
<div>
  {{-- Page Header --}}
  <div class="content-header">
    <div class="w-full">
      <div class="flex flex-wrap -mx-2 mb-2 items-center">
        <div class="w-full sm:w-6/12 px-2">
          <h1 class="m-0"><i class="fas fa-shield-alt mr-2 text-teal-700"></i>Insurance Claims</h1>
        </div>
        <div class="w-full sm:w-6/12 px-2">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item active">Insurance Claims</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="content">
    <div class="w-full">

      {{-- Stats Row --}}
      <div class="flex flex-wrap -mx-2 mb-4">
        <div class="w-6/12 md:w-3/12 px-2">
          <div class="info-box shadow-sm mb-0">
            <span class="info-box-icon bg-slate-500 text-white"><i class="fas fa-file-alt"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Pending Submission</span>
              <span class="info-box-number">{{ $draftCount }}</span>
            </div>
          </div>
        </div>
        <div class="w-6/12 md:w-3/12 px-2">
          <div class="info-box shadow-sm mb-0">
            <span class="info-box-icon bg-teal-700 text-white"><i class="fas fa-paper-plane"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Awaiting Approval</span>
              <span class="info-box-number">{{ $submittedCount }}</span>
            </div>
          </div>
        </div>
        <div class="w-6/12 md:w-2/12 px-2">
          <div class="info-box shadow-sm mb-0">
            <span class="info-box-icon bg-green-600 text-white"><i class="fas fa-check-circle"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Total Approved</span>
              <span class="info-box-number">{{ currency() }} {{ number_format($approvedSum, 2) }}</span>
            </div>
          </div>
        </div>
        <div class="w-6/12 md:w-2/12 px-2">
          <div class="info-box shadow-sm mb-0">
            <span class="info-box-icon bg-amber-400"><i class="fas fa-clock"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Outstanding</span>
              <span class="info-box-number">{{ currency() }} {{ number_format($outstandingSum, 2) }}</span>
            </div>
          </div>
        </div>
        <div class="w-6/12 md:w-2/12 px-2">
          <div class="info-box shadow-sm mb-0" style="cursor:pointer;"
               wire:click="$set('preAuthFilter', {{ $pendingPreAuth > 0 ? '\'pending\'' : '\'\'' }})">
            <span class="info-box-icon {{ $pendingPreAuth > 0 ? 'bg-orange' : 'bg-slate-50' }}">
              <i class="fas fa-id-card" style="{{ $pendingPreAuth > 0 ? '' : 'color:#aaa;' }}"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text">Pre-Auth Pending</span>
              <span class="info-box-number {{ $pendingPreAuth > 0 ? 'text-orange' : 'text-slate-500' }}">{{ $pendingPreAuth }}</span>
            </div>
          </div>
        </div>
      </div>

      <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-primary shadow-sm">

        {{-- Tabs --}}
        <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2 p-0 border-b-0">
          <ul class="flex flex-wrap border-b border-slate-200" style="flex-wrap:nowrap; overflow-x:auto;">
            @foreach(['all' => 'All', 'draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'partially_approved' => 'Part. Approved', 'rejected' => 'Rejected', 'paid' => 'Paid'] as $tab => $label)
            <li class="">
              <a class="block px-3 py-2 {{ $activeTab === $tab ? 'active' : '' }}"
                 wire:click="$set('activeTab', '{{ $tab }}')" href="#" style="white-space:nowrap;">
                {{ $label }}
              </a>
            </li>
            @endforeach
          </ul>
        </div>

        {{-- Filters --}}
        <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2 flex-wrap" style="gap:8px; border-top: 1px solid #dee2e6;">
          <div class="flex items-center flex-wrap w-full" style="gap:8px;">
            <div class="flex items-stretch" style="max-width:240px;">
              <div class="flex"><span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600"><i class="fas fa-search"></i></span></div>
              <input wire:model.live.debounce.300ms="search" type="text" class="form-control ui-input" placeholder="Patient name or Px#…">
            </div>
            <select wire:model.live="insurerFilter" class="form-control ui-input" style="max-width:200px;">
              <option value="">All Insurers</option>
              @foreach($insurers as $ins)
                <option value="{{ $ins->id }}">{{ $ins->name }}</option>
              @endforeach
            </select>
            <select wire:model.live="preAuthFilter" class="form-control ui-input" style="max-width:170px;">
              <option value="">All Pre-Auth</option>
              <option value="not_required">Not Required</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </select>
            <x-date-range from="fromDate" to="toDate" presets="finance" clearable />
            <div class="ml-auto flex" style="gap:6px;">
              <button wire:click="exportCsv" class="btn ui-button ui-button-secondary ui-button-sm">
                <i class="fas fa-file-csv mr-1"></i>Export CSV
              </button>
              <button wire:click="openCreate" class="btn ui-button ui-button-primary ui-button-sm">
                <i class="fas fa-plus mr-1"></i>Log Claim
              </button>
            </div>
          </div>
        </div>

        <div class="card-body p-0">
          <div class="ui-table-wrap">
            <table class="table ui-table ui-table-sm mb-0">
              <thead class="">
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
                  <td class="text-slate-500 text-sm">{{ $claim->id }}</td>
                  <td>
                    <div class="font-semibold">{{ $claim->patient->name ?? '—' }}</div>
                    <div class="text-slate-500 text-sm">{{ $claim->patient->pxnumber ?? '' }}</div>
                  </td>
                  <td>
                    <div>{{ $claim->insurer->name ?? '—' }}</div>
                    @if($claim->insurer)
                      <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $claim->insurer->schemeBadgeClass() }} badge-sm">{{ $claim->insurer->scheme_type }}</span>
                    @endif
                  </td>
                  <td class="text-sm text-slate-500">{{ $claim->member_id ?: '—' }}</td>
                  <td class="text-right font-semibold">{{ currency() }} {{ number_format($claim->claim_amount, 2) }}</td>
                  <td class="text-right">
                    @if($claim->approved_amount !== null)
                      {{ currency() }} {{ number_format($claim->approved_amount, 2) }}
                    @else
                      <span class="text-slate-500">—</span>
                    @endif
                  </td>
                  <td class="text-center">
                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $claim->preAuthBadgeClass() }}" title="{{ $claim->pre_auth_code ? 'Code: '.$claim->pre_auth_code : '' }}">
                      {{ $claim->preAuthLabel() }}
                    </span>
                    @if($claim->pre_auth_expired)
                      <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800 block mt-1" style="font-size:.65rem;">Expired</span>
                    @endif
                  </td>
                  <td class="text-center">
                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $claim->statusBadgeClass() }}">{{ $claim->statusLabel() }}</span>
                  </td>
                  <td class="text-center text-sm text-slate-500">{{ $claim->created_at->format('d M Y') }}</td>
                  <td class="text-center" style="white-space:nowrap;">
                    {{-- Edit (only draft) --}}
                    @if($claim->status === 'draft')
                    <button wire:click="openEdit({{ $claim->id }})" class="btn ui-button ui-button-sm ui-button-secondary" title="Edit">
                      <i class="fas fa-edit"></i>
                    </button>
                    @endif
                    {{-- Status transitions --}}
                    @if($claim->status === 'draft')
                      <button wire:click="openStatusModal({{ $claim->id }}, 'submitted')" class="btn ui-button ui-button-sm ui-button-primary" title="Submit">
                        <i class="fas fa-paper-plane"></i>
                      </button>
                    @elseif($claim->status === 'submitted')
                      <button wire:click="openStatusModal({{ $claim->id }}, 'approved')" class="btn ui-button ui-button-sm ui-button-primary" title="Approve">
                        <i class="fas fa-check"></i>
                      </button>
                      <button wire:click="openStatusModal({{ $claim->id }}, 'partially_approved')" class="btn ui-button ui-button-sm ui-button-secondary" title="Partially Approve">
                        <i class="fas fa-adjust"></i>
                      </button>
                      <button wire:click="openStatusModal({{ $claim->id }}, 'rejected')" class="btn ui-button ui-button-sm ui-button-danger" title="Reject">
                        <i class="fas fa-times"></i>
                      </button>
                    @endif
                    @if($canRecordPayments && in_array($claim->status, \App\Models\InsuranceClaim::PAYABLE_STATUSES))
                      <a href="{{ route('admin.insurance.payments', ['insurer' => $claim->insurer_id]) }}" class="btn ui-button ui-button-sm ui-button-secondary" title="Record insurer payment">
                        <i class="fas fa-money-bill-wave"></i>
                      </a>
                    @endif
                    {{-- Delete (draft/rejected only) --}}
                    @if(in_array($claim->status, ['draft', 'rejected']))
                    <button wire:click="deleteClaim({{ $claim->id }})"
                            wire:confirm="Delete this claim? This cannot be undone."
                            class="btn ui-button ui-button-sm ui-button-danger" title="Delete">
                      <i class="fas fa-trash"></i>
                    </button>
                    @endif
                  </td>
                </tr>
                @empty
                <tr>
                  <td colspan="10" class="text-center py-12 text-slate-500">
                    <i class="fas fa-shield-alt fa-3x mb-4 block text-slate-500"></i>
                    <h5>No claims found</h5>
                    <button wire:click="openCreate" class="btn ui-button ui-button-primary ui-button-sm mt-2">
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
        <div class="border-t border-slate-200 bg-slate-50 px-4 py-2">{{ $claims->links() }}</div>
        @endif
      </div>

    </div>
  </div>

  {{-- Create / Edit Modal --}}
  @if($showModal)
  <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show block" tabindex="-1" role="dialog" style="background:rgba(0,0,0,.5);">
    <div class="mx-auto my-8 w-full max-w-lg insurance-claim-modal" role="document">
      <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-teal-700 text-white">
          <h5 class="text-base font-semibold">
            <i class="fas fa-shield-alt mr-2"></i>
            {{ $isEditing ? 'Edit Claim' : 'Log Insurance Claim' }}
          </h5>
          <button wire:click="$set('showModal', false)" type="button" class="text-xl leading-none hover:text-slate-800 text-white"><span>&times;</span></button>
        </div>
        <div class="p-4">
          <div class="flex flex-wrap -mx-2">
            {{-- Patient search --}}
            <div class="w-full md:w-6/12 px-2">
              <div class="mb-4">
                <label>Patient <span class="text-red-700">*</span></label>
                <div class="relative">
                  <input wire:model.live.debounce.300ms="state.patient_search" type="text"
                         class="form-control ui-input @error('state.patient_id') is-invalid @enderror"
                         placeholder="Search name or Px#…" autocomplete="off">
                  @if(count($patientResults))
                  <div class="overflow-hidden rounded-md border border-slate-200 bg-white absolute w-full shadow" style="z-index:1050; top:100%;">
                    @foreach($patientResults as $p)
                    <button type="button" class="list-group-item block w-full border-b border-slate-100 px-3 text-left hover:bg-slate-50 py-1 text-sm"
                            wire:click="selectPatient({{ $p['id'] }}, '{{ addslashes($p['name']) }}')">
                      <strong>{{ $p['name'] }}</strong> <span class="text-slate-500">{{ $p['pxnumber'] }}</span>
                    </button>
                    @endforeach
                  </div>
                  @endif
                </div>
                @error('state.patient_id')<div class="text-red-700 text-sm mt-1">{{ $message }}</div>@enderror
              </div>
            </div>
            {{-- Insurer --}}
            <div class="w-full md:w-6/12 px-2">
              <div class="mb-4">
                <label>Insurer <span class="text-red-700">*</span></label>
                <div class="relative">
                  <input wire:model.live.debounce.250ms="state.insurer_search" type="text"
                         class="form-control ui-input @error('state.insurer_id') is-invalid @enderror"
                         placeholder="Search insurer name, code, or scheme..." autocomplete="off">
                  @if(count($insurerResults))
                  <div class="overflow-hidden rounded-md border border-slate-200 bg-white absolute w-full shadow" style="z-index:1050; top:100%;">
                    @foreach($insurerResults as $ins)
                    <button type="button" class="list-group-item block w-full border-b border-slate-100 px-3 text-left hover:bg-slate-50 py-1 text-sm"
                            wire:click="selectInsurer({{ $ins['id'] }})">
                      <strong>{{ $ins['name'] }}</strong>
                      <span class="text-slate-500">
                        {{ $ins['code'] ? ' - '.$ins['code'] : '' }}
                        {{ $ins['scheme_type'] ? ' ('.$ins['scheme_type'].')' : '' }}
                      </span>
                    </button>
                    @endforeach
                  </div>
                  @endif
                </div>
                <select wire:model="state.insurer_id" class="hidden @error('state.insurer_id') is-invalid @enderror">
                  <option value="">— Select insurer —</option>
                  @foreach($insurers as $ins)
                    <option value="{{ $ins->id }}">{{ $ins->name }}</option>
                  @endforeach
                </select>
                @error('state.insurer_id')<div class="text-red-700 text-sm mt-1">{{ $message }}</div>@enderror
              </div>
            </div>
            {{-- Sale --}}
            <div class="w-full md:w-full px-2">
              <div class="mb-4">
                <label>Linked Sale <span class="text-slate-500 text-sm">(optional — select a patient first)</span></label>
                <select wire:model.live="state.sale_id" class="form-control ui-input" {{ !$state['patient_id'] ? 'disabled' : '' }}>
                  <option value="">— No linked sale —</option>
                  @foreach($patientSales as $ps)
                    <option value="{{ $ps['id'] }}">{{ $ps['label'] }}</option>
                  @endforeach
                </select>
              </div>
            </div>
            {{-- Member details --}}
            <div class="w-full md:w-4/12 px-2">
              <div class="mb-4">
                <label>Member ID</label>
                <input wire:model="state.member_id" type="text" class="form-control ui-input" placeholder="e.g. NHIS-123456">
              </div>
            </div>
            <div class="w-full md:w-4/12 px-2">
              <div class="mb-4">
                <label>Member Name</label>
                <input wire:model="state.member_name" type="text" class="form-control ui-input" placeholder="As on card">
              </div>
            </div>
            <div class="w-full md:w-4/12 px-2">
              <div class="mb-4">
                <label>Policy Number</label>
                <input wire:model="state.policy_number" type="text" class="form-control ui-input" placeholder="Policy #">
              </div>
            </div>
            {{-- Claim amount --}}
            <div class="w-full md:w-4/12 px-2">
              <div class="mb-4">
                <label>Claim Amount <span class="text-red-700">*</span></label>
                <div class="flex items-stretch">
                  <div class="flex"><span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600">{{ currency() }}</span></div>
                  <input wire:model="state.claim_amount" type="number" step="0.01" min="0"
                         class="form-control ui-input @error('state.claim_amount') is-invalid @enderror" placeholder="0.00">
                  @error('state.claim_amount')<div class="ui-error">{{ $message }}</div>@enderror
                </div>
              </div>
            </div>
            {{-- Notes --}}
            <div class="w-full md:w-8/12 px-2">
              <div class="mb-4">
                <label>Notes</label>
                <textarea wire:model="state.notes" class="form-control ui-input" rows="2" placeholder="Any notes…"></textarea>
              </div>
            </div>
          </div>

          {{-- Pre-Authorisation Section --}}
          <hr class="my-2">
          <h6 class="font-semibold text-slate-500 mb-2">
            <i class="fas fa-id-card mr-1 text-sky-700"></i>Pre-Authorisation
          </h6>
          <div class="flex flex-wrap -mx-2">
            <div class="w-full md:w-4/12 px-2">
              <div class="mb-4">
                <label>Pre-Auth Status</label>
                <select wire:model.live="state.pre_auth_status" class="form-control ui-input @error('state.pre_auth_status') is-invalid @enderror">
                  <option value="not_required">Not Required</option>
                  <option value="pending">Pending</option>
                  <option value="approved">Approved</option>
                  <option value="rejected">Rejected</option>
                </select>
                @error('state.pre_auth_status')<div class="ui-error">{{ $message }}</div>@enderror
              </div>
            </div>
            @if(in_array($state['pre_auth_status'], ['approved', 'rejected', 'pending']))
            <div class="w-full md:w-4/12 px-2">
              <div class="mb-4">
                <label>Pre-Auth Code</label>
                <input wire:model="state.pre_auth_code" type="text" class="form-control ui-input"
                       placeholder="Authorisation code…">
              </div>
            </div>
            @if($state['pre_auth_status'] === 'approved')
            <div class="w-full md:w-4/12 px-2">
              <div class="mb-4">
                <label>Pre-Auth Amount</label>
                <div class="flex items-stretch">
                  <div class="flex"><span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600">{{ currency() }}</span></div>
                  <input wire:model="state.pre_auth_amount" type="number" step="0.01" min="0"
                         class="form-control ui-input @error('state.pre_auth_amount') is-invalid @enderror" placeholder="0.00">
                  @error('state.pre_auth_amount')<div class="ui-error">{{ $message }}</div>@enderror
                </div>
              </div>
            </div>
            <div class="w-full md:w-4/12 px-2">
              <div class="mb-4">
                <label>Authorisation Date</label>
                <input wire:model="state.pre_auth_date" type="date" class="form-control ui-input">
              </div>
            </div>
            <div class="w-full md:w-4/12 px-2">
              <div class="mb-4">
                <label>Expiry Date</label>
                <input wire:model="state.pre_auth_expiry_date" type="date" class="form-control ui-input">
              </div>
            </div>
            @endif
            <div class="col-md-{{ $state['pre_auth_status'] === 'approved' ? '4' : '8' }}">
              <div class="mb-4">
                <label>Pre-Auth Notes</label>
                <textarea wire:model="state.pre_auth_notes" class="form-control ui-input" rows="2"
                          placeholder="Insurer notes, conditions…"></textarea>
              </div>
            </div>
            @endif
          </div>
        </div>
        <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
          <button wire:click="$set('showModal', false)" class="btn ui-button ui-button-secondary">Cancel</button>
          <button wire:click="save" class="btn ui-button ui-button-primary">
            <i class="fas fa-save mr-1"></i>{{ $isEditing ? 'Update Claim' : 'Log Claim' }}
          </button>
        </div>
      </div>
    </div>
  </div>
  @endif

  {{-- Status Update Modal --}}
  @if($showStatusModal)
  <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show block" tabindex="-1" role="dialog" style="background:rgba(0,0,0,.5);">
    <div class="mx-auto my-8 w-full max-w-lg" role="document">
      <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-slate-800 text-white">
          <h5 class="text-base font-semibold">
            <i class="fas fa-exchange-alt mr-2"></i>
            Update Status:
            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-900 ml-1">{{ ucfirst(str_replace('_', ' ', $pendingStatus)) }}</span>
          </h5>
          <button wire:click="$set('showStatusModal', false)" type="button" class="text-xl leading-none hover:text-slate-800 text-white"><span>&times;</span></button>
        </div>
        <div class="p-4">
          @if($pendingStatus === 'submitted')
            <div class="mb-4">
              <label>Submission Date <span class="text-red-700">*</span></label>
              <input wire:model="statusState.submission_date" type="date" class="form-control ui-input @error('statusState.submission_date') is-invalid @enderror">
              @error('statusState.submission_date')<div class="ui-error">{{ $message }}</div>@enderror
            </div>
          @elseif(in_array($pendingStatus, ['approved', 'partially_approved']))
            <div class="mb-4">
              <label>Approval Date <span class="text-red-700">*</span></label>
              <input wire:model="statusState.approval_date" type="date" class="form-control ui-input @error('statusState.approval_date') is-invalid @enderror">
              @error('statusState.approval_date')<div class="ui-error">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
              <label>Approved Amount <span class="text-red-700">*</span></label>
              <div class="flex items-stretch">
                <div class="flex"><span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600">{{ currency() }}</span></div>
                <input wire:model="statusState.approved_amount" type="number" step="0.01" min="0"
                       class="form-control ui-input @error('statusState.approved_amount') is-invalid @enderror" placeholder="0.00">
                @error('statusState.approved_amount')<div class="ui-error">{{ $message }}</div>@enderror
              </div>
              @if($pendingStatus === 'partially_approved')
                <small class="text-slate-500">Enter the partial amount approved by the insurer.</small>
              @endif
            </div>
            @if($statusClaim?->sale_id)
              <div class="rounded-lg border px-3 py-2 text-sm bg-white text-slate-700 border-slate-200 mb-0">
                Claimed {{ currency() }} {{ number_format((float) $statusClaim->claim_amount, 2) }}.
                Anything not approved is {{ $statusClaim->insurer?->shortfall_action === 'write_off' ? 'written off by the clinic' : "added to the patient's balance" }}
                ({{ $statusClaim->insurer?->name }} setting).
              </div>
            @endif
          @elseif($pendingStatus === 'rejected')
            <div class="mb-4">
              <label>Rejection Reason <span class="text-red-700">*</span></label>
              <textarea wire:model="statusState.rejection_reason"
                        class="form-control ui-input @error('statusState.rejection_reason') is-invalid @enderror"
                        rows="3" placeholder="Reason given by the insurer…"></textarea>
              @error('statusState.rejection_reason')<div class="ui-error">{{ $message }}</div>@enderror
            </div>
            @if($statusClaim?->sale_id)
              <div class="rounded-lg border px-3 py-2 text-sm bg-white text-slate-700 border-slate-200 mb-0">
                The claimed {{ currency() }} {{ number_format((float) $statusClaim->claim_amount, 2) }} will be
                {{ $statusClaim->insurer?->shortfall_action === 'write_off' ? 'written off by the clinic' : "added to the patient's balance" }}
                ({{ $statusClaim->insurer?->name }} setting).
              </div>
            @endif
          @elseif($pendingStatus === 'paid')
            <div class="mb-4">
              <label>Payment Date <span class="text-red-700">*</span></label>
              <input wire:model="statusState.payment_date" type="date" class="form-control ui-input @error('statusState.payment_date') is-invalid @enderror">
              @error('statusState.payment_date')<div class="ui-error">{{ $message }}</div>@enderror
            </div>
          @endif
        </div>
        <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
          <button wire:click="$set('showStatusModal', false)" class="btn ui-button ui-button-secondary">Cancel</button>
          <button wire:click="applyStatus" class="btn ui-button ui-button-secondary">
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
