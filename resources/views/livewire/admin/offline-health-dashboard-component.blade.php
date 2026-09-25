<div class="container-fluid py-3">
    <section class="content-header">
        <div class="d-flex flex-wrap align-items-center justify-content-between">
            <div>
                <h1 class="m-0"><i class="fas fa-heartbeat mr-2 text-success"></i>Offline Health Dashboard</h1>
                <p class="text-muted mb-0">Local status for scheduler, backups, mail, reports, SMS, and WhatsApp.</p>
            </div>
            <button type="button" class="btn btn-primary font-weight-bold mt-2 mt-md-0" wire:click="refreshChecks">
                <i class="fas fa-sync-alt mr-1"></i> Run Checks Now
            </button>
        </div>
    </section>

    <section class="content">
        <div>
            <div class="alert alert-info border-0 shadow-sm">
                <i class="fas fa-info-circle mr-2"></i>
                This page uses only local database and filesystem signals. If the internet is down, pending reports remain in the outbox and retry when connectivity returns.
                @if($lastDashboardCheck)
                    <span class="ml-2 text-nowrap">Last manual check: <strong>{{ $lastDashboardCheck->format('d M Y, h:i A') }}</strong></span>
                @endif
            </div>

            <div class="row">
                @foreach($cards as $card)
                    @php
                        $tone = [
                            'healthy' => ['class' => 'success', 'label' => 'Healthy'],
                            'warning' => ['class' => 'warning', 'label' => 'Warning'],
                            'critical' => ['class' => 'danger', 'label' => 'Action Needed'],
                            'disabled' => ['class' => 'secondary', 'label' => 'Disabled'],
                        ][$card['state']] ?? ['class' => 'secondary', 'label' => ucfirst($card['state'])];
                    @endphp
                    <div class="col-xl-4 col-lg-6 col-md-6 mb-3">
                        <div class="card h-100 shadow-sm border-0 ohd-card ohd-card--{{ $tone['class'] }}">
                            <div class="card-body">
                                <div class="d-flex align-items-start justify-content-between mb-3">
                                    <div class="d-flex align-items-center">
                                        <span class="ohd-icon bg-{{ $tone['class'] }}"><i class="fas {{ $card['icon'] }}"></i></span>
                                        <div>
                                            <div class="font-weight-bold">{{ $card['title'] }}</div>
                                            <div class="text-muted small">{{ $card['summary'] }}</div>
                                        </div>
                                    </div>
                                    <span class="badge badge-{{ $tone['class'] }} px-2 py-1">{{ $tone['label'] }}</span>
                                </div>
                                <p class="small text-muted mb-3">{{ $card['detail'] }}</p>
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

            <div class="row">
                <div class="col-lg-7 mb-3">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white d-flex align-items-center justify-content-between">
                            <span class="font-weight-bold"><i class="fas fa-paper-plane mr-1 text-primary"></i>Recent Report Deliveries</span>
                            <a href="{{ route('admin.settings', ['tab' => 'report']) }}" class="btn btn-sm btn-outline-primary">Open Outbox</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
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
                                                <span class="badge badge-{{ $delivery->status === 'sent' ? 'success' : ($delivery->status === 'failed' ? 'danger' : 'warning') }}">
                                                    {{ ucfirst($delivery->status) }}
                                                </span>
                                            </td>
                                            <td>{{ $delivery->attempts }}</td>
                                            <td>{{ optional($delivery->last_attempt_at ?? $delivery->sent_at ?? $delivery->created_at)->format('d M, h:i A') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center text-muted py-4">No report deliveries recorded.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5 mb-3">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white d-flex align-items-center justify-content-between">
                            <span class="font-weight-bold"><i class="fas fa-comment-slash mr-1 text-danger"></i>Failed Messages</span>
                            <a href="{{ route('admin.sms-logs') }}" class="btn btn-sm btn-outline-secondary">SMS Logs</a>
                        </div>
                        <div class="list-group list-group-flush">
                            @forelse($recentFailedMessages as $log)
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <strong>{{ strtoupper($log->channel ?? 'sms') }} to {{ $log->recipient }}</strong>
                                        <span class="text-muted small">{{ $log->created_at->format('d M, h:i A') }}</span>
                                    </div>
                                    <div class="text-muted small">{{ \Illuminate\Support\Str::limit($log->error ?? 'Failed without provider details.', 95) }}</div>
                                </div>
                            @empty
                                <div class="list-group-item text-center text-muted py-4">No failed messages recorded.</div>
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
