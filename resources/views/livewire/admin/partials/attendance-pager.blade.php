{{-- Long lists of people come a page at a time. $pager = [page, pages, total, from, to]; $model is the page property to set. --}}
@if($pager['pages'] > 1)
    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-3 py-2 text-xs text-slate-600">
        <span>Showing {{ number_format($pager['from']) }} to {{ number_format($pager['to']) }} of {{ number_format($pager['total']) }} people. Search by name or pick a department to narrow it.</span>
        <span class="inline-flex items-center gap-1">
            <button type="button" class="ui-button ui-button-secondary !px-2.5 !py-1 !text-xs" wire:click="$set('{{ $model }}', {{ $pager['page'] - 1 }})" @disabled($pager['page'] <= 1)>Previous</button>
            <span class="px-1 tabular-nums">Page {{ $pager['page'] }} of {{ $pager['pages'] }}</span>
            <button type="button" class="ui-button ui-button-secondary !px-2.5 !py-1 !text-xs" wire:click="$set('{{ $model }}', {{ $pager['page'] + 1 }})" @disabled($pager['page'] >= $pager['pages'])>Next</button>
        </span>
    </div>
@endif
