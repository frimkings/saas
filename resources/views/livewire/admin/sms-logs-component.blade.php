<div class="container-fluid py-4">

    {{-- Header + summary cards --}}
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h5 class="font-weight-bold mb-0"><i class="fas fa-comment-dots text-primary mr-2"></i> SMS Delivery Logs</h5>
            <small class="text-muted">Every outgoing SMS attempt is recorded here.</small>
        </div>
        <ul class="nav nav-tabs border-bottom-0 mb-0">
            <li class="nav-item">
                <a href="#" wire:click.prevent="$set('showArchive', false)"
                   class="nav-link {{ !$showArchive ? 'active font-weight-bold' : 'text-muted' }}">
                    <i class="fas fa-list mr-1"></i> Active
                </a>
            </li>
            <li class="nav-item">
                <a href="#" wire:click.prevent="$set('showArchive', true)"
                   class="nav-link {{ $showArchive ? 'active font-weight-bold' : 'text-muted' }}">
                    <i class="fas fa-archive mr-1"></i> Archived
                </a>
            </li>
        </ul>
    </div>

    @if(!$showArchive)
    <div class="row mb-4">
        <div class="col-sm-4">
            <div class="info-box mb-0 shadow-sm border-0">
                <span class="info-box-icon bg-secondary"><i class="fas fa-paper-plane"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Total Sent</span>
                    <span class="info-box-number">{{ number_format($totals['total']) }}</span>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="info-box mb-0 shadow-sm border-0">
                <span class="info-box-icon bg-success"><i class="fas fa-check"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Sent</span>
                    <span class="info-box-number">{{ number_format($totals['success']) }}</span>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="info-box mb-0 shadow-sm border-0">
                <span class="info-box-icon bg-danger"><i class="fas fa-times"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Failed</span>
                    <span class="info-box-number">{{ number_format($totals['failed']) }}</span>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Filters --}}
    <div class="card border-0 shadow-sm rounded-lg mb-4">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <input type="text" wire:model.live.debounce.300ms="search"
                           class="form-control form-control-sm bg-light border-0"
                           placeholder="Search name, phone, message…">
                </div>
                <div class="col-md-2">
                    <select wire:model.live="filterStatus" class="form-control form-control-sm bg-light border-0">
                        <option value="">All statuses</option>
                        <option value="sent">Sent</option>
                        <option value="queued">Queued</option>
                        <option value="failed">Failed</option>
                        <option value="skipped">Skipped (opted out)</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <select wire:model.live="filterChannel" class="form-control form-control-sm bg-light border-0">
                        <option value="">All channels</option>
                        <option value="sms">SMS</option>
                        <option value="whatsapp">WhatsApp</option>
                    </select>
                </div>
                @if($branches->count() > 1)
                <div class="col-md-2">
                    <select wire:model.live="filterBranch" class="form-control form-control-sm bg-light border-0">
                        <option value="">All branches</option>
                        @foreach($branches as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="col-md-2">
                    <select wire:model.live="filterTemplate" class="form-control form-control-sm bg-light border-0">
                        <option value="">All templates</option>
                        @foreach($templates as $tpl)
                            <option value="{{ $tpl }}">{{ ucwords(str_replace('_', ' ', $tpl)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3"><x-date-range from="dateFrom" to="dateTo" presets="activity" clearable /></div>
                <div class="col-md-1">
                    <button wire:click="clearFilters" class="btn btn-sm btn-outline-secondary w-100"
                            title="Clear filters"><i class="fas fa-times"></i></button>
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="card border-0 shadow-sm rounded-lg">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th style="width:140px;">Date / Time</th>
                            <th>Patient</th>
                            <th>Recipient</th>
                            <th>Template</th>
                            @if($branches->count() > 1)<th>Branch</th>@endif
                            <th>Message</th>
                            <th style="width:90px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs as $log)
                            <tr>
                                <td class="text-muted small align-middle text-nowrap">
                                    {{ $log->created_at->format('d M Y') }}<br>
                                    <span class="text-muted" style="font-size:0.75rem;">{{ $log->created_at->format('h:i A') }}</span>
                                </td>
                                <td class="align-middle">
                                    @if($log->patient)
                                        <span class="font-weight-bold">{{ $log->patient->name }}</span>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                                <td class="align-middle small text-nowrap">{{ $log->recipient }}</td>
                                <td class="align-middle">
                                    @if($log->template_key)
                                        <span class="badge badge-light border small">
                                            {{ ucwords(str_replace('_', ' ', $log->template_key)) }}
                                        </span>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                                @if($branches->count() > 1)
                                    <td class="align-middle small">{{ $log->branch?->name ?? '—' }}</td>
                                @endif
                                <td class="align-middle small" style="max-width:300px;">
                                    <span class="d-inline-block text-truncate" style="max-width:280px;"
                                          title="{{ $log->message }}">{{ $log->message }}</span>
                                    @if($log->error)
                                        <br><span class="text-danger" style="font-size:0.75rem;">
                                            <i class="fas fa-exclamation-circle mr-1"></i>{{ $log->error }}
                                        </span>
                                    @endif
                                </td>
                                <td class="align-middle text-center">
                                    @switch($log->status)
                                        @case('sent')
                                            <span class="badge badge-success">Sent</span>
                                            @break
                                        @case('queued')
                                            <span class="badge badge-info">Queued</span>
                                            @break
                                        @case('skipped')
                                            <span class="badge badge-secondary">Skipped</span>
                                            @break
                                        @default
                                            <span class="badge badge-danger">Failed</span>
                                    @endswitch
                                    <div class="text-muted" style="font-size:0.7rem;">
                                        {{ $log->channel === 'whatsapp' ? 'WhatsApp' : 'SMS · ' . ($log->segments ?? 1) . ' ' . \Illuminate\Support\Str::plural('credit', $log->segments ?? 1) }}
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $branches->count() > 1 ? 7 : 6 }}" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x d-block mb-2 text-light"></i>
                                    No SMS logs found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($logs->hasPages())
            <div class="card-footer bg-white border-0 py-2">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

</div>
