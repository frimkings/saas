<div class="{{ $optical ? 'ui-panel p-6 space-y-4 max-w-2xl' : 'card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm rounded-lg h-full' }}">
    @unless($optical)<div class="card-body p-4">@endunless

    <div class="{{ $optical ? '' : 'mb-4' }}">
        <h5 class="{{ $optical ? 'text-sm font-semibold text-slate-900' : 'font-semibold mb-1' }}">
            <i class="fas fa-wallet {{ $optical ? '' : 'mr-1 text-teal-700' }}"></i> Payment methods{{ $optical ? ' (optical)' : '' }}
        </h5>
        <p class="{{ $optical ? 'ui-muted text-xs' : 'text-sm text-slate-500 mb-0' }}">
            What the {{ $optical ? 'optical' : 'clinic (clearance, POS and outstanding balances)' }} tills offer.
            Switch off what you don't take, rename methods, or add your own (e.g. Zeepay). Past payments keep their method.
        </p>
    </div>

    @error('methods')<div class="{{ $optical ? 'text-xs text-red-600' : 'rounded-lg border px-3 py-2 text-sm border-red-200 bg-red-50 text-red-800 py-2 text-sm' }}">{{ $message }}</div>@enderror

    <table class="{{ $optical ? 'w-full text-sm' : 'table ui-table ui-table-sm mb-2' }}">
        <thead>
            <tr class="{{ $optical ? 'text-left text-xs text-slate-500' : 'text-sm text-slate-500' }}">
                <th style="width:60px;">On</th>
                <th>Name shown at the till</th>
                <th style="width:110px;"></th>
            </tr>
        </thead>
        <tbody>
            @foreach($methods as $i => $method)
                <tr wire:key="pm-{{ $method['key'] }}" class="{{ $method['is_active'] ? '' : ($optical ? 'opacity-50' : 'text-slate-500') }}">
                    <td class="align-middle">
                        <input type="checkbox" wire:model="methods.{{ $i }}.is_active" aria-label="Switch {{ $method['label'] }} on or off">
                    </td>
                    <td>
                        <input type="text" wire:model="methods.{{ $i }}.label" maxlength="40"
                               class="{{ $optical ? 'ui-input' : 'form-control ui-input ui-input-sm' }} @error('methods.'.$i.'.label') is-invalid @enderror">
                        @if(!$method['built_in'])
                            <small class="{{ $optical ? 'ui-muted text-xs' : 'text-slate-500' }}">Your own method</small>
                        @endif
                    </td>
                    <td class="align-middle text-right" style="white-space:nowrap;">
                        <button type="button" wire:click="move({{ $i }}, -1)" class="{{ $optical ? 'ui-button ui-button-secondary px-2 py-1' : 'btn ui-button ui-button-sm ui-button-secondary' }}" @disabled($loop->first) title="Move up">
                            <i class="fas fa-arrow-up"></i>
                        </button>
                        <button type="button" wire:click="move({{ $i }}, 1)" class="{{ $optical ? 'ui-button ui-button-secondary px-2 py-1' : 'btn ui-button ui-button-sm ui-button-secondary' }}" @disabled($loop->last) title="Move down">
                            <i class="fas fa-arrow-down"></i>
                        </button>
                        @if(!$method['built_in'] && !$method['used'])
                            <button type="button" wire:click="remove({{ $i }})" class="{{ $optical ? 'ui-button ui-button-secondary px-2 py-1 text-red-600' : 'btn ui-button ui-button-sm ui-button-danger' }}" title="Remove">
                                <i class="fas fa-trash"></i>
                            </button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="{{ $optical ? 'flex gap-2 items-start' : 'flex items-start mb-4' }}" style="gap:8px;">
        <div class="{{ $optical ? 'flex-1' : 'grow' }}">
            <input type="text" wire:model="newLabel" wire:keydown.enter.prevent="add" maxlength="40" placeholder="Add a method, e.g. Zeepay"
                   class="{{ $optical ? 'ui-input' : 'form-control ui-input ui-input-sm' }} @error('newLabel') is-invalid @enderror">
            @error('newLabel')<div class="{{ $optical ? 'text-xs text-red-600' : 'ui-error block' }}">{{ $message }}</div>@enderror
        </div>
        <button type="button" wire:click="add" class="{{ $optical ? 'ui-button ui-button-secondary' : 'btn ui-button ui-button-sm ui-button-secondary' }}">
            <i class="fas fa-plus"></i> Add
        </button>
    </div>

    <button type="button" wire:click="save" class="{{ $optical ? 'ui-button ui-button-primary' : 'btn ui-button ui-button-primary ui-button-sm' }}">
        <i class="fas fa-save"></i> Save payment methods
    </button>

    @unless($optical)</div>@endunless
</div>
