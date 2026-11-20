{{--
    A labelled dropdown you can type into to filter, bound to one Livewire property (the single-choice
    partner of x-ui.multi-select). options: [value => label]. Typing filters; arrows and Enter pick; Esc or
    leaving the field puts back the chosen name.

        <x-ui.search-select label="Optical centre" name="partnerId" :options="$centres" required />

    live: a filter, so picking sends the value at once. bare: the page styles the field itself (pass class=,
    e.g. "so-field"); the label is a <span> then.
--}}
@props(['label', 'name', 'model' => null, 'options' => [], 'placeholder' => 'Type to search…', 'id' => null, 'required' => false, 'live' => false, 'bare' => false])
@php
    $errors ??= new \Illuminate\Support\ViewErrorBag;
    $id = $id ?? 'ui-'.str_replace(['.', '[', ']'], '-', $name);
    $list = collect($options)->map(fn ($text, $value) => ['v' => $value, 'l' => (string) $text])->values()->all();
@endphp
<div {{ $attributes->class([$bare ? '' : 'ui-field']) }}>
@if($bare)<span id="{{ $id }}-label">{{ $label }}</span>@else<label for="{{ $id }}">{{ $label }}@if($required)<span class="text-red-700" aria-hidden="true"> *</span>@endif</label>@endif
<div wire:ignore class="relative"
     x-data="{
         value: $wire.entangle('{{ $model ?? $name }}'){{ $live ? '.live' : '' }},
         options: @js($list),
         q: '', open: false, active: 0,
         labelFor(v) { const o = this.options.find(o => String(o.v) === String(v)); return o ? o.l : '' },
         get typing() { return this.q !== this.labelFor(this.value) },
         get matches() {
             const term = this.typing ? this.q.trim().toLowerCase() : '';
             return this.options.filter(o => !term || o.l.toLowerCase().includes(term)).slice(0, 50);
         },
         pick(o) { this.value = o.v; this.q = o.l; this.open = false },
         restore() { this.open = false; this.q = this.labelFor(this.value) },
         init() { this.q = this.labelFor(this.value); this.$watch('value', v => { if (!this.open) this.q = this.labelFor(v) }) },
     }"
     x-on:click.outside="open && restore()">
    <input id="{{ $id }}" @if($bare) aria-labelledby="{{ $id }}-label" @endif x-model="q" type="text" autocomplete="off" placeholder="{{ $placeholder }}"
           role="combobox" aria-autocomplete="list" :aria-expanded="open.toString()" aria-controls="{{ $id }}-list"
           aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}" @if($errors->has($name)) aria-describedby="{{ $id }}-error" @endif
           class="ui-input w-full pr-8"
           x-on:focus="open = true; $el.select()" x-on:input="open = true; active = 0"
           x-on:keydown.arrow-down.prevent="open = true; active = Math.min(active + 1, matches.length - 1)"
           x-on:keydown.arrow-up.prevent="active = Math.max(active - 1, 0)"
           x-on:keydown.enter.prevent="matches[active] && pick(matches[active])"
           x-on:keydown.escape.stop="restore()" x-on:keydown.tab="restore()">
    <i class="fas fa-chevron-down pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
    <div id="{{ $id }}-list" role="listbox" x-show="open" x-cloak
         class="absolute z-30 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white text-sm shadow-lg">
        <template x-for="(option, index) in matches" :key="option.v">
            <button type="button" role="option" :aria-selected="(String(option.v) === String(value)).toString()" x-on:mousedown.prevent x-on:click="pick(option)"
                    class="block w-full px-3 py-1.5 text-left hover:bg-slate-50" :class="{ 'bg-teal-50': index === active, 'font-semibold text-teal-800': String(option.v) === String(value) }" x-text="option.l"></button>
        </template>
        <p x-show="!matches.length" class="px-3 py-2 text-xs text-slate-500">No match.</p>
    </div>
</div>
@error($name)<p id="{{ $id }}-error" class="ui-error" role="alert">{{ $message }}</p>@enderror
</div>
