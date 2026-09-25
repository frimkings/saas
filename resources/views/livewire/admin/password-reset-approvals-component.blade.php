<div data-livewire-root>
<div class="container-fluid py-4">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">Password Reset Approvals</h2>
            <p class="text-muted mb-0">Review and action staff password reset requests</p>
        </div>
    </div>

    {{-- Stat cards --}}
    <div class="row mb-4">
        @foreach([
            ['label'=>'Pending',   'key'=>'pending',   'color'=>'warning', 'icon'=>'fa-clock'],
            ['label'=>'Approved',  'key'=>'approved',  'color'=>'success', 'icon'=>'fa-check-circle'],
            ['label'=>'Rejected',  'key'=>'rejected',  'color'=>'danger',  'icon'=>'fa-times-circle'],
            ['label'=>'Completed', 'key'=>'completed', 'color'=>'secondary','icon'=>'fa-flag-checkered'],
        ] as $stat)
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm" style="cursor:pointer;"
                wire:click="$set('filterStatus','{{ $stat['key'] }}')">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="small text-muted text-uppercase font-weight-bold">{{ $stat['label'] }}</div>
                        <div class="h3 mb-0 text-{{ $stat['color'] }}">{{ $counts[$stat['key']] }}</div>
                    </div>
                    <i class="fas {{ $stat['icon'] }} fa-2x text-{{ $stat['color'] }} opacity-50"></i>
                </div>
                @if($filterStatus === $stat['key'])
                    <div class="card-footer p-0">
                        <div style="height:3px;background:var(--{{ $stat['color'] }},currentColor)"
                            class="bg-{{ $stat['color'] }} rounded-bottom"></div>
                    </div>
                @endif
            </div>
        </div>
        @endforeach
    </div>

    {{-- Filter tabs --}}
    <div class="mb-3">
        @foreach(['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','completed'=>'Completed',''=>'All'] as $val=>$label)
            <button wire:click="$set('filterStatus','{{ $val }}')"
                class="btn btn-sm mr-1 {{ $filterStatus === $val ? 'btn-dark' : 'btn-outline-secondary' }}">
                {{ $label }}
                @if($val === 'pending' && $counts['pending'] > 0)
                    <span class="badge badge-warning ml-1">{{ $counts['pending'] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Table --}}
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
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
                        <td><small class="text-muted">#{{ $req->id }}</small></td>
                        <td class="font-weight-bold">{{ $req->email }}</td>
                        <td>
                            @php $user = \App\Models\User::where('email', $req->email)->first() @endphp
                            {{ $user?->name ?? '<span class="text-muted font-italic">Unknown</span>' }}
                        </td>
                        <td>
                            @php
                                $badge = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','completed'=>'secondary'][$req->status] ?? 'secondary';
                            @endphp
                            <span class="badge badge-{{ $badge }}">{{ ucfirst($req->status) }}</span>
                        </td>
                        <td>
                            <div class="small">{{ $req->created_at->format('M d, Y') }}</div>
                            <small class="text-muted">{{ $req->created_at->diffForHumans() }}</small>
                        </td>
                        <td>
                            @if($req->actionedBy)
                                <div class="small font-weight-bold">{{ $req->actionedBy->name }}</div>
                                <small class="text-muted">{{ $req->actioned_at?->format('M d, Y h:i A') }}</small>
                            @else
                                <small class="text-muted font-italic">—</small>
                            @endif
                        </td>
                        <td>
                            @if($req->admin_note)
                                <small class="text-muted" title="{{ $req->admin_note }}">
                                    {{ Str::limit($req->admin_note, 40) }}
                                </small>
                            @else
                                <small class="text-muted font-italic">—</small>
                            @endif
                        </td>
                        <td class="text-right">
                            @if($req->isPending())
                                <button wire:click="openConfirm({{ $req->id }}, 'approve')"
                                    class="btn btn-sm btn-success mr-1">
                                    <i class="fas fa-check mr-1"></i> Approve
                                </button>
                                <button wire:click="openConfirm({{ $req->id }}, 'reject')"
                                    class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-times mr-1"></i> Reject
                                </button>
                            @elseif($req->isApproved())
                                <button wire:click="openConfirm({{ $req->id }}, 'reject')"
                                    class="btn btn-sm btn-outline-danger btn-sm">
                                    <i class="fas fa-ban mr-1"></i> Revoke
                                </button>
                            @else
                                <span class="text-muted small font-italic">No actions</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="text-center py-5">
                            <i class="fas fa-shield-alt fa-3x text-muted mb-3 d-block"></i>
                            <h5 class="text-muted">No {{ $filterStatus ?: '' }} requests found</h5>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($requests->hasPages())
            <div class="card-footer bg-light">{{ $requests->links() }}</div>
        @endif
    </div>

    {{-- Confirm Action Modal --}}
    @if($confirmId)
    <div class="modal fade show" style="display:block; background:rgba(0,0,0,0.5);" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
            <div class="modal-content">
                <div class="modal-header {{ $confirmAction === 'approve' ? 'bg-success' : 'bg-danger' }} text-white">
                    <h5 class="modal-title">
                        <i class="fas {{ $confirmAction === 'approve' ? 'fa-check-circle' : 'fa-times-circle' }} mr-2"></i>
                        {{ $confirmAction === 'approve' ? 'Approve' : 'Reject' }} Request
                    </h5>
                    <button type="button" class="close text-white opacity-1" wire:click="cancelConfirm">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    @php $target = \App\Models\PasswordResetRequest::find($confirmId) @endphp
                    <p class="mb-3">
                        You are about to <strong>{{ $confirmAction }}</strong> the password reset request for:<br>
                        <span class="text-primary font-weight-bold">{{ $target?->email }}</span>
                    </p>
                    <div class="form-group mb-0">
                        <label class="small font-weight-bold">Note <span class="text-muted font-weight-normal">(optional)</span></label>
                        <textarea wire:model.live="noteInput" class="form-control form-control-sm"
                            rows="2" placeholder="Reason or remark..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary btn-sm" wire:click="cancelConfirm">Cancel</button>
                    <button class="btn btn-sm {{ $confirmAction === 'approve' ? 'btn-success' : 'btn-danger' }}"
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
