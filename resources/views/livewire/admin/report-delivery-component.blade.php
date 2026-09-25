<div class="container-fluid py-4">

    {{-- Page header --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 class="mb-1"><i class="fas fa-envelope-open-text mr-2 text-primary"></i>Report Delivery</h2>
            <p class="text-muted mb-0">Automatically email a financial summary on a daily or weekly schedule.</p>
        </div>
        <div class="d-flex" style="gap:.5rem">
            <button type="button"
                    wire:click="retryPending"
                    wire:loading.attr="disabled"
                    wire:target="retryPending"
                    class="btn btn-outline-primary font-weight-bold">
                <span wire:loading.remove wire:target="retryPending">
                    <i class="fas fa-redo mr-1"></i> Retry Pending
                    @if($pendingDeliveriesCount > 0)
                        <span class="badge badge-primary ml-1">{{ $pendingDeliveriesCount }}</span>
                    @endif
                </span>
                <span wire:loading wire:target="retryPending">
                    <i class="fas fa-spinner fa-spin mr-1"></i> Retrying...
                </span>
            </button>
            <button type="button"
                    wire:click="sendNow"
                    wire:loading.attr="disabled"
                    wire:target="sendNow"
                    class="btn btn-primary font-weight-bold">
                <span wire:loading.remove wire:target="sendNow">
                    <i class="fas fa-paper-plane mr-1"></i> Send Current Report
                </span>
                <span wire:loading wire:target="sendNow">
                    <i class="fas fa-spinner fa-spin mr-1"></i> Sending…
                </span>
            </button>
            <button type="button"
                    wire:click="save"
                    wire:loading.attr="disabled"
                    wire:target="save"
                    class="btn btn-primary font-weight-bold">
                <span wire:loading.remove wire:target="save">
                    <i class="fas fa-save mr-1"></i> Save Schedule
                </span>
                <span wire:loading wire:target="save">
                    <i class="fas fa-spinner fa-spin mr-1"></i> Saving…
                </span>
            </button>
        </div>
    </div>

    <div class="row">

        {{-- Left: Schedule settings --}}
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3 font-weight-bold">
                    <i class="fas fa-clock mr-1 text-primary"></i> Schedule
                </div>
                <div class="card-body">

                    {{-- Enable toggle --}}
                    <div class="d-flex align-items-center justify-content-between p-3 rounded mb-4"
                         style="background:{{ $enabled ? '#f0fdf4' : '#f9fafb' }};border:1px solid {{ $enabled ? '#bbf7d0' : '#e5e7eb' }}">
                        <div>
                            <div class="font-weight-bold">Automatic Delivery</div>
                            <div class="text-muted small">
                                {{ $enabled ? 'Reports are being sent automatically.' : 'Automatic delivery is off.' }}
                            </div>
                        </div>
                        <div class="custom-control custom-switch ml-3">
                            <input type="checkbox"
                                   class="custom-control-input"
                                   id="reportEnabled"
                                   wire:model.live="enabled">
                            <label class="custom-control-label" for="reportEnabled"></label>
                        </div>
                    </div>

                    {{-- Frequency --}}
                    <div class="form-group">
                        <label class="font-weight-bold small text-muted text-uppercase" style="letter-spacing:.05em">
                            Frequency
                        </label>
                        <div class="d-flex" style="gap:.75rem">
                            <label class="flex-fill mb-0" style="cursor:pointer">
                                <div class="p-3 rounded border text-center {{ $frequency === 'daily' ? 'border-primary bg-primary text-white' : 'bg-light' }}"
                                     wire:click="$set('frequency','daily')"
                                     style="cursor:pointer;transition:all .15s">
                                    <i class="fas fa-sun d-block mb-1" style="font-size:1.3rem"></i>
                                    <span class="font-weight-bold small">Daily</span>
                                </div>
                            </label>
                            <label class="flex-fill mb-0" style="cursor:pointer">
                                <div class="p-3 rounded border text-center {{ $frequency === 'weekly' ? 'border-primary bg-primary text-white' : 'bg-light' }}"
                                     wire:click="$set('frequency','weekly')"
                                     style="cursor:pointer;transition:all .15s">
                                    <i class="fas fa-calendar-week d-block mb-1" style="font-size:1.3rem"></i>
                                    <span class="font-weight-bold small">Weekly</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    {{-- Day of week (weekly only) --}}
                    @if($frequency === 'weekly')
                    <div class="form-group">
                        <label class="font-weight-bold small text-muted text-uppercase" style="letter-spacing:.05em">
                            Day of Week
                        </label>
                        <select wire:model.live="day" class="form-control @error('day') is-invalid @enderror">
                            <option value="0">Sunday</option>
                            <option value="1">Monday</option>
                            <option value="2">Tuesday</option>
                            <option value="3">Wednesday</option>
                            <option value="4">Thursday</option>
                            <option value="5">Friday</option>
                            <option value="6">Saturday</option>
                        </select>
                        @error('day')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @endif

                    {{-- Send time --}}
                    <div class="form-group mb-0">
                        <label class="font-weight-bold small text-muted text-uppercase" style="letter-spacing:.05em">
                            Send Time
                        </label>
                        <div class="input-group" style="max-width:200px">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-clock text-muted"></i></span>
                            </div>
                            <input type="time"
                                   wire:model.live="time"
                                   class="form-control @error('time') is-invalid @enderror">
                            @error('time')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <small class="text-muted d-block mt-1">
                            Based on the server's local time (Windows Task Scheduler must be running).
                        </small>
                    </div>

                </div>
            </div>
        </div>

        {{-- Right: Recipients --}}
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3 font-weight-bold">
                    <i class="fas fa-users mr-1 text-success"></i> Recipients
                    <span class="badge badge-secondary ml-1">{{ count($recipients) }}</span>
                </div>
                <div class="card-body">

                    {{-- Add recipient --}}
                    <div class="input-group mb-3">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fas fa-at text-muted"></i></span>
                        </div>
                        <input type="email"
                               wire:model="newRecipient"
                               wire:keydown.enter="addRecipient"
                               class="form-control @error('newRecipient') is-invalid @enderror"
                               placeholder="name@example.com">
                        <div class="input-group-append">
                            <button type="button"
                                    wire:click="addRecipient"
                                    class="btn btn-success font-weight-bold">
                                <i class="fas fa-plus mr-1"></i> Add
                            </button>
                        </div>
                        @error('newRecipient')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Recipient list --}}
                    @forelse($recipients as $index => $email)
                        <div wire:key="recipient-{{ $index }}"
                             class="d-flex align-items-center p-2 mb-2 rounded"
                             style="background:#f8f9fa;border:1px solid #e9ecef">
                            <div class="recipient-avatar mr-3 d-flex align-items-center justify-content-center rounded-circle"
                                 style="width:34px;height:34px;background:#dbeafe;color:#1d4ed8;font-size:.75rem;font-weight:bold;flex-shrink:0">
                                {{ strtoupper(substr($email, 0, 1)) }}
                            </div>
                            <span class="small flex-grow-1 text-truncate">{{ $email }}</span>
                            <button type="button"
                                    wire:click="removeRecipient({{ $index }})"
                                    wire:confirm="Remove {{ $email }} from recipients?"
                                    class="btn btn-sm btn-outline-danger ml-2 flex-shrink-0"
                                    title="Remove">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    @empty
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-inbox fa-2x d-block mb-2" style="opacity:.3"></i>
                            <span class="small">No recipients yet. Add at least one email address.</span>
                        </div>
                    @endforelse

                </div>
            </div>
        </div>

    </div>

    {{-- Delivery outbox --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <div class="font-weight-bold">
                <i class="fas fa-paper-plane mr-1 text-primary"></i> Delivery Outbox
                @if($pendingDeliveriesCount > 0)
                    <span class="badge badge-warning ml-1">{{ $pendingDeliveriesCount }} pending</span>
                @endif
            </div>
            <small class="text-muted">Failed reports retry automatically every minute while the scheduler is running.</small>
        </div>
        <div class="card-body p-0">
            @if($recentDeliveries->count())
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Report</th>
                                <th>Status</th>
                                <th>Recipients</th>
                                <th>Attempts</th>
                                <th>Last Attempt</th>
                                <th>Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentDeliveries as $delivery)
                                @php
                                    $statusClass = [
                                        'sent' => 'success',
                                        'failed' => 'danger',
                                        'pending' => 'warning',
                                    ][$delivery->status] ?? 'secondary';
                                    $sentCount = count($delivery->sent_recipients ?? []);
                                    $recipientCount = count($delivery->recipients ?? []);
                                @endphp
                                <tr>
                                    <td>
                                        <div class="font-weight-bold small">{{ $delivery->subject }}</div>
                                        <div class="text-muted small">{{ $delivery->period_start?->format('d M Y') }} - {{ $delivery->period_end?->format('d M Y') }}</div>
                                    </td>
                                    <td><span class="badge badge-{{ $statusClass }}">{{ ucfirst($delivery->status) }}</span></td>
                                    <td class="small">{{ $sentCount }}/{{ $recipientCount }} sent</td>
                                    <td class="small">{{ $delivery->attempts }}</td>
                                    <td class="small text-muted">{{ $delivery->last_attempt_at ? $delivery->last_attempt_at->format('d M h:i A') : 'Not tried yet' }}</td>
                                    <td class="small text-muted" style="max-width:260px">{{ \Illuminate\Support\Str::limit($delivery->last_error ?? '', 90) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox d-block mb-2" style="font-size:1.5rem;opacity:.35"></i>
                    <span class="small">No report deliveries have been queued yet.</span>
                </div>
            @endif
        </div>
    </div>

    {{-- Preview card --}}
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 font-weight-bold">
            <i class="fas fa-eye mr-1 text-info"></i> What the report includes
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-2 col-4 mb-3">
                    <div class="p-3 rounded" style="background:#eff6ff">
                        <i class="fas fa-peso-sign text-primary d-block mb-1" style="font-size:1.3rem"></i>
                        <div class="small font-weight-bold">Gross Revenue</div>
                    </div>
                </div>
                <div class="col-md-2 col-4 mb-3">
                    <div class="p-3 rounded" style="background:#f0fdf4">
                        <i class="fas fa-chart-line text-success d-block mb-1" style="font-size:1.3rem"></i>
                        <div class="small font-weight-bold">Net Revenue</div>
                    </div>
                </div>
                <div class="col-md-2 col-4 mb-3">
                    <div class="p-3 rounded" style="background:#faf5ff">
                        <i class="fas fa-receipt text-purple d-block mb-1" style="font-size:1.3rem"></i>
                        <div class="small font-weight-bold">Transactions</div>
                    </div>
                </div>
                <div class="col-md-2 col-4 mb-3">
                    <div class="p-3 rounded" style="background:#fff7ed">
                        <i class="fas fa-hourglass-half text-warning d-block mb-1" style="font-size:1.3rem"></i>
                        <div class="small font-weight-bold">Outstanding</div>
                    </div>
                </div>
                <div class="col-md-2 col-4 mb-3">
                    <div class="p-3 rounded" style="background:#fef2f2">
                        <i class="fas fa-undo text-danger d-block mb-1" style="font-size:1.3rem"></i>
                        <div class="small font-weight-bold">Refunds</div>
                    </div>
                </div>
                <div class="col-md-2 col-4 mb-3">
                    <div class="p-3 rounded" style="background:#ecfdf5">
                        <i class="fas fa-tags text-success d-block mb-1" style="font-size:1.3rem"></i>
                        <div class="small font-weight-bold">Discounts</div>
                    </div>
                </div>
            </div>
            <p class="text-muted small mb-0 text-center mt-1">
                <i class="fas fa-info-circle mr-1"></i>
                Scheduled reports cover yesterday (daily) or last full week (weekly).
                Click <strong>Send Current Report</strong> to send today's data immediately without waiting for the schedule.
            </p>
        </div>
    </div>

</div>
