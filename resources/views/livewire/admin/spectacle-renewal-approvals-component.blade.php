<div class="container-fluid py-4">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">Spectacle Renewal Reminders</h2>
            <p class="text-muted mb-0">Review and approve SMS reminders before they are sent to patients.</p>
        </div>
    </div>

    {{-- Stat cards --}}
    <div class="row mb-4">
        @foreach([
            ['label'=>'Pending',  'key'=>'pending',  'color'=>'warning', 'icon'=>'fa-clock'],
            ['label'=>'Approved', 'key'=>'approved', 'color'=>'success', 'icon'=>'fa-check-circle'],
            ['label'=>'Rejected', 'key'=>'rejected', 'color'=>'danger',  'icon'=>'fa-times-circle'],
        ] as $stat)
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm" style="cursor:pointer"
                 wire:click="$set('filterStatus','{{ $stat['key'] }}')">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="small text-muted text-uppercase font-weight-bold">{{ $stat['label'] }}</div>
                        <div class="h3 mb-0 text-{{ $stat['color'] }}">{{ $counts[$stat['key']] }}</div>
                    </div>
                    <i class="fas {{ $stat['icon'] }} fa-2x text-{{ $stat['color'] }}" style="opacity:.4"></i>
                </div>
                @if($filterStatus === $stat['key'])
                    <div class="card-footer p-0">
                        <div class="bg-{{ $stat['color'] }} rounded-bottom" style="height:3px"></div>
                    </div>
                @endif
            </div>
        </div>
        @endforeach
    </div>

    {{-- Filter tabs --}}
    <div class="mb-3">
        @foreach(['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected',''=>'All'] as $val=>$label)
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
            <table class="table table-hover mb-0 align-middle">
                <thead class="thead-light">
                    <tr>
                        <th>Patient</th>
                        <th>Contact</th>
                        <th>Renewal Date</th>
                        <th>Message Preview</th>
                        <th>Status</th>
                        <th>Actioned By</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($orders as $order)
                        @php
                            $recipient = $order->renewalRecipient(); $patient = $order->customer ?? ($recipient ? (object) ['name' => $recipient['name'], 'pxnumber' => '', 'contact' => $recipient['phone']] : null);
                            $badge   = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$order->renewal_approval_status] ?? 'secondary';
                        @endphp
                        <tr wire:key="rn-{{ $order->id }}">
                            <td>
                                <div class="font-weight-bold">{{ $patient?->name ?? '—' }}</div>
                                <small class="text-muted">{{ $patient?->pxnumber ?? '' }}</small>
                            </td>
                            <td>
                                <small>{{ $patient?->contact ?? '<span class="text-muted font-italic">No contact</span>' }}</small>
                            </td>
                            <td>
                                @if($order->renewal_date)
                                    <div class="font-weight-bold">{{ $order->renewal_date->format('d M Y') }}</div>
                                    <small class="text-muted">{{ $order->renewal_date->diffForHumans() }}</small>
                                @else
                                    <span class="text-muted font-italic">—</span>
                                @endif
                            </td>
                            <td style="max-width:280px">
                                <small class="text-muted" style="word-break:break-word">
                                    {{ $previews[$order->id] ?? '—' }}
                                </small>
                            </td>
                            <td>
                                <span class="badge badge-{{ $badge }}">{{ ucfirst($order->renewal_approval_status) }}</span>
                                @if($order->renewal_reminder_sent_at)
                                    <div><small class="text-success"><i class="fas fa-check-circle mr-1"></i>SMS sent {{ $order->renewal_reminder_sent_at->format('d M Y') }}</small></div>
                                @endif
                            </td>
                            <td>
                                @if($order->renewalApprovedBy)
                                    <div class="small font-weight-bold">{{ $order->renewalApprovedBy->name }}</div>
                                    <small class="text-muted">{{ $order->renewal_actioned_at?->format('d M Y H:i') }}</small>
                                @else
                                    <small class="text-muted font-italic">—</small>
                                @endif
                            </td>
                            <td class="text-right" style="white-space:nowrap">
                                @if($order->renewal_approval_status === 'pending')
                                    @if($patient?->contact)
                                        <button wire:click="openConfirm({{ $order->id }}, 'approve')"
                                                class="btn btn-sm btn-success mr-1">
                                            <i class="fas fa-check mr-1"></i> Approve &amp; Send
                                        </button>
                                    @else
                                        <span class="btn btn-sm btn-success mr-1 disabled" title="No contact number">
                                            <i class="fas fa-check mr-1"></i> Approve
                                        </span>
                                    @endif
                                    <button wire:click="openConfirm({{ $order->id }}, 'reject')"
                                            class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-times mr-1"></i> Reject
                                    </button>
                                @elseif($order->renewal_approval_status === 'rejected')
                                    <button wire:click="requeue({{ $order->id }})"
                                            class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-undo mr-1"></i> Re-queue
                                    </button>
                                @else
                                    <span class="text-muted small font-italic">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <i class="fas fa-redo fa-3x text-muted mb-3 d-block"></i>
                                <h5 class="text-muted">No {{ $filterStatus ?: '' }} renewal reminders</h5>
                                <p class="text-muted small">
                                    The scheduler queues reminders automatically when renewal dates fall within the configured lead time.<br>
                                    You can also run <code>php artisan sms:spectacle-renewal-reminders</code> manually.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($orders->hasPages())
            <div class="card-footer bg-light">{{ $orders->links() }}</div>
        @endif
    </div>

    {{-- Confirm modal --}}
    @if($confirmId)
        @php $target = \App\Models\LensOrder::with(['patient', 'refraction.consultation.patient'])->find($confirmId) @endphp
        <div class="modal fade show" style="display:block; background:rgba(0,0,0,0.5);" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header {{ $confirmAction === 'approve' ? 'bg-success' : 'bg-danger' }} text-white">
                        <h5 class="modal-title">
                            <i class="fas {{ $confirmAction === 'approve' ? 'fa-check-circle' : 'fa-times-circle' }} mr-2"></i>
                            {{ $confirmAction === 'approve' ? 'Approve & Send SMS' : 'Reject Reminder' }}
                        </h5>
                        <button type="button" class="close text-white" wire:click="cancelConfirm">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        @php $recipient = $target?->renewalRecipient(); $pt = $target?->customer ?? ($recipient ? (object) ['name' => $recipient['name'], 'pxnumber' => '', 'contact' => $recipient['phone']] : null) @endphp
                        @if($confirmAction === 'approve')
                            <p class="mb-3">
                                The following SMS will be sent to <strong>{{ $pt?->name }}</strong>
                                at <strong>{{ $pt?->contact }}</strong>:
                            </p>
                            <div class="alert alert-info p-3 small" style="font-style:italic">
                                {{ $previews[$confirmId] ?? '—' }}
                            </div>
                        @else
                            <p class="mb-3">
                                Reject the renewal reminder for <strong>{{ $pt?->name }}</strong>?
                                No SMS will be sent. You can re-queue it later.
                            </p>
                        @endif
                        <div class="form-group mb-0">
                            <label class="small font-weight-bold">Note <span class="text-muted font-weight-normal">(optional)</span></label>
                            <textarea wire:model.live.debounce.400ms="noteInput" class="form-control form-control-sm"
                                      rows="2" placeholder="Reason or remark…"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary btn-sm" wire:click="cancelConfirm">Cancel</button>
                        <button class="btn btn-sm {{ $confirmAction === 'approve' ? 'btn-success' : 'btn-danger' }}"
                                wire:click="execute"
                                wire:loading.attr="disabled" wire:target="execute">
                            <span wire:loading.remove wire:target="execute">Confirm {{ $confirmAction === 'approve' ? 'Send' : 'Reject' }}</span>
                            <span wire:loading wire:target="execute"><i class="fas fa-circle-notch fa-spin mr-1"></i> Processing…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>
