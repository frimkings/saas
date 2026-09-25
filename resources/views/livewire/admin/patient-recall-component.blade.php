<div>
  {{-- Page Header --}}
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0"><i class="fas fa-user-clock mr-2 text-success"></i>Patient Recall</h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item active">Patient Recall</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="content">
    <div class="container-fluid">

      {{-- Config notice --}}
      @if(empty($s->recall_sms_enabled))
      <div class="alert alert-warning shadow-sm">
        <i class="fas fa-exclamation-triangle mr-2"></i>
        Automated recall SMS is <strong>disabled</strong>.
        Enable it in <a href="{{ route('admin.settings') }}">Settings → SMS Templates</a> to run the daily scheduler.
        You can still send manual recalls from this page.
      </div>
      @endif

      {{-- Stats Row --}}
      <div class="row mb-3">
        <div class="col-4">
          <div class="info-box shadow-sm mb-0 {{ $activeTab === 'due' ? 'bg-warning' : '' }}">
            <span class="info-box-icon {{ $activeTab === 'due' ? 'bg-warning' : 'bg-light' }}">
              <i class="fas fa-user-clock {{ $activeTab !== 'due' ? 'text-warning' : '' }}"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text">Due for Recall</span>
              <span class="info-box-number">{{ $dueCount }}</span>
              <span class="info-box-text small">inactive &gt; {{ $months }} months</span>
            </div>
          </div>
        </div>
        <div class="col-4">
          <div class="info-box shadow-sm mb-0 {{ $activeTab === 'sent' ? 'bg-primary text-white' : '' }}">
            <span class="info-box-icon {{ $activeTab === 'sent' ? 'bg-primary' : 'bg-light' }}">
              <i class="fas fa-paper-plane {{ $activeTab !== 'sent' ? 'text-primary' : '' }}"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text">Recall Sent</span>
              <span class="info-box-number">{{ $sentCount }}</span>
              <span class="info-box-text small">this cycle</span>
            </div>
          </div>
        </div>
        <div class="col-4">
          <div class="info-box shadow-sm mb-0 {{ $activeTab === 'returned' ? 'bg-success text-white' : '' }}">
            <span class="info-box-icon {{ $activeTab === 'returned' ? 'bg-success' : 'bg-light' }}">
              <i class="fas fa-user-check {{ $activeTab !== 'returned' ? 'text-success' : '' }}"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text">Returned</span>
              <span class="info-box-number">{{ $returnedCount }}</span>
              <span class="info-box-text small">came back after recall</span>
            </div>
          </div>
        </div>
      </div>

      <div class="card card-outline card-success shadow-sm">

        {{-- Tabs --}}
        <div class="card-header p-0 border-bottom-0">
          <ul class="nav nav-tabs">
            <li class="nav-item">
              <a class="nav-link {{ $activeTab === 'due' ? 'active' : '' }}"
                 wire:click="$set('activeTab', 'due')" href="#">
                <i class="fas fa-user-clock mr-1"></i>Due for Recall
                @if($dueCount > 0)
                  <span class="badge badge-warning ml-1">{{ $dueCount }}</span>
                @endif
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link {{ $activeTab === 'sent' ? 'active' : '' }}"
                 wire:click="$set('activeTab', 'sent')" href="#">
                <i class="fas fa-paper-plane mr-1"></i>Recall Sent
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link {{ $activeTab === 'returned' ? 'active' : '' }}"
                 wire:click="$set('activeTab', 'returned')" href="#">
                <i class="fas fa-user-check mr-1"></i>Returned
              </a>
            </li>
          </ul>
        </div>

        {{-- Filters --}}
        <div class="card-header flex-wrap" style="gap:8px; border-top: 1px solid #dee2e6;">
          <div class="d-flex align-items-center flex-wrap w-100" style="gap:8px;">
            <div class="input-group" style="max-width:260px;">
              <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
              <input wire:model.live.debounce.300ms="search" type="text" class="form-control" placeholder="Search name or Px#…">
            </div>
            @if($activeTab === 'due' && $dueCount > 0)
            <div class="ml-auto">
              <button wire:click="sendBulkRecall"
                      onclick="return confirm('Send recall SMS to all {{ $dueCount }} due patient(s)? This will use your configured SMS/WhatsApp channel.')"
                      class="btn btn-warning btn-sm" wire:loading.attr="disabled">
                <span wire:loading wire:target="sendBulkRecall"><i class="fas fa-spinner fa-spin mr-1"></i></span>
                <span wire:loading.remove wire:target="sendBulkRecall"><i class="fas fa-bullhorn mr-1"></i></span>
                Send Bulk Recall ({{ $dueCount }})
              </button>
            </div>
            @endif
          </div>
        </div>

        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
              <thead class="thead-light">
                <tr>
                  <th>Patient</th>
                  <th>Contact</th>
                  <th>Last Visit</th>
                  @if($activeTab === 'sent' || $activeTab === 'returned')
                    <th>Recall Sent</th>
                  @endif
                  <th class="text-center">Actions</th>
                </tr>
              </thead>
              <tbody wire:loading.class="opacity-50">
                @forelse($patientsPaginated as $patient)
                <tr>
                  <td>
                    <div class="font-weight-bold">{{ $patient->name }}</div>
                    <div class="text-muted small">{{ $patient->pxnumber }}</div>
                  </td>
                  <td class="small">{{ $patient->contact }}</td>
                  <td class="small text-muted">
                    @if($patient->consultations_max_created_at)
                      {{ \Carbon\Carbon::parse($patient->consultations_max_created_at)->format('d M Y') }}
                      <br>
                      <span class="text-{{ \Carbon\Carbon::parse($patient->consultations_max_created_at)->diffInMonths(now()) > 18 ? 'danger' : 'warning' }}">
                        {{ \Carbon\Carbon::parse($patient->consultations_max_created_at)->diffForHumans() }}
                      </span>
                    @else
                      <span class="text-muted">—</span>
                    @endif
                  </td>
                  @if($activeTab === 'sent' || $activeTab === 'returned')
                  <td class="small text-muted">
                    {{ $patient->recall_sms_sent_at ? $patient->recall_sms_sent_at->format('d M Y') : '—' }}
                  </td>
                  @endif
                  <td class="text-center" style="white-space:nowrap;">
                    @if($activeTab === 'due')
                      <button wire:click="sendRecall({{ $patient->id }})"
                              wire:loading.attr="disabled"
                              wire:target="sendRecall({{ $patient->id }})"
                              class="btn btn-xs btn-success" title="Send recall SMS">
                        <span wire:loading wire:target="sendRecall({{ $patient->id }})"><i class="fas fa-spinner fa-spin"></i></span>
                        <span wire:loading.remove wire:target="sendRecall({{ $patient->id }})"><i class="fas fa-paper-plane"></i></span>
                        Send
                      </button>
                    @elseif($activeTab === 'sent')
                      <button wire:click="sendRecall({{ $patient->id }})"
                              onclick="return confirm('Re-send recall to {{ addslashes($patient->name) }}?')"
                              class="btn btn-xs btn-outline-primary" title="Re-send">
                        <i class="fas fa-redo"></i> Re-send
                      </button>
                      <button wire:click="resetRecall({{ $patient->id }})"
                              onclick="return confirm('Reset recall cycle for {{ addslashes($patient->name) }}? They will appear as due again.')"
                              class="btn btn-xs btn-outline-secondary" title="Reset cycle">
                        <i class="fas fa-undo"></i>
                      </button>
                    @elseif($activeTab === 'returned')
                      <span class="badge badge-success"><i class="fas fa-check mr-1"></i>Returned</span>
                      <button wire:click="resetRecall({{ $patient->id }})"
                              onclick="return confirm('Reset recall cycle for {{ addslashes($patient->name) }}?')"
                              class="btn btn-xs btn-outline-secondary ml-1" title="Reset cycle">
                        <i class="fas fa-undo"></i>
                      </button>
                    @endif
                  </td>
                </tr>
                @empty
                <tr>
                  <td colspan="5" class="text-center py-5 text-muted">
                    @if($activeTab === 'due')
                      <i class="fas fa-check-circle fa-3x mb-3 d-block text-success"></i>
                      <h5>No patients due for recall</h5>
                      <p class="text-muted">All patients have visited within the last {{ $months }} months.</p>
                    @elseif($activeTab === 'sent')
                      <i class="fas fa-paper-plane fa-3x mb-3 d-block text-secondary"></i>
                      <h5>No recalls sent this cycle</h5>
                    @else
                      <i class="fas fa-user-check fa-3x mb-3 d-block text-secondary"></i>
                      <h5>No patients have returned after recall yet</h5>
                    @endif
                  </td>
                </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>

        @if($patientsPaginated->hasPages())
        <div class="card-footer">{{ $patientsPaginated->links() }}</div>
        @endif
      </div>

    </div>
  </div>
</div>
