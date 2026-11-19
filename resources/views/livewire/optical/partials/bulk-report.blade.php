{{-- What the last bulk action did: $report = ['title', 'done' => [labels], 'skipped' => [reasons], 'note' => ?, 'link' => ?, 'linkLabel' => ?]. --}}
@if($report)
    <div class="oo-note {{ $report['done'] ? '' : 'amber' }}" role="status" data-bulk-report
         style="{{ $report['done'] ? 'background:#ecfdf5;border-color:#a7f3d0;color:#065f46' : '' }}">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <b>{{ $report['title'] }}:</b>
                {{ count($report['done']) }} done{{ $report['done'] ? ' ('.implode(', ', array_slice($report['done'], 0, 20)).(count($report['done']) > 20 ? ', …' : '').')' : '' }}.
                @if(! empty($report['note'])) {{ $report['note'] }}@endif
                @if($report['skipped'])
                    <div class="mt-1" style="color:#92400e"><b>{{ count($report['skipped']) }} skipped</b> (still ticked):
                        <ul style="margin:4px 0 0;padding-left:18px">@foreach($report['skipped'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                    </div>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach((array) ($report['links'] ?? []) as $link)
                    <a href="{{ $link['url'] }}" target="_blank" rel="noopener" class="oo-btn wa"><i class="fab fa-whatsapp" aria-hidden="true"></i> {{ $link['label'] }}</a>
                @endforeach
                <button type="button" class="oo-btn" wire:click="$set('bulkReport', null)">Close</button>
            </div>
        </div>
    </div>
@endif
