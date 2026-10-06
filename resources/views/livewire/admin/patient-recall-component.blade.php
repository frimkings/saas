<div class="clinic-ui ui-page">
  {{-- Page Header --}}
  <div class="content-header">
    <div class="w-full">
      <div class="flex flex-wrap -mx-2 mb-2 items-center">
        <div class="w-full sm:w-6/12 px-2">
          <h1 class="m-0"><i class="fas fa-user-clock mr-2 text-green-700"></i>Patient Recall</h1>
        </div>
        <div class="w-full sm:w-6/12 px-2">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item active">Patient Recall</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="content">
    <div class="w-full">

      {{-- Config notice --}}
      @if(empty($s->recall_sms_enabled))
      <div class="rounded-lg border px-3 py-2 text-sm border-amber-200 bg-amber-50 text-amber-900 shadow-sm">
        <i class="fas fa-exclamation-triangle mr-2"></i>
        Automated recall SMS is <strong>disabled</strong>.
        Enable it in <a href="{{ route('admin.messages') }}">Communications → Messages</a> to run the daily scheduler.
        You can still send manual recalls from this page.
      </div>
      @endif

      {{-- Stats Row --}}
      <div class="flex flex-wrap -mx-2 mb-4">
        <div class="w-4/12 px-2">
          <div class="info-box shadow-sm mb-0 {{ $activeTab === 'due' ? 'bg-amber-400' : '' }}">
            <span class="info-box-icon {{ $activeTab === 'due' ? 'bg-amber-400' : 'bg-slate-50' }}">
              <i class="fas fa-user-clock {{ $activeTab !== 'due' ? 'text-amber-600' : '' }}"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text">Due for Recall</span>
              <span class="info-box-number">{{ $dueCount }}</span>
              <span class="info-box-text text-sm">inactive &gt; {{ $months }} months</span>
            </div>
          </div>
        </div>
        <div class="w-4/12 px-2">
          <div class="info-box shadow-sm mb-0 {{ $activeTab === 'sent' ? 'bg-teal-700 text-white text-white' : '' }}">
            <span class="info-box-icon {{ $activeTab === 'sent' ? 'bg-teal-700 text-white' : 'bg-slate-50' }}">
              <i class="fas fa-paper-plane {{ $activeTab !== 'sent' ? 'text-teal-700' : '' }}"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text">Recall Sent</span>
              <span class="info-box-number">{{ $sentCount }}</span>
              <span class="info-box-text text-sm">this cycle</span>
            </div>
          </div>
        </div>
        <div class="w-4/12 px-2">
          <div class="info-box shadow-sm mb-0 {{ $activeTab === 'returned' ? 'bg-green-600 text-white text-white' : '' }}">
            <span class="info-box-icon {{ $activeTab === 'returned' ? 'bg-green-600 text-white' : 'bg-slate-50' }}">
              <i class="fas fa-user-check {{ $activeTab !== 'returned' ? 'text-green-700' : '' }}"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text">Returned</span>
              <span class="info-box-number">{{ $returnedCount }}</span>
              <span class="info-box-text text-sm">came back after recall</span>
            </div>
          </div>
        </div>
      </div>

      <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-success shadow-sm">

        {{-- Tabs --}}
        <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2 p-0 border-b-0">
          <ul class="flex flex-wrap border-b border-slate-200">
            <li class="">
              <a class="block px-3 py-2 {{ $activeTab === 'due' ? 'active' : '' }}"
                 wire:click="$set('activeTab', 'due')" href="#">
                <i class="fas fa-user-clock mr-1"></i>Due for Recall
                @if($dueCount > 0)
                  <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 ml-1">{{ $dueCount }}</span>
                @endif
              </a>
            </li>
            <li class="">
              <a class="block px-3 py-2 {{ $activeTab === 'sent' ? 'active' : '' }}"
                 wire:click="$set('activeTab', 'sent')" href="#">
                <i class="fas fa-paper-plane mr-1"></i>Recall Sent
              </a>
            </li>
            <li class="">
              <a class="block px-3 py-2 {{ $activeTab === 'returned' ? 'active' : '' }}"
                 wire:click="$set('activeTab', 'returned')" href="#">
                <i class="fas fa-user-check mr-1"></i>Returned
              </a>
            </li>
          </ul>
        </div>

        {{-- Filters --}}
        <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2 flex-wrap" style="gap:8px; border-top: 1px solid #dee2e6;">
          <div class="flex items-center flex-wrap w-full" style="gap:8px;">
            <div class="flex items-stretch" style="max-width:260px;">
              <div class="flex"><span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600"><i class="fas fa-search"></i></span></div>
              <input wire:model.live.debounce.300ms="search" type="text" class="form-control ui-input" placeholder="Search name or Px#…">
            </div>
            @if($activeTab === 'due' && $dueCount > 0)
            <div class="ml-auto">
              <button wire:click="sendBulkRecall"
                      wire:confirm="Send recall SMS to all {{ $dueCount }} due patient(s)? This will use your configured SMS/WhatsApp channel."
                      class="btn ui-button ui-button-secondary ui-button-sm" wire:loading.attr="disabled">
                <span wire:loading wire:target="sendBulkRecall"><i class="fas fa-spinner fa-spin mr-1"></i></span>
                <span wire:loading.remove wire:target="sendBulkRecall"><i class="fas fa-bullhorn mr-1"></i></span>
                Send Bulk Recall ({{ $dueCount }})
              </button>
            </div>
            @endif
          </div>
        </div>

        <div class="card-body p-0">
          <div class="ui-table-wrap">
            <table class="table ui-table ui-table-sm mb-0">
              <thead class="">
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
                    <div class="font-semibold">{{ $patient->name }}</div>
                    <div class="text-slate-500 text-sm">{{ $patient->pxnumber }}</div>
                  </td>
                  <td class="text-sm">{{ $patient->contact }}</td>
                  <td class="text-sm text-slate-500">
                    @if($patient->consultations_max_created_at)
                      {{ \Carbon\Carbon::parse($patient->consultations_max_created_at)->format('d M Y') }}
                      <br>
                      <span class="text-{{ \Carbon\Carbon::parse($patient->consultations_max_created_at)->diffInMonths(now()) > 18 ? 'danger' : 'warning' }}">
                        {{ \Carbon\Carbon::parse($patient->consultations_max_created_at)->diffForHumans() }}
                      </span>
                    @else
                      <span class="text-slate-500">—</span>
                    @endif
                  </td>
                  @if($activeTab === 'sent' || $activeTab === 'returned')
                  <td class="text-sm text-slate-500">
                    {{ $patient->recall_sms_sent_at ? $patient->recall_sms_sent_at->format('d M Y') : '—' }}
                  </td>
                  @endif
                  <td class="text-center" style="white-space:nowrap;">
                    @if($activeTab === 'due')
                      <button wire:click="sendRecall({{ $patient->id }})"
                              wire:loading.attr="disabled"
                              wire:target="sendRecall({{ $patient->id }})"
                              class="btn ui-button ui-button-sm ui-button-primary" title="Send recall SMS">
                        <span wire:loading wire:target="sendRecall({{ $patient->id }})"><i class="fas fa-spinner fa-spin"></i></span>
                        <span wire:loading.remove wire:target="sendRecall({{ $patient->id }})"><i class="fas fa-paper-plane"></i></span>
                        Send
                      </button>
                    @elseif($activeTab === 'sent')
                      <button wire:click="sendRecall({{ $patient->id }})"
                              wire:confirm="Re-send recall to {{ $patient->name }}?"
                              class="btn ui-button ui-button-sm ui-button-secondary" title="Re-send">
                        <i class="fas fa-redo"></i> Re-send
                      </button>
                      <button wire:click="resetRecall({{ $patient->id }})"
                              wire:confirm="Reset recall cycle for {{ $patient->name }}? They will appear as due again."
                              class="btn ui-button ui-button-sm ui-button-secondary" title="Reset cycle">
                        <i class="fas fa-undo"></i>
                      </button>
                    @elseif($activeTab === 'returned')
                      <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800"><i class="fas fa-check mr-1"></i>Returned</span>
                      <button wire:click="resetRecall({{ $patient->id }})"
                              wire:confirm="Reset recall cycle for {{ $patient->name }}?"
                              class="btn ui-button ui-button-sm ui-button-secondary ml-1" title="Reset cycle">
                        <i class="fas fa-undo"></i>
                      </button>
                    @endif
                  </td>
                </tr>
                @empty
                <tr>
                  <td colspan="5" class="text-center py-12 text-slate-500">
                    @if($activeTab === 'due')
                      <i class="fas fa-check-circle fa-3x mb-4 block text-green-700"></i>
                      <h5>No patients due for recall</h5>
                      <p class="text-slate-500">All patients have visited within the last {{ $months }} months.</p>
                    @elseif($activeTab === 'sent')
                      <i class="fas fa-paper-plane fa-3x mb-4 block text-slate-500"></i>
                      <h5>No recalls sent this cycle</h5>
                    @else
                      <i class="fas fa-user-check fa-3x mb-4 block text-slate-500"></i>
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
        <div class="border-t border-slate-200 bg-slate-50 px-4 py-2">{{ $patientsPaginated->links() }}</div>
        @endif
      </div>

    </div>
  </div>
</div>
