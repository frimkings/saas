<div class="container-fluid py-4 clinical-task-center">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 class="mb-1"><i class="fas fa-clipboard-check mr-2 text-primary"></i>Clinical Task Center</h2>
            <p class="text-muted mb-0">One queue for patient care, billing, inventory, communication, and approval work that needs attention.</p>
        </div>
        <button type="button" class="btn btn-primary font-weight-bold" wire:click="refreshTasks" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="refreshTasks"><i class="fas fa-sync-alt mr-1"></i> Refresh</span>
            <span wire:loading wire:target="refreshTasks"><i class="fas fa-spinner fa-spin mr-1"></i> Refreshing...</span>
        </button>
    </div>

    <div class="task-summary-grid mb-4">
        @foreach($summaryCards as $card)
            <a href="{{ $card['route'] }}" class="task-summary-card task-summary-card--{{ $card['tone'] }}">
                <div class="task-summary-card__icon"><i class="fas {{ $card['icon'] }}"></i></div>
                <div class="task-summary-card__body">
                    <div class="task-summary-card__count">{{ number_format($card['count']) }}</div>
                    <div class="task-summary-card__label">{{ $card['label'] }}</div>
                    <div class="task-summary-card__hint">{{ $card['hint'] }}</div>
                </div>
            </a>
        @endforeach
    </div>

    <div class="row">
        <div class="col-xl-4 col-lg-6 mb-4">
            <div class="task-panel">
                <div class="task-panel__header">
                    <div><i class="fas fa-user-clock text-primary mr-1"></i> Patients Awaiting</div>
                    <a href="{{ route('doctor.patient-awaiting') }}">Open</a>
                </div>
                <div class="task-panel__body">
                    @forelse($awaitingPatients as $clearance)
                        <div class="task-row">
                            <div>
                                <div class="task-row__title">{{ $clearance->patient->name ?? 'Unknown patient' }}</div>
                                <div class="task-row__meta">{{ $clearance->patient->pxnumber ?? 'No PX' }} | {{ $clearance->service->name ?? 'Service not set' }}</div>
                            </div>
                            <span class="badge badge-primary">Ready</span>
                        </div>
                    @empty
                        <div class="task-empty">No paid patients waiting for consultation.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-6 mb-4">
            <div class="task-panel">
                <div class="task-panel__header">
                    <div><i class="fas fa-calendar-times text-danger mr-1"></i> Overdue Appointments</div>
                    <a href="{{ route('secretary.appointments') }}">Open</a>
                </div>
                <div class="task-panel__body">
                    @forelse($overdueAppointments as $appointment)
                        <div class="task-row">
                            <div>
                                <div class="task-row__title">{{ $appointment->patient->name ?? 'Unknown patient' }}</div>
                                <div class="task-row__meta">{{ $appointment->scheduled_at?->format('d M Y h:i A') }} | {{ $appointment->title }}</div>
                            </div>
                            <span class="badge badge-danger">{{ $appointment->status }}</span>
                        </div>
                    @empty
                        <div class="task-empty">No overdue appointment queue.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-6 mb-4">
            <div class="task-panel">
                <div class="task-panel__header">
                    <div><i class="fas fa-prescription-bottle-alt text-info mr-1"></i> Pending Prescriptions</div>
                    <a href="{{ route('cashier.seller-desk') }}">Open POS</a>
                </div>
                <div class="task-panel__body">
                    @forelse($pendingPrescriptions as $item)
                        <div class="task-row">
                            <div>
                                <div class="task-row__title">{{ $item->patient->name ?? 'Unknown patient' }}</div>
                                <div class="task-row__meta">{{ $item->product->name ?? 'Unknown item' }} x {{ $item->quantity }} | {{ $item->consultation->doctor->name ?? 'Doctor N/A' }}</div>
                            </div>
                            <span class="badge badge-info">{{ currency() }}{{ number_format((float) $item->total, 2) }}</span>
                        </div>
                    @empty
                        <div class="task-empty">No pending prescription items.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-6 mb-4">
            <div class="task-panel">
                <div class="task-panel__header">
                    <div><i class="fas fa-check-double text-warning mr-1"></i> Approvals</div>
                    <a href="{{ route('admin.approvals') }}">Open</a>
                </div>
                <div class="task-panel__body task-chip-list">
                    <span class="task-chip">Discounts <strong>{{ $approvalCounts['discount'] }}</strong></span>
                    <span class="task-chip">Refunds <strong>{{ $approvalCounts['refund'] }}</strong></span>
                    <span class="task-chip">Clearance revoke <strong>{{ $approvalCounts['clearance'] }}</strong></span>
                    <span class="task-chip">Password reset <strong>{{ $approvalCounts['password'] }}</strong></span>
                    <span class="task-chip">Spectacle renewal <strong>{{ $approvalCounts['spectacle'] }}</strong></span>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-6 mb-4">
            <div class="task-panel">
                <div class="task-panel__header">
                    <div><i class="fas fa-box-open text-purple mr-1"></i> Inventory Alerts</div>
                    <a href="{{ route('admin.inventory-alerts') }}">Open</a>
                </div>
                <div class="task-panel__body task-chip-list">
                    <span class="task-chip">Low stock <strong>{{ $inventoryCounts['low'] }}</strong></span>
                    <span class="task-chip">Out of stock <strong>{{ $inventoryCounts['out'] }}</strong></span>
                    <span class="task-chip">Expired <strong>{{ $inventoryCounts['expired'] }}</strong></span>
                    <span class="task-chip">Expiring 30 days <strong>{{ $inventoryCounts['expiring'] }}</strong></span>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-6 mb-4">
            <div class="task-panel">
                <div class="task-panel__header">
                    <div><i class="fas fa-file-invoice-dollar text-secondary mr-1"></i> Outstanding Balances</div>
                    <a href="{{ route('cashier.outstanding-balances') }}">Open</a>
                </div>
                <div class="task-panel__body">
                    @forelse($outstandingSales as $sale)
                        <div class="task-row">
                            <div>
                                <div class="task-row__title">{{ $sale->patient->name ?? 'Unknown patient' }}</div>
                                <div class="task-row__meta">{{ $sale->transaction_id }} | {{ ucfirst($sale->payment_status) }}</div>
                            </div>
                            <span class="badge badge-secondary">{{ currency() }}{{ number_format(max(0, (float) $sale->total_amount - (float) $sale->amount_paid), 2) }}</span>
                        </div>
                    @empty
                        <div class="task-empty">No outstanding balances.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-xl-6 mb-4">
            <div class="task-panel">
                <div class="task-panel__header">
                    <div><i class="fas fa-paper-plane text-teal mr-1"></i> Report Delivery Outbox</div>
                    <a href="{{ route('admin.settings', ['tab' => 'report']) }}">Open</a>
                </div>
                <div class="task-panel__body">
                    @forelse($reportDeliveries as $delivery)
                        <div class="task-row">
                            <div>
                                <div class="task-row__title">{{ $delivery->subject }}</div>
                                <div class="task-row__meta">{{ $delivery->last_attempt_at ? $delivery->last_attempt_at->format('d M h:i A') : 'Not tried yet' }} | {{ \Illuminate\Support\Str::limit($delivery->last_error ?? 'Waiting to send', 70) }}</div>
                            </div>
                            <span class="badge badge-{{ $delivery->status === 'failed' ? 'danger' : 'warning' }}">{{ ucfirst($delivery->status) }}</span>
                        </div>
                    @empty
                        <div class="task-empty">No pending report deliveries.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-xl-6 mb-4">
            <div class="task-panel">
                <div class="task-panel__header">
                    <div><i class="fas fa-comment-slash text-dark mr-1"></i> Failed SMS / Messages</div>
                    <a href="{{ route('admin.sms-logs') }}">Open</a>
                </div>
                <div class="task-panel__body">
                    @forelse($failedSmsLogs as $log)
                        <div class="task-row">
                            <div>
                                <div class="task-row__title">{{ $log->patient->name ?? $log->recipient }}</div>
                                <div class="task-row__meta">{{ strtoupper($log->channel ?? 'sms') }} | {{ \Illuminate\Support\Str::limit($log->error ?? 'Failed', 80) }}</div>
                            </div>
                            <span class="badge badge-dark">{{ $log->created_at->format('d M') }}</span>
                        </div>
                    @empty
                        <div class="task-empty">No failed SMS messages.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <style>
        .clinical-task-center { color: #0f172a; }
        .task-summary-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
        .task-summary-card { align-items: center; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; color: inherit; display: flex; min-height: 112px; padding: 14px; text-decoration: none; transition: box-shadow .15s, transform .15s; }
        .task-summary-card:hover { box-shadow: 0 8px 24px rgba(15, 23, 42, .09); color: inherit; text-decoration: none; transform: translateY(-1px); }
        .task-summary-card__icon { align-items: center; border-radius: 8px; display: flex; flex: 0 0 44px; height: 44px; justify-content: center; margin-right: 12px; }
        .task-summary-card__count { font-size: 24px; font-weight: 800; line-height: 1; }
        .task-summary-card__label { font-size: 13px; font-weight: 700; margin-top: 3px; }
        .task-summary-card__hint { color: #64748b; font-size: 11px; margin-top: 3px; }
        .task-summary-card--primary .task-summary-card__icon { background: #dbeafe; color: #1d4ed8; }
        .task-summary-card--danger .task-summary-card__icon { background: #fee2e2; color: #dc2626; }
        .task-summary-card--info .task-summary-card__icon { background: #e0f2fe; color: #0284c7; }
        .task-summary-card--warning .task-summary-card__icon { background: #fef3c7; color: #d97706; }
        .task-summary-card--purple .task-summary-card__icon { background: #f3e8ff; color: #7e22ce; }
        .task-summary-card--secondary .task-summary-card__icon { background: #f1f5f9; color: #475569; }
        .task-summary-card--teal .task-summary-card__icon { background: #ccfbf1; color: #0f766e; }
        .task-summary-card--dark .task-summary-card__icon { background: #e5e7eb; color: #111827; }
        .task-panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; height: 100%; overflow: hidden; }
        .task-panel__header { align-items: center; border-bottom: 1px solid #eef2f7; display: flex; font-size: 13px; font-weight: 800; justify-content: space-between; padding: 12px 14px; }
        .task-panel__header a { font-size: 12px; font-weight: 700; }
        .task-panel__body { padding: 8px 12px; }
        .task-row { align-items: center; border-bottom: 1px solid #f1f5f9; display: flex; gap: 10px; justify-content: space-between; padding: 10px 0; }
        .task-row:last-child { border-bottom: 0; }
        .task-row__title { font-size: 13px; font-weight: 700; }
        .task-row__meta { color: #64748b; font-size: 11px; margin-top: 2px; }
        .task-empty { color: #94a3b8; font-size: 13px; padding: 20px 4px; text-align: center; }
        .task-chip-list { display: flex; flex-wrap: wrap; gap: 8px; padding: 14px; }
        .task-chip { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 999px; color: #334155; font-size: 12px; padding: 7px 10px; }
        .task-chip strong { color: #0f172a; margin-left: 4px; }
        .text-purple { color: #7e22ce !important; }
        .text-teal { color: #0f766e !important; }
    </style>
</div>
