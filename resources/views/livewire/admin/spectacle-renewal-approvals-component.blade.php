<div class="w-full">

    {{-- Stat cards --}}
    <div class="flex flex-wrap -mx-2 mb-6">
        @foreach([
            ['label'=>'Pending',  'key'=>'pending',  'color'=>'warning', 'icon'=>'fa-clock'],
            ['label'=>'Approved', 'key'=>'approved', 'color'=>'success', 'icon'=>'fa-check-circle'],
            ['label'=>'Rejected', 'key'=>'rejected', 'color'=>'danger',  'icon'=>'fa-times-circle'],
        ] as $stat)
        <div class="w-full md:w-4/12 px-2 mb-4">
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm" style="cursor:pointer"
                 wire:click="$set('filterStatus','{{ $stat['key'] }}')">
                <div class="card-body p-4 flex items-center justify-between">
                    <div>
                        <div class="text-sm text-slate-500 uppercase font-semibold">{{ $stat['label'] }}</div>
                        <div class="text-xl font-semibold mb-0 text-{{ $stat['color'] }}">{{ $counts[$stat['key']] }}</div>
                    </div>
                    <i class="fas {{ $stat['icon'] }} fa-2x text-{{ $stat['color'] }}" style="opacity:.4"></i>
                </div>
                @if($filterStatus === $stat['key'])
                    <div class="border-t border-slate-200 bg-slate-50 px-4 py-2 p-0">
                        <div class="bg-{{ $stat['color'] }} rounded-bottom" style="height:3px"></div>
                    </div>
                @endif
            </div>
        </div>
        @endforeach
    </div>

    {{-- Filter tabs --}}
    <div class="mb-4">
        @foreach(['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected',''=>'All'] as $val=>$label)
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
            <table class="table ui-table mb-0 align-middle">
                <thead class="">
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
                                <div class="font-semibold">{{ $patient?->name ?? '—' }}</div>
                                <small class="text-slate-500">{{ $patient?->pxnumber ?? '' }}</small>
                            </td>
                            <td>
                                <small>{{ $patient?->contact ?? '<span class="text-slate-500 italic">No contact</span>' }}</small>
                            </td>
                            <td>
                                @if($order->renewal_date)
                                    <div class="font-semibold">{{ $order->renewal_date->format('d M Y') }}</div>
                                    <small class="text-slate-500">{{ $order->renewal_date->diffForHumans() }}</small>
                                @else
                                    <span class="text-slate-500 italic">—</span>
                                @endif
                            </td>
                            <td style="max-width:280px">
                                <small class="text-slate-500" style="word-break:break-word">
                                    {{ $previews[$order->id] ?? '—' }}
                                </small>
                            </td>
                            <td>
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $badge }}">{{ ucfirst($order->renewal_approval_status) }}</span>
                                @if($order->renewal_reminder_sent_at)
                                    <div><small class="text-green-700"><i class="fas fa-check-circle mr-1"></i>SMS sent {{ $order->renewal_reminder_sent_at->format('d M Y') }}</small></div>
                                @endif
                            </td>
                            <td>
                                @if($order->renewalApprovedBy)
                                    <div class="text-sm font-semibold">{{ $order->renewalApprovedBy->name }}</div>
                                    <small class="text-slate-500">{{ $order->renewal_actioned_at?->format('d M Y H:i') }}</small>
                                @else
                                    <small class="text-slate-500 italic">—</small>
                                @endif
                            </td>
                            <td class="text-right" style="white-space:nowrap">
                                @if($order->renewal_approval_status === 'pending')
                                    @if($patient?->contact)
                                        <button wire:click="openConfirm({{ $order->id }}, 'approve')"
                                                class="btn ui-button ui-button-sm ui-button-primary mr-1">
                                            <i class="fas fa-check mr-1"></i> Approve &amp; Send
                                        </button>
                                    @else
                                        <span class="btn ui-button ui-button-sm ui-button-primary mr-1 disabled" title="No contact number">
                                            <i class="fas fa-check mr-1"></i> Approve
                                        </span>
                                    @endif
                                    <button wire:click="openConfirm({{ $order->id }}, 'reject')"
                                            class="btn ui-button ui-button-sm ui-button-danger">
                                        <i class="fas fa-times mr-1"></i> Reject
                                    </button>
                                @elseif($order->renewal_approval_status === 'rejected')
                                    <button wire:click="requeue({{ $order->id }})"
                                            class="btn ui-button ui-button-sm ui-button-secondary">
                                        <i class="fas fa-undo mr-1"></i> Re-queue
                                    </button>
                                @else
                                    <span class="text-slate-500 text-sm italic">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-12">
                                <i class="fas fa-redo fa-3x text-slate-500 mb-4 block"></i>
                                <h5 class="text-slate-500">No {{ $filterStatus ?: '' }} renewal reminders</h5>
                                <p class="text-slate-500 text-sm">
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
            <div class="border-t border-slate-200 px-4 py-2 bg-slate-50">{{ $orders->links() }}</div>
        @endif
    </div>

    {{-- Confirm modal --}}
    @if($confirmId)
        @php $target = \App\Models\LensOrder::with(['patient', 'refraction.consultation.patient'])->find($confirmId) @endphp
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show" style="display:block; background:rgba(0,0,0,0.5);" tabindex="-1" role="dialog">
            <div class="mx-auto my-8 w-full max-w-lg" role="document">
                <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 {{ $confirmAction === 'approve' ? 'bg-green-600 text-white' : 'bg-red-600 text-white' }} text-white">
                        <h5 class="text-base font-semibold">
                            <i class="fas {{ $confirmAction === 'approve' ? 'fa-check-circle' : 'fa-times-circle' }} mr-2"></i>
                            {{ $confirmAction === 'approve' ? 'Approve & Send SMS' : 'Reject Reminder' }}
                        </h5>
                        <button type="button" class="text-xl leading-none hover:text-slate-800 text-white" wire:click="cancelConfirm">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="p-4">
                        @php $recipient = $target?->renewalRecipient(); $pt = $target?->customer ?? ($recipient ? (object) ['name' => $recipient['name'], 'pxnumber' => '', 'contact' => $recipient['phone']] : null) @endphp
                        @if($confirmAction === 'approve')
                            <p class="mb-4">
                                The following SMS will be sent to <strong>{{ $pt?->name }}</strong>
                                at <strong>{{ $pt?->contact }}</strong>:
                            </p>
                            <div class="rounded-lg border px-3 py-2 text-sm border-sky-200 bg-sky-50 text-sky-900 p-4" style="font-style:italic">
                                {{ $previews[$confirmId] ?? '—' }}
                            </div>
                        @else
                            <p class="mb-4">
                                Reject the renewal reminder for <strong>{{ $pt?->name }}</strong>?
                                No SMS will be sent. You can re-queue it later.
                            </p>
                        @endif
                        <div class="mb-0">
                            <label class="text-sm font-semibold">Note <span class="text-slate-500 font-normal">(optional)</span></label>
                            <textarea wire:model.live.debounce.400ms="noteInput" class="form-control ui-input ui-input-sm"
                                      rows="2" placeholder="Reason or remark…"></textarea>
                        </div>
                    </div>
                    <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                        <button class="btn ui-button ui-button-secondary ui-button-sm" wire:click="cancelConfirm">Cancel</button>
                        <button class="btn ui-button ui-button-sm {{ $confirmAction === 'approve' ? 'ui-button-primary' : 'ui-button-danger' }}"
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
