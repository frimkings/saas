<div class="ui-panel">
    <div class="border-b border-slate-200 px-5 py-4">
        <h2 class="text-base font-semibold text-slate-900">Payment corrections</h2>
        <p class="text-xs text-slate-500">Payments recorded under the wrong method. Approving moves the payment to the right method so the till balances; the amount does not change.</p>
    </div>
    <div class="ui-table-wrap">
        <table class="ui-table">
            <thead><tr><th>Taken</th><th>Sale</th><th>Change</th><th class="ui-number">Amount</th><th>Why</th><th>Asked by</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
                @forelse($corrections as $correction)
                    <tr wire:key="pc-{{ $correction->id }}">
                        <td class="whitespace-nowrap">{{ $correction->payment?->created_at?->format('d M Y H:i') }}</td>
                        <td class="font-mono text-xs">{{ $correction->sale?->transaction_id }}</td>
                        <td>{{ \App\Support\PaymentMethods::label($correction->from_method) }} → <b>{{ \App\Support\PaymentMethods::label($correction->to_method) }}</b></td>
                        <td class="ui-number">{{ number_format((float) $correction->amount, 2) }}</td>
                        <td class="text-sm">{{ $correction->reason }}</td>
                        <td class="text-xs text-slate-500">{{ $correction->requester?->name }}</td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1">
                                <button type="button" wire:click="approve({{ $correction->id }})" class="ui-button ui-button-primary">Approve</button>
                                <button type="button" wire:click="$set('rejectingId', {{ $correction->id }})" class="ui-button ui-button-danger">Reject</button>
                            </div>
                        </td>
                    </tr>
                    @if($rejectingId === $correction->id)
                        <tr wire:key="pc-reject-{{ $correction->id }}"><td colspan="7" class="bg-red-50">
                            <form wire:submit="reject" class="flex flex-wrap items-end gap-2">
                                <div class="min-w-[16rem] flex-1">
                                    <label for="pc-reject-reason" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Reason for rejecting</label>
                                    <input id="pc-reject-reason" type="text" wire:model="rejectReason" class="ui-input" maxlength="500">
                                    @error('rejectReason')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                                </div>
                                <button type="submit" class="ui-button ui-button-danger">Reject</button>
                                <button type="button" wire:click="$set('rejectingId', null)" class="ui-button ui-button-secondary">Back</button>
                            </form>
                        </td></tr>
                    @endif
                @empty
                    <tr><td colspan="7" class="ui-empty italic text-slate-500">No payment corrections waiting.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
