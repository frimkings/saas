{{--
    A shift's colour: the swatches from ShiftCodes::COLOURS, or any other colour. $model is the
    property, $value what it holds now, $auto whether "Pick for me" (an empty value) is offered.
--}}
@php $palette = \App\Support\ShiftCodes::COLOURS; @endphp
<fieldset>
    <legend class="mb-1 block text-xs font-semibold text-slate-600">Colour</legend>
    <div class="flex flex-wrap items-center gap-1.5">
        @if($auto)
            <label class="cursor-pointer">
                <input type="radio" class="peer sr-only" value="" wire:model.live="{{ $model }}">
                <span class="inline-flex h-7 items-center rounded-md border border-dashed border-slate-400 bg-white px-2 text-xs text-slate-600 peer-checked:ring-2 peer-checked:ring-slate-800 peer-checked:ring-offset-1 peer-focus-visible:ring-2 peer-focus-visible:ring-teal-600">Pick for me</span>
            </label>
        @endif
        @foreach($palette as $hex => $name)
            <label class="cursor-pointer" title="{{ $name }}">
                <input type="radio" class="peer sr-only" value="{{ $hex }}" wire:model.live="{{ $model }}" aria-label="{{ $name }}">
                <span class="block h-7 w-7 rounded-md peer-checked:ring-2 peer-checked:ring-slate-800 peer-checked:ring-offset-1 peer-focus-visible:ring-2 peer-focus-visible:ring-teal-600" style="background-color:{{ $hex }}"></span>
            </label>
        @endforeach
        <label class="inline-flex h-7 cursor-pointer items-center gap-1 rounded-md border border-slate-300 bg-white px-1.5 text-xs text-slate-600" title="Another colour">
            <input type="color" class="h-5 w-6 cursor-pointer border-0 bg-transparent p-0" value="{{ $value !== '' ? $value : '#0d9488' }}" wire:model.live.debounce.300ms="{{ $model }}" aria-label="Another colour">
            Other
        </label>
    </div>
</fieldset>
