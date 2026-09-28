<div class="p-4">
    <div class="d-flex flex-wrap align-items-start justify-content-between mb-4" style="gap:.75rem">
        <div>
            <h4 class="mb-1"><i class="fas fa-envelope-open-text mr-2 text-primary"></i>Owner Emails</h4>
            <p class="text-muted mb-0">Billing notices, approval requests, staff changes, stock alerts and sales summaries are emailed to the clinic owner only.</p>
        </div>
        <button type="button" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest" class="btn btn-outline-primary font-weight-bold">
            <span wire:loading.remove wire:target="sendTest"><i class="fas fa-paper-plane mr-1"></i> Send a test summary</span>
            <span wire:loading wire:target="sendTest"><i class="fas fa-spinner fa-spin mr-1"></i> Sending…</span>
        </button>
    </div>

    <div class="row">
        <div class="col-lg-5 mb-3">
            <div class="border rounded p-3 h-100">
                <div class="small text-muted text-uppercase font-weight-bold mb-1">Owner email</div>
                @if($ownerEmail)
                    <div class="h6 mb-1">{{ $ownerEmail }}</div>
                @else
                    <div class="text-danger font-weight-bold mb-1">Not set: no emails can be sent</div>
                @endif
                <a href="{{ route('admin.subscription') }}" class="small">Change it under Subscription → Billing profile</a>
            </div>
        </div>
        <div class="col-lg-7 mb-3">
            <div class="border rounded p-3 h-100">
                <div class="small text-muted text-uppercase font-weight-bold mb-2">Scheduled emails on your plan</div>
                @foreach([
                    'daily' => ['Daily', 'Every morning at 7:00, for the day before'],
                    'weekly' => ['Weekly', 'Monday at 7:00, for the week before'],
                    'monthly' => ['Monthly', 'The 1st at 7:00, for the month before'],
                    'alerts' => ['Morning alerts', 'At 7:00 when something new needs attention: low or expiring stock, bills due, late or uncollected jobs'],
                ] as $period => [$name, $when])
                    <div class="d-flex align-items-center mb-1">
                        @if($included[$period])
                            <i class="fas fa-check-circle text-success mr-2"></i><span class="font-weight-bold mr-2 text-nowrap">{{ $name }}</span>
                            <span class="text-muted small">{{ $when }}</span>
                        @else
                            <i class="far fa-circle text-muted mr-2"></i><span class="text-muted mr-2 text-nowrap">{{ $name }}</span>
                            <span class="text-muted small">Not on your plan</span>
                        @endif
                    </div>
                @endforeach
                <div class="text-muted small mt-2">Times are clinic time ({{ $timezone }}). Every branch is included.</div>
            </div>
        </div>
    </div>

    <h6 class="font-weight-bold mt-2 mb-2">Recent emails</h6>
    @if($emails->isEmpty())
        <p class="text-muted">Nothing sent yet.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="thead-light">
                    <tr><th>When</th><th>Email</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach($emails as $email)
                        <tr>
                            <td class="text-nowrap small">{{ $email->created_at->timezone($timezone)->format('d M Y, g:i A') }}</td>
                            <td class="small">{{ $email->subject }}</td>
                            <td class="small">
                                @if($email->status === 'sent')
                                    <span class="badge badge-success">Sent</span>
                                @elseif($email->status === 'failed')
                                    <span class="badge badge-danger">Failed</span>
                                    <div class="text-muted" style="max-width:260px">{{ \Illuminate\Support\Str::limit($email->error, 120) }}</div>
                                @else
                                    <span class="badge badge-secondary">Not sent</span>
                                    <div class="text-muted">{{ $email->error }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
