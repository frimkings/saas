<div class="ui-panel p-6 space-y-5 max-w-2xl">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-slate-900">Owner emails</h2>
            <p class="ui-muted text-sm">Billing notices, approval requests, staff changes, stock alerts and sales summaries go to the clinic owner only.</p>
        </div>
        <button type="button" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest" class="ui-button">
            <span wire:loading.remove wire:target="sendTest">Send a test summary</span>
            <span wire:loading wire:target="sendTest">Sending…</span>
        </button>
    </div>

    <div class="rounded-lg border border-slate-200 p-4">
        <div class="text-xs font-semibold uppercase text-slate-500">Owner email</div>
        @if($ownerEmail)
            <div class="font-semibold text-slate-900">{{ $ownerEmail }}</div>
        @else
            <div class="font-semibold text-red-600">Not set: no emails can be sent</div>
        @endif
        <a href="{{ route('admin.subscription') }}" class="text-sm">Change it under Subscription → Billing profile</a>
    </div>

    <div class="rounded-lg border border-slate-200 p-4 space-y-1">
        <div class="text-xs font-semibold uppercase text-slate-500 mb-1">Scheduled emails on your plan</div>
        @foreach([
            'daily' => ['Daily summary', 'Every morning at 7:00, for the day before'],
            'weekly' => ['Weekly summary', 'Monday at 7:00, for the week before'],
            'monthly' => ['Monthly summary', 'The 1st at 7:00, for the month before'],
            'alerts' => ['Morning alerts', 'At 7:00 when something new needs attention: low stock, late or uncollected jobs, bills due'],
        ] as $period => [$name, $when])
            <div class="flex items-baseline gap-2 text-sm">
                @if($included[$period])
                    <span class="text-emerald-600" aria-hidden="true">✓</span><span class="font-semibold whitespace-nowrap">{{ $name }}</span><span class="ui-muted text-xs">{{ $when }}</span>
                @else
                    <span class="text-slate-400" aria-hidden="true">○</span><span class="text-slate-500 whitespace-nowrap">{{ $name }}</span><span class="ui-muted text-xs">Not on your plan</span>
                @endif
            </div>
        @endforeach
        <p class="ui-muted text-xs pt-1">Times are clinic time ({{ $timezone }}). Every branch is included.</p>
    </div>

    <div>
        <h3 class="text-sm font-semibold text-slate-900 mb-2">Recent emails</h3>
        @if($emails->isEmpty())
            <p class="ui-muted text-sm">Nothing sent yet.</p>
        @else
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr><th>When</th><th>Email</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach($emails as $email)
                            <tr>
                                <td class="whitespace-nowrap">{{ $email->created_at->timezone($timezone)->format('d M Y, g:i A') }}</td>
                                <td>{{ $email->subject }}</td>
                                <td>
                                    @if($email->status === 'sent')
                                        <span class="ui-badge">Sent</span>
                                    @elseif($email->status === 'failed')
                                        <span class="ui-badge" style="background:#fef3f2;color:#b42318">Failed</span>
                                        <div class="ui-muted text-xs">{{ \Illuminate\Support\Str::limit($email->error, 120) }}</div>
                                    @else
                                        <span class="ui-badge" style="background:#f2f4f7;color:#475467">Not sent</span>
                                        <div class="ui-muted text-xs">{{ $email->error }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
