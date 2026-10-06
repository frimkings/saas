<div class="clinic-ui ui-page">

    {{-- Header + summary cards --}}
    <div class="flex items-center justify-between mb-4">
        <div>
            <h5 class="font-semibold mb-0"><i class="fas fa-comment-dots text-teal-700 mr-2"></i> SMS Delivery Logs</h5>
            <small class="text-slate-500">Every outgoing SMS attempt is recorded here.</small>
        </div>
        <ul class="flex flex-wrap border-b border-slate-200 border-b-0 mb-0">
            <li class="">
                <a href="#" wire:click.prevent="$set('showArchive', false)"
                   class="block px-3 py-2 {{ !$showArchive ? 'active font-semibold' : 'text-slate-500' }}">
                    <i class="fas fa-list mr-1"></i> Active
                </a>
            </li>
            <li class="">
                <a href="#" wire:click.prevent="$set('showArchive', true)"
                   class="block px-3 py-2 {{ $showArchive ? 'active font-semibold' : 'text-slate-500' }}">
                    <i class="fas fa-archive mr-1"></i> Archived
                </a>
            </li>
        </ul>
    </div>

    @if(!$showArchive)
    <div class="flex flex-wrap -mx-2 mb-6">
        <div class="w-full sm:w-4/12 px-2">
            <div class="info-box mb-0 shadow-sm border-0">
                <span class="info-box-icon bg-slate-500 text-white"><i class="fas fa-paper-plane"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Total Sent</span>
                    <span class="info-box-number">{{ number_format($totals['total']) }}</span>
                </div>
            </div>
        </div>
        <div class="w-full sm:w-4/12 px-2">
            <div class="info-box mb-0 shadow-sm border-0">
                <span class="info-box-icon bg-green-600 text-white"><i class="fas fa-check"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Sent</span>
                    <span class="info-box-number">{{ number_format($totals['success']) }}</span>
                </div>
            </div>
        </div>
        <div class="w-full sm:w-4/12 px-2">
            <div class="info-box mb-0 shadow-sm border-0">
                <span class="info-box-icon bg-red-600 text-white"><i class="fas fa-times"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Failed</span>
                    <span class="info-box-number">{{ number_format($totals['failed']) }}</span>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Filters --}}
    <div class="card overflow-hidden border border-slate-200 bg-white border-0 shadow-sm rounded-lg mb-6">
        <div class="card-body p-4 py-4">
            <div class="flex flex-wrap -mx-2 items-end">
                <div class="w-full md:w-2/12 px-2">
                    <input type="text" wire:model.live.debounce.300ms="search"
                           class="form-control ui-input ui-input-sm bg-slate-50 border-0"
                           placeholder="Search name, phone, message…">
                </div>
                <div class="w-full md:w-2/12 px-2">
                    <select wire:model.live="filterStatus" class="form-control ui-input ui-input-sm bg-slate-50 border-0">
                        <option value="">All statuses</option>
                        <option value="sent">Sent</option>
                        <option value="queued">Queued</option>
                        <option value="failed">Failed</option>
                        <option value="skipped">Skipped (opted out)</option>
                    </select>
                </div>
                <div class="w-full md:w-1/12 px-2">
                    <select wire:model.live="filterChannel" class="form-control ui-input ui-input-sm bg-slate-50 border-0">
                        <option value="">All channels</option>
                        <option value="sms">SMS</option>
                        <option value="whatsapp">WhatsApp</option>
                    </select>
                </div>
                @if($branches->count() > 1)
                <div class="w-full md:w-2/12 px-2">
                    <select wire:model.live="filterBranch" class="form-control ui-input ui-input-sm bg-slate-50 border-0">
                        <option value="">All branches</option>
                        @foreach($branches as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="w-full md:w-2/12 px-2">
                    <select wire:model.live="filterTemplate" class="form-control ui-input ui-input-sm bg-slate-50 border-0">
                        <option value="">All templates</option>
                        @foreach($templates as $tpl)
                            <option value="{{ $tpl }}">{{ ucwords(str_replace('_', ' ', $tpl)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full md:w-3/12 px-2"><x-date-range from="dateFrom" to="dateTo" presets="activity" clearable /></div>
                <div class="w-full md:w-1/12 px-2">
                    <button wire:click="clearFilters" class="btn ui-button ui-button-sm ui-button-secondary w-full"
                            title="Clear filters"><i class="fas fa-times"></i></button>
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="card overflow-hidden border border-slate-200 bg-white border-0 shadow-sm rounded-lg">
        <div class="card-body p-0">
            <div class="ui-table-wrap">
                <table class="table ui-table ui-table-sm mb-0">
                    <thead class="">
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
                                <td class="text-slate-500 text-sm align-middle whitespace-nowrap">
                                    {{ $log->created_at->format('d M Y') }}<br>
                                    <span class="text-slate-500" style="font-size:0.75rem;">{{ $log->created_at->format('h:i A') }}</span>
                                </td>
                                <td class="align-middle">
                                    @if($log->patient)
                                        <span class="font-semibold">{{ $log->patient->name }}</span>
                                    @else
                                        <span class="text-slate-500 text-sm">—</span>
                                    @endif
                                </td>
                                <td class="align-middle text-sm whitespace-nowrap">{{ $log->recipient }}</td>
                                <td class="align-middle">
                                    @if($log->template_key)
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600 border border-slate-200 text-sm">
                                            {{ ucwords(str_replace('_', ' ', $log->template_key)) }}
                                        </span>
                                    @else
                                        <span class="text-slate-500 text-sm">—</span>
                                    @endif
                                </td>
                                @if($branches->count() > 1)
                                    <td class="align-middle text-sm">{{ $log->branch?->name ?? '—' }}</td>
                                @endif
                                <td class="align-middle text-sm" style="max-width:300px;">
                                    <span class="inline-block truncate" style="max-width:280px;"
                                          title="{{ $log->message }}">{{ $log->message }}</span>
                                    @if($log->error)
                                        <br><span class="text-red-700" style="font-size:0.75rem;">
                                            <i class="fas fa-exclamation-circle mr-1"></i>{{ $log->error }}
                                        </span>
                                    @endif
                                </td>
                                <td class="align-middle text-center">
                                    @switch($log->status)
                                        @case('sent')
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800">Sent</span>
                                            @break
                                        @case('queued')
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-sky-100 text-sky-800">Queued</span>
                                            @break
                                        @case('skipped')
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700">Skipped</span>
                                            @break
                                        @default
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800">Failed</span>
                                    @endswitch
                                    <div class="text-slate-500" style="font-size:0.7rem;">
                                        {{ $log->channel === 'whatsapp' ? 'WhatsApp' : 'SMS · ' . ($log->segments ?? 1) . ' ' . \Illuminate\Support\Str::plural('credit', $log->segments ?? 1) }}
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $branches->count() > 1 ? 7 : 6 }}" class="text-center text-slate-500 py-6">
                                    <i class="fas fa-inbox fa-2x block mb-2 text-slate-100"></i>
                                    No SMS logs found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($logs->hasPages())
            <div class="border-t border-slate-200 px-4 bg-white border-0 py-2">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

</div>
