<div class="clinic-ui ui-page" data-livewire-root>
<div class="w-full">

    {{-- Tabs + Filters --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0 mb-4">
        <div class="card-body p-4 py-2">
            <div class="flex flex-wrap mx-0 items-center">
                <div class="w-full md:w-5/12 px-2">
                    <div class="inline-flex flex-wrap gap-1">
                        <button wire:click="switchTab('pending')"
                            class="btn ui-button {{ $activeTab === 'pending' ? 'ui-button-secondary text-slate-900' : 'ui-button-secondary' }}">
                            <i class="fas fa-clock mr-1"></i> Pending
                            @if($pendingCount > 0)
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-800 text-white ml-1">{{ $pendingCount }}</span>
                            @endif
                        </button>
                        <button wire:click="switchTab('history')"
                            class="btn ui-button {{ $activeTab === 'history' ? 'ui-button-secondary' : 'ui-button-secondary' }}">
                            <i class="fas fa-history mr-1"></i> History
                        </button>
                    </div>
                </div>
                <div class="w-full md:w-4/12 px-1">
                    <input wire:model.live.debounce.300ms="search" type="text"
                           class="form-control ui-input ui-input-sm shadow-none"
                           placeholder="Search patient or reason…">
                </div>
                @if($activeTab === 'history')
                    <div class="w-full md:w-3/12 px-1">
                        <div class="flex items-stretch">
                            <x-date-range from="fromDate" to="toDate" presets="activity" align="right" />
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0">
        <div class="ui-table-wrap" wire:loading.class="opacity-50">
            <table class="table ui-table align-middle mb-0">
                <thead class="bg-slate-50">
                    <tr class="text-sm uppercase font-semibold text-slate-500">
                        <th class="pl-6 border-0">Patient</th>
                        <th class="border-0">Clearance Date</th>
                        <th class="border-0">Requested By</th>
                        <th class="border-0">Reason</th>
                        <th class="border-0">Requested At</th>
                        <th class="border-0 text-center">Status</th>
                        <th class="pr-6 border-0 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        @php
                            $patient   = optional(optional($log->clearance)->patient);
                            $clearance = $log->clearance;
                        @endphp
                        <tr>
                            <td class="pl-6 py-4">
                                <span class="font-semibold text-sm">{{ $patient->name ?? '—' }}</span>
                                @if($patient->pxnumber ?? false)
                                    <div class="text-sm text-slate-500">{{ $patient->pxnumber }}</div>
                                @endif
                            </td>
                            <td class="text-sm whitespace-nowrap">
                                {{ optional($clearance)->clearance_date ?? '—' }}
                            </td>
                            <td class="text-sm">{{ optional($log->requestedBy)->name ?? '—' }}</td>
                            <td class="text-sm text-slate-500" style="max-width:220px; word-break:break-word;">
                                {{ Str::limit($log->reason, 90) }}
                                @if($log->status === 'rejected' && $log->rejection_reason)
                                    <div class="text-red-700 mt-1">
                                        <i class="fas fa-times-circle mr-1"></i>
                                        <em>{{ Str::limit($log->rejection_reason, 80) }}</em>
                                    </div>
                                @endif
                            </td>
                            <td class="text-sm whitespace-nowrap">
                                {{ $log->requested_at?->format('M d, Y H:i') ?? '—' }}
                            </td>
                            <td class="text-center">
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $log->status_color }} px-2 py-1">
                                    {{ $log->status_label }}
                                </span>
                                @if($log->approved_at)
                                    <div class="text-sm text-slate-500 mt-1">
                                        by {{ optional($log->approvedBy)->name ?? '—' }}
                                    </div>
                                @endif
                                @if($log->rejected_at)
                                    <div class="text-sm text-slate-500 mt-1">
                                        by {{ optional($log->rejectedBy)->name ?? '—' }}
                                    </div>
                                @endif
                            </td>
                            <td class="pr-6 text-right whitespace-nowrap">
                                @if($log->status === 'pending')
                                    @if($clearance && $clearance->consultation()->exists())
                                        <span class="inline-flex items-center rounded text-xs font-semibold bg-slate-100 text-slate-700 text-sm px-2 py-1"
                                              title="A consultation is now linked — cannot revoke">
                                            <i class="fas fa-lock mr-1"></i>Blocked
                                        </span>
                                    @else
                                        <button type="button"
                                                class="btn ui-button ui-button-sm ui-button-primary shadow-none mr-1"
                                                wire:click="approve({{ $log->id }})"
                                                wire:confirm="Approve this clearance revoke request? The clearance will be permanently removed.">
                                            <i class="fas fa-check mr-1"></i>Approve
                                        </button>
                                        <button wire:click="openRejectModal({{ $log->id }})"
                                                class="btn ui-button ui-button-sm ui-button-danger shadow-none">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    @endif
                                @else
                                    <span class="text-slate-500 text-sm">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-12 text-slate-500">
                                <i class="fas fa-undo-alt fa-2x mb-2 block opacity-50"></i>
                                No revoke requests found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
            <div class="border-t px-4 bg-white border-slate-200 py-2">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

</div>

{{-- Reject Modal --}}
<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4  {{ $showRejectModal ? 'show' : '' }}" id="rejectRevokeModal" tabindex="-1" role="dialog" style="{{ $showRejectModal ? 'display:block; background:rgba(0,0,0,.45);' : 'display:none;' }}" aria-modal="{{ $showRejectModal ? 'true' : 'false' }}">
    <div class="mx-auto my-8 w-full max-w-lg" role="document">
        <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl border-0 shadow">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-red-600 text-white">
                <h5 class="text-base font-semibold"><i class="fas fa-times-circle mr-2"></i>Reject Revoke Request</h5>
                <button type="button" class="text-xl leading-none hover:text-slate-800 text-white" wire:click="closeRejectModal"><span>&times;</span></button>
            </div>
            <div class="p-4">
                <div class="mb-0">
                    <label class="font-semibold text-sm">Reason for Rejection <span class="text-red-700">*</span></label>
                    <textarea wire:model="rejectionReason"
                              class="form-control ui-input @error('rejectionReason') is-invalid @enderror"
                              rows="3"
                              placeholder="Explain why this revoke request is being rejected…"></textarea>
                    @error('rejectionReason')
                        <div class="ui-error">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-4 py-3 bg-slate-50">
                <button type="button" class="btn ui-button ui-button-secondary" wire:click="closeRejectModal">Cancel</button>
                <button type="button" class="btn ui-button ui-button-danger" wire:click="confirmReject">
                    <i class="fas fa-times mr-1"></i> Confirm Rejection
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    function confirmApproveRevoke(logId, patientName) {
        window.appConfirm('Approve revoke for ' + patientName + '?\nThe clearance will be permanently removed.',
            { title: 'Approve revoke?', confirmText: 'Yes, approve', danger: true })
            .then(ok => ok && @this.call('approve', logId));
    }
</script>
</div>
