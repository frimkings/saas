<div class="p-6">
    <div class="flex flex-wrap items-start justify-between mb-6" style="gap:.75rem">
        <div>
            <h4 class="mb-1"><i class="fas fa-envelope-open-text mr-2 text-teal-700"></i>Owner Emails</h4>
            <p class="text-slate-500 mb-0">Billing notices, approval requests, staff changes, stock alerts and sales summaries are emailed to the clinic owner only.</p>
        </div>
        <button type="button" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest" class="btn ui-button ui-button-secondary font-semibold">
            <span wire:loading.remove wire:target="sendTest"><i class="fas fa-paper-plane mr-1"></i> Send a test summary</span>
            <span wire:loading wire:target="sendTest"><i class="fas fa-spinner fa-spin mr-1"></i> Sending…</span>
        </button>
    </div>

    <div class="flex flex-wrap -mx-2">
        <div class="w-full lg:w-5/12 px-2 mb-4">
            <div class="border border-slate-200 rounded-md p-4 h-full">
                <div class="text-sm text-slate-500 uppercase font-semibold mb-1">Owner email</div>
                @if($ownerEmail)
                    <div class="text-sm font-semibold mb-1">{{ $ownerEmail }}</div>
                @else
                    <div class="text-red-700 font-semibold mb-1">Not set: no emails can be sent</div>
                @endif
                <a href="{{ route('admin.subscription') }}" class="text-sm">Change it under Subscription → Billing profile</a>
            </div>
        </div>
        <div class="w-full lg:w-7/12 px-2 mb-4">
            <div class="border border-slate-200 rounded-md p-4 h-full">
                <div class="text-sm text-slate-500 uppercase font-semibold mb-2">Scheduled emails on your plan</div>
                @foreach([
                    'daily' => ['Daily', 'Every morning at 10:00, for the day before'],
                    'weekly' => ['Weekly', 'Monday at 10:00, for the week before'],
                    'monthly' => ['Monthly', 'The 1st at 10:00, for the month before'],
                    'alerts' => ['Morning alerts', 'At 10:00 when something new needs attention: low or expiring stock, bills due, late or uncollected jobs'],
                ] as $period => [$name, $when])
                    <div class="flex items-center mb-1">
                        @if($included[$period])
                            <i class="fas fa-check-circle text-green-700 mr-2"></i><span class="font-semibold mr-2 whitespace-nowrap">{{ $name }}</span>
                            <span class="text-slate-500 text-sm">{{ $when }}</span>
                        @else
                            <i class="far fa-circle text-slate-500 mr-2"></i><span class="text-slate-500 mr-2 whitespace-nowrap">{{ $name }}</span>
                            <span class="text-slate-500 text-sm">Not on your plan</span>
                        @endif
                    </div>
                @endforeach
                <div class="text-slate-500 text-sm mt-2">Times are clinic time ({{ $timezone }}). Every branch is included.</div>
            </div>
        </div>
    </div>

    <h6 class="font-semibold mt-2 mb-2">Recent emails</h6>
    @if($emails->isEmpty())
        <p class="text-slate-500">Nothing sent yet.</p>
    @else
        <div class="ui-table-wrap">
            <table class="table ui-table ui-table-sm mb-0">
                <thead class="">
                    <tr><th>When</th><th>Email</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach($emails as $email)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ $email->created_at->timezone($timezone)->format('d M Y, g:i A') }}</td>
                            <td class="text-sm">{{ $email->subject }}</td>
                            <td class="text-sm">
                                @if($email->status === 'sent')
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800">Sent</span>
                                @elseif($email->status === 'failed')
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800">Failed</span>
                                    <div class="text-slate-500" style="max-width:260px">{{ \Illuminate\Support\Str::limit($email->error, 120) }}</div>
                                @else
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700">Not sent</span>
                                    <div class="text-slate-500">{{ $email->error }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
