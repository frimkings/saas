<div class="clinic-ui ui-page" data-livewire-root>
<div class="w-full">

    {{-- Stat cards --}}
    <div class="flex flex-wrap -mx-2 mb-6">
        @foreach([
            ['label'=>'Pending',   'key'=>'pending',   'color'=>'warning', 'icon'=>'fa-clock'],
            ['label'=>'Approved',  'key'=>'approved',  'color'=>'success', 'icon'=>'fa-check-circle'],
            ['label'=>'Rejected',  'key'=>'rejected',  'color'=>'danger',  'icon'=>'fa-times-circle'],
            ['label'=>'Completed', 'key'=>'completed', 'color'=>'secondary','icon'=>'fa-flag-checkered'],
        ] as $stat)
        <div class="w-full md:w-3/12 px-2 mb-4">
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm" style="cursor:pointer;"
                wire:click="$set('filterStatus','{{ $stat['key'] }}')">
                <div class="card-body p-4 flex items-center justify-between">
                    <div>
                        <div class="text-sm text-slate-500 uppercase font-semibold">{{ $stat['label'] }}</div>
                        <div class="text-xl font-semibold mb-0 text-{{ $stat['color'] }}">{{ $counts[$stat['key']] }}</div>
                    </div>
                    <i class="fas {{ $stat['icon'] }} fa-2x text-{{ $stat['color'] }} opacity-50"></i>
                </div>
                @if($filterStatus === $stat['key'])
                    <div class="border-t border-slate-200 bg-slate-50 px-4 py-2 p-0">
                        <div style="height:3px;background:var(--{{ $stat['color'] }},currentColor)"
                            class="bg-{{ $stat['color'] }} rounded-bottom"></div>
                    </div>
                @endif
            </div>
        </div>
        @endforeach
    </div>

    {{-- Filter tabs --}}
    <div class="mb-4">
        @foreach(['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','completed'=>'Completed',''=>'All'] as $val=>$label)
            <button wire:click="$set('filterStatus','{{ $val }}')"
                class="btn ui-button ui-button-sm mr-1 {{ $filterStatus === $val ? 'ui-button-secondary' : 'ui-button-secondary' }}">
                {{ $label }}
                @if($val === 'pending' && $counts['pending'] > 0)
                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 ml-1">{{ $counts['pending'] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Table --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="ui-table-wrap">
            <table class="table ui-table mb-0">
                <thead class="">
                    <tr>
                        <th>#</th>
                        <th>Email</th>
                        <th>Account Name</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Actioned By</th>
                        <th>Note</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($requests as $req)
                    <tr wire:key="req-{{ $req->id }}">
                        <td><small class="text-slate-500">#{{ $req->id }}</small></td>
                        <td class="font-semibold">{{ $req->email }}</td>
                        <td>
                            @php $user = \App\Models\User::where('email', $req->email)->first() @endphp
                            {{ $user?->name ?? '<span class="text-slate-500 italic">Unknown</span>' }}
                        </td>
                        <td>
                            @php
                                $badge = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','completed'=>'secondary'][$req->status] ?? 'secondary';
                            @endphp
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $badge }}">{{ ucfirst($req->status) }}</span>
                        </td>
                        <td>
                            <div class="text-sm">{{ $req->created_at->format('M d, Y') }}</div>
                            <small class="text-slate-500">{{ $req->created_at->diffForHumans() }}</small>
                        </td>
                        <td>
                            @if($req->actionedBy)
                                <div class="text-sm font-semibold">{{ $req->actionedBy->name }}</div>
                                <small class="text-slate-500">{{ $req->actioned_at?->format('M d, Y h:i A') }}</small>
                            @else
                                <small class="text-slate-500 italic">—</small>
                            @endif
                        </td>
                        <td>
                            @if($req->admin_note)
                                <small class="text-slate-500" title="{{ $req->admin_note }}">
                                    {{ Str::limit($req->admin_note, 40) }}
                                </small>
                            @else
                                <small class="text-slate-500 italic">—</small>
                            @endif
                        </td>
                        <td class="text-right">
                            @if($req->isPending())
                                <button wire:click="openConfirm({{ $req->id }}, 'approve')"
                                    class="btn ui-button ui-button-sm ui-button-primary mr-1">
                                    <i class="fas fa-check mr-1"></i> Approve
                                </button>
                                <button wire:click="openConfirm({{ $req->id }}, 'reject')"
                                    class="btn ui-button ui-button-sm ui-button-danger">
                                    <i class="fas fa-times mr-1"></i> Reject
                                </button>
                            @elseif($req->isApproved())
                                <button wire:click="openConfirm({{ $req->id }}, 'reject')"
                                    class="btn ui-button ui-button-sm ui-button-danger">
                                    <i class="fas fa-ban mr-1"></i> Revoke
                                </button>
                            @else
                                <span class="text-slate-500 text-sm italic">No actions</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="text-center py-12">
                            <i class="fas fa-shield-alt fa-3x text-slate-500 mb-4 block"></i>
                            <h5 class="text-slate-500">No {{ $filterStatus ?: '' }} requests found</h5>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($requests->hasPages())
            <div class="border-t border-slate-200 px-4 py-2 bg-slate-50">{{ $requests->links() }}</div>
        @endif
    </div>

    {{-- Confirm Action Modal --}}
    @if($confirmId)
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show" style="display:block; background:rgba(0,0,0,0.5);" tabindex="-1" role="dialog">
        <div class="mx-auto my-8 w-full max-w-sm" role="document">
            <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 {{ $confirmAction === 'approve' ? 'bg-green-600 text-white' : 'bg-red-600 text-white' }} text-white">
                    <h5 class="text-base font-semibold">
                        <i class="fas {{ $confirmAction === 'approve' ? 'fa-check-circle' : 'fa-times-circle' }} mr-2"></i>
                        {{ $confirmAction === 'approve' ? 'Approve' : 'Reject' }} Request
                    </h5>
                    <button type="button" class="text-xl leading-none hover:text-slate-800 text-white opacity-1" wire:click="cancelConfirm">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="p-4">
                    @php $target = \App\Models\PasswordResetRequest::find($confirmId) @endphp
                    <p class="mb-4">
                        You are about to <strong>{{ $confirmAction }}</strong> the password reset request for:<br>
                        <span class="text-teal-700 font-semibold">{{ $target?->email }}</span>
                    </p>
                    <div class="mb-0">
                        <label class="text-sm font-semibold">Note <span class="text-slate-500 font-normal">(optional)</span></label>
                        <textarea wire:model.live.debounce.400ms="noteInput" class="form-control ui-input ui-input-sm"
                            rows="2" placeholder="Reason or remark..."></textarea>
                    </div>
                </div>
                <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                    <button class="btn ui-button ui-button-secondary ui-button-sm" wire:click="cancelConfirm">Cancel</button>
                    <button class="btn ui-button ui-button-sm {{ $confirmAction === 'approve' ? 'ui-button-primary' : 'ui-button-danger' }}"
                        wire:click="execute">
                        Confirm {{ ucfirst($confirmAction) }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>

<style>
    .opacity-50 { opacity: 0.5; }
    .opacity-1 { opacity: 1 !important; }
</style>
</div>
