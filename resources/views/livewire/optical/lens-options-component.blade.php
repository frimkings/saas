@php
    $sections = [
        'design' => ['Lens types', 'Single vision is matched on CYL; bifocal and progressive per eye on ADD. Rename or hide them; new lens types can’t be added here.'],
        'form' => ['Forms', 'The form of a lens type, e.g. flat top or invisible bifocals, wider corridor progressives. Standard is the plain form of every lens type.'],
        'treatment' => ['Treatments', 'Coatings and treatments, named the way your shop does.'],
        'manufacturer' => ['Manufacturers', 'The makers and product lines you buy lenses from. Each manufacturer’s lenses are kept as their own stock.'],
    ];
@endphp
<div class="space-y-4">
    <x-ui.flash />
    @error('options')<div class="ui-panel p-3 text-sm text-red-700" role="alert">{{ $message }}</div>@enderror

    @foreach($sections as $kind => [$title, $help])
        @php $options = $kinds[$kind]['options']; $inUse = $kinds[$kind]['inUse']; @endphp
        <section class="ui-panel" aria-labelledby="lens-{{ $kind }}-title">
            <div class="border-b border-slate-200 px-4 py-3">
                <h2 id="lens-{{ $kind }}-title" class="text-sm font-bold text-slate-900">{{ $title }}</h2>
                <p class="ui-muted text-xs">{{ $help }} Renaming changes only the name shown; stock and past orders are unchanged.</p>
            </div>
            <ul class="divide-y divide-slate-100">
                @foreach($options as $option)
                    @php $standard = $kind === 'form' && $option->code === \App\Support\Optical\LensOptions::STANDARD_FORM; @endphp
                    <li wire:key="lens-option-{{ $option->id }}" class="flex flex-wrap items-center gap-2 px-4 py-2 text-sm {{ $option->is_active ? '' : 'bg-slate-50 text-slate-500' }}">
                        <span class="flex flex-col">
                            <button type="button" wire:click="move({{ $option->id }}, -1)" class="text-xs leading-none text-slate-400 hover:text-slate-700 disabled:opacity-30" @disabled($loop->first) aria-label="Move {{ $option->name }} up">▲</button>
                            <button type="button" wire:click="move({{ $option->id }}, 1)" class="text-xs leading-none text-slate-400 hover:text-slate-700 disabled:opacity-30" @disabled($loop->last) aria-label="Move {{ $option->name }} down">▼</button>
                        </span>
                        @if($editingId === $option->id)
                            <form wire:submit="saveName" class="flex flex-1 flex-wrap items-center gap-2">
                                <label for="lens-option-name-{{ $option->id }}" class="sr-only">Name</label>
                                <input id="lens-option-name-{{ $option->id }}" wire:model="editingName" maxlength="{{ $kind === 'manufacturer' ? 100 : 40 }}" class="ui-input !w-56 !py-1.5 text-sm" autofocus>
                                <button type="submit" class="ui-button ui-button-primary !py-1.5 text-xs">Save</button>
                                <button type="button" wire:click="cancelEdit" class="ui-button ui-button-secondary !py-1.5 text-xs">Cancel</button>
                                @error('editingName')<span class="w-full text-xs text-red-600">{{ $message }}</span>@enderror
                            </form>
                        @else
                            <span class="flex-1 font-medium">{{ $option->name }}
                                @if($kind === 'form')<span class="ml-1 text-xs font-normal text-slate-500">· {{ $option->design ? \App\Support\Optical\LensOptions::label('design', $option->design) : 'every lens type' }}</span>@endif
                                @if($option->code !== $option->name)<span class="ml-1 text-xs font-normal text-slate-400" title="Saved on stock as">{{ $option->code }}</span>@endif
                                @unless($option->is_active)<span class="ml-1 rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600">Hidden</span>@endunless
                            </span>
                            <button type="button" wire:click="edit({{ $option->id }})" class="ui-button ui-button-secondary !py-1 text-xs">Rename</button>
                            @unless($standard)<button type="button" wire:click="toggle({{ $option->id }})" class="ui-button ui-button-secondary !py-1 text-xs">{{ $option->is_active ? 'Hide' : 'Show' }}</button>@endunless
                            @if($kind !== 'design' && ! $standard)
                                @if($inUse[$option->id] ?? true)
                                    <span class="w-16 text-center text-[11px] text-slate-400" title="On lens stock or a price list">In use</span>
                                @else
                                    <button type="button" wire:click="delete({{ $option->id }})" wire:confirm="Delete “{{ $option->name }}”?" class="ui-button ui-button-secondary !py-1 text-xs text-red-700">Delete</button>
                                @endif
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
            @if($kind !== 'design')
                <form wire:submit="add('{{ $kind }}')" class="flex flex-wrap items-center gap-2 border-t border-slate-200 px-4 py-3">
                    <label for="new-{{ $kind }}" class="sr-only">New {{ $kind }}</label>
                    <input id="new-{{ $kind }}" wire:model="newName.{{ $kind }}" maxlength="{{ $kind === 'manufacturer' ? 100 : 40 }}"
                           placeholder="{{ ['form' => 'New form, e.g. Executive', 'treatment' => 'New treatment, e.g. Blue cut Photo AR', 'manufacturer' => 'New manufacturer, e.g. ESSILOR'][$kind] }}" class="ui-input !w-72 !py-1.5 text-sm">
                    @if($kind === 'form')
                        <label for="new-form-design" class="sr-only">Lens type</label>
                        <select id="new-form-design" wire:model="newFormDesign" class="ui-input !w-auto !py-1.5 text-sm">
                            @foreach(\App\Support\Optical\LensOptions::choices('design') as $code => $label)<option value="{{ $code }}">for {{ $label }}</option>@endforeach
                            <option value="">for every lens type</option>
                        </select>
                    @endif
                    <button type="submit" class="ui-button ui-button-primary !py-1.5 text-xs">+ Add</button>
                    @error('newName.'.$kind)<span class="w-full text-xs text-red-600">{{ $message }}</span>@enderror
                </form>
            @endif
        </section>
    @endforeach
</div>
