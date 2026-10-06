{{-- Searchable multi-select bound to a Livewire array property (replaces select2 on Tailwind pages).
     <x-ui.multi-select model="selectedDiagnoses" :options="$names" placeholder="Search…" id="diagnosis-select" /> --}}
@props(['model', 'options' => [], 'placeholder' => 'Search…', 'id' => null])
@php($id = $id ?? 'ms-'.\Illuminate\Support\Str::random(6))
<div wire:ignore {{ $attributes->class(['relative']) }}
     x-data="{
         selected: $wire.entangle('{{ $model }}'),
         options: @js(array_values(collect($options)->all())),
         q: '', open: false, active: 0,
         get chosen() { return Array.isArray(this.selected) ? this.selected : [] },
         get matches() {
             const term = this.q.trim().toLowerCase();
             return this.options.filter(o => !this.chosen.includes(o) && (!term || o.toLowerCase().includes(term))).slice(0, 50);
         },
         add(value) { if (!this.chosen.includes(value)) this.selected = [...this.chosen, value]; this.q = ''; this.active = 0; this.$refs.input.focus() },
         remove(value) { this.selected = this.chosen.filter(v => v !== value) },
     }"
     x-on:click.outside="open = false">
    <div class="flex min-h-[42px] cursor-text flex-wrap items-center gap-1 rounded-lg border border-slate-300 bg-white p-1.5 focus-within:border-teal-600 focus-within:ring-1 focus-within:ring-teal-600"
         x-on:click="$refs.input.focus()">
        <template x-for="value in chosen" :key="value">
            <span class="inline-flex items-center gap-1 rounded-md bg-teal-50 px-2 py-0.5 text-xs font-semibold text-teal-800">
                <span x-text="value"></span>
                <button type="button" class="leading-none text-teal-700 hover:text-teal-950" x-on:click.stop="remove(value)" :aria-label="'Remove ' + value">&times;</button>
            </span>
        </template>
        <input id="{{ $id }}" x-ref="input" type="text" x-model="q" autocomplete="off" placeholder="{{ $placeholder }}"
               role="combobox" aria-autocomplete="list" :aria-expanded="(open && matches.length > 0).toString()" aria-controls="{{ $id }}-list"
               class="min-w-[8rem] flex-1 border-0 p-1 text-sm focus:ring-0"
               x-on:focus="open = true" x-on:input="open = true; active = 0"
               x-on:keydown.arrow-down.prevent="open = true; active = Math.min(active + 1, matches.length - 1)"
               x-on:keydown.arrow-up.prevent="active = Math.max(active - 1, 0)"
               x-on:keydown.enter.prevent="matches[active] && add(matches[active])"
               x-on:keydown.backspace="!q && chosen.length && remove(chosen[chosen.length - 1])"
               x-on:keydown.escape.stop="open = false">
    </div>
    <div id="{{ $id }}-list" role="listbox" x-show="open && matches.length" x-cloak
         class="absolute z-30 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white text-sm shadow-lg">
        <template x-for="(option, index) in matches" :key="option">
            <button type="button" role="option" :aria-selected="(index === active).toString()" x-on:mousedown.prevent x-on:click="add(option)"
                    class="block w-full px-3 py-1.5 text-left hover:bg-slate-50" :class="index === active && 'bg-teal-50'" x-text="option"></button>
        </template>
    </div>
</div>
