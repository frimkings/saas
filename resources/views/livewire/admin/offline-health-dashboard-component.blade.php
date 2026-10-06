<div class="clinic-ui ui-page">
    <section class="content-header">
        <div class="flex flex-wrap items-center justify-between">
            <div>
                <h1 class="m-0"><i class="fas fa-heartbeat mr-2 text-green-700"></i>Offline Health Dashboard</h1>
                <p class="text-slate-500 mb-0">Local status for scheduler, backups, mail, reports, SMS, and WhatsApp.</p>
            </div>
            <button type="button" class="btn ui-button ui-button-primary font-semibold mt-2 md:mt-0" wire:click="refreshChecks">
                <i class="fas fa-sync-alt mr-1"></i> Run Checks Now
            </button>
        </div>
    </section>

    <section class="content">
        <div>
            <div class="rounded-lg border px-3 py-2 text-sm border-sky-200 bg-sky-50 text-sky-900 border-0 shadow-sm">
                <i class="fas fa-info-circle mr-2"></i>
                This page uses only local database and filesystem signals. If the internet is down, pending reports remain in the outbox and retry when connectivity returns.
                @if($lastDashboardCheck)
                    <span class="ml-2 whitespace-nowrap">Last manual check: <strong>{{ $lastDashboardCheck->format('d M Y, h:i A') }}</strong></span>
                @endif
            </div>

            <div class="flex flex-wrap -mx-2">
                @foreach($cards as $card)
                    @php
                        $tone = [
                            'healthy' => ['class' => 'success', 'label' => 'Healthy'],
                            'warning' => ['class' => 'warning', 'label' => 'Warning'],
                            'critical' => ['class' => 'danger', 'label' => 'Action Needed'],
                            'disabled' => ['class' => 'secondary', 'label' => 'Disabled'],
                        ][$card['state']] ?? ['class' => 'secondary', 'label' => ucfirst($card['state'])];
                    @endphp
                    <div class="w-full xl:w-4/12 lg:w-6/12 md:w-6/12 px-2 mb-4">
                        <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white h-full shadow-sm border-0 ohd-card ohd-card--{{ $tone['class'] }}">
                            <div class="card-body p-4">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex items-center">
                                        <span class="ohd-icon bg-{{ $tone['class'] }}"><i class="fas {{ $card['icon'] }}"></i></span>
                                        <div>
                                            <div class="font-semibold">{{ $card['title'] }}</div>
                                            <div class="text-slate-500 text-sm">{{ $card['summary'] }}</div>
                                        </div>
                                    </div>
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $tone['class'] }} px-2 py-1">{{ $tone['label'] }}</span>
                                </div>
                                <p class="text-sm text-slate-500 mb-4">{{ $card['detail'] }}</p>
                                @if(!empty($card['metrics']))
                                    <div class="ohd-metrics">
                                        @foreach($card['metrics'] as $label => $value)
                                            <div class="ohd-metric">
                                                <span>{{ $label }}</span>
                                                <strong>{{ $value }}</strong>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap -mx-2">
                <div class="w-full lg:w-7/12 px-2 mb-4">
                    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0">
                        <div class="card-header border-b border-slate-200 px-4 py-2 bg-white flex items-center justify-between">
                            <span class="font-semibold"><i class="fas fa-paper-plane mr-1 text-teal-700"></i>Recent Report Deliveries</span>
                            <a href="{{ route('admin.settings', ['tab' => 'report']) }}" class="btn ui-button ui-button-sm ui-button-secondary">Open Outbox</a>
                        </div>
                        <div class="ui-table-wrap">
                            <table class="table ui-table ui-table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Status</th>
                                        <th>Attempts</th>
                                        <th>Last Attempt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($recentReportDeliveries as $delivery)
                                        <tr>
                                            <td>{{ \Illuminate\Support\Str::limit($delivery->subject, 42) }}</td>
                                            <td>
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $delivery->status === 'sent' ? 'success' : ($delivery->status === 'failed' ? 'danger' : 'warning') }}">
                                                    {{ ucfirst($delivery->status) }}
                                                </span>
                                            </td>
                                            <td>{{ $delivery->attempts }}</td>
                                            <td>{{ optional($delivery->last_attempt_at ?? $delivery->sent_at ?? $delivery->created_at)->format('d M, h:i A') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center text-slate-500 py-6">No report deliveries recorded.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="w-full lg:w-5/12 px-2 mb-4">
                    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0">
                        <div class="card-header border-b border-slate-200 px-4 py-2 bg-white flex items-center justify-between">
                            <span class="font-semibold"><i class="fas fa-comment-slash mr-1 text-red-700"></i>Failed Messages</span>
                            <a href="{{ route('admin.sms-logs') }}" class="btn ui-button ui-button-sm ui-button-secondary">SMS Logs</a>
                        </div>
                        <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
                            @forelse($recentFailedMessages as $log)
                                <div class="list-group-item block w-full border-b border-slate-100 px-3 py-2 text-left">
                                    <div class="flex justify-between">
                                        <strong>{{ strtoupper($log->channel ?? 'sms') }} to {{ $log->recipient }}</strong>
                                        <span class="text-slate-500 text-sm">{{ $log->created_at->format('d M, h:i A') }}</span>
                                    </div>
                                    <div class="text-slate-500 text-sm">{{ \Illuminate\Support\Str::limit($log->error ?? 'Failed without provider details.', 95) }}</div>
                                </div>
                            @empty
                                <div class="list-group-item block w-full border-b border-slate-100 px-3 text-left text-center text-slate-500 py-6">No failed messages recorded.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <style>
        .ohd-card { border-top: 3px solid #d1d5db !important; border-radius: 6px; }
        .ohd-card--success { border-top-color: #28a745 !important; }
        .ohd-card--warning { border-top-color: #ffc107 !important; }
        .ohd-card--danger { border-top-color: #dc3545 !important; }
        .ohd-icon {
            width: 42px;
            height: 42px;
            border-radius: 6px;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            flex: 0 0 42px;
        }
        .ohd-metrics { border-top: 1px solid #eef2f7; padding-top: 8px; }
        .ohd-metric { display: flex; justify-content: space-between; gap: 12px; font-size: .86rem; padding: 5px 0; }
        .ohd-metric span { color: #6b7280; }
        .ohd-metric strong { text-align: right; }
    </style>
</div>
