{{--
    The line under a prescribed drug: its form, dose and meal timing (24 Nov 2026), the Taper tick, a free-text
    note and quick picks. Needs $list ('products' dispensed here, or 'outside' bought at a pharmacy), $index,
    $line, $locked, $colspan.
--}}
@php
    $model = ($list === 'outside' ? 'outsideDrugs' : 'productsList') . '.' . $index;
    $taper = (bool) ($line['taper'] ?? false);
    $note = trim((string) ($line['instructions'] ?? ''));
    $form = \App\Support\Clinical\DrugCourse::form($line);
    $doseText = $form?->asksDose() ? $form->doseLabel($line['dose'] ?? null) : null;
    $meal = \App\Enums\MealTiming::tryFrom((string) ($line['meal_timing'] ?? ''));
@endphp
@if(!$locked || $taper || $note !== '' || $doseText || $meal)
    <tr class="drug-note-row {{ $locked ? 'table-secondary' : '' }}" wire:key="{{ $list }}-note-{{ $line['cart_id'] ?? $line['id'] ?? 'new' }}-{{ $index }}">
        <td class="py-0 px-1"></td>
        <td colspan="{{ $colspan }}" class="pt-0 pb-1.5 px-2" style="border-top: 0;">
            @if($locked)
                <div class="flex flex-wrap items-start gap-1.5" style="font-size: 11px;">
                    @if($doseText)<span class="text-slate-600">{{ $doseText }}</span>@endif
                    @if($meal)<span class="text-slate-600">· {{ $meal->label() }}</span>@endif
                    @if($taper)<span class="inline-flex items-center rounded px-1.5 py-0.5 font-bold bg-amber-100 text-amber-800" style="font-size: 9px;">TAPER</span>@endif
                    @if($note !== '')<span class="text-slate-600"><i class="fas fa-sticky-note text-slate-400"></i> {{ $note }}</span>@endif
                </div>
            @else
                <div x-data="{ picks: false }">
                    <div class="flex flex-wrap items-center gap-1.5">
                        {{-- Form: set here when the product has none (it is saved on the product too), or corrected for this line. --}}
                        <select class="ui-input ui-input-sm !w-auto {{ $form ? '' : 'border-amber-400' }}" style="font-size: 11px; min-width: 104px;"
                                wire:change="setLineForm('{{ $list }}', {{ $index }}, $event.target.value)" aria-label="Form of {{ $line['name'] ?? 'this drug' }}"
                                title="{{ $form ? 'What form this drug comes in' : 'Form not set: choose it so the line asks the right questions' }}">
                            <option value="" @selected(!$form)>Form?</option>
                            @foreach(\App\Enums\DosageForm::options() as $value => $label)
                                <option value="{{ $value }}" @selected($form?->value === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @if($form?->asksDose())
                            <label class="inline-flex items-center gap-1 text-slate-600" style="font-size: 10px;">
                                Dose
                                <input type="number" min="0.25" max="9999" step="0.25" wire:model.blur="{{ $model }}.dose"
                                    class="ui-input ui-input-sm text-center !w-14 {{ (float) ($line['dose'] ?? 0) > 0 || $form === \App\Enums\DosageForm::INJECTION ? '' : 'border-amber-400' }}"
                                    aria-label="Dose of {{ $line['name'] ?? 'this drug' }}">
                                {{ in_array($form, [\App\Enums\DosageForm::SYRUP, \App\Enums\DosageForm::INJECTION], true) ? 'ml' : rtrim($form->unitPlural(), 's') . '(s)' }}
                            </label>
                        @endif
                        @if($form?->asksMealTiming())
                            <select wire:model="{{ $model }}.meal_timing" class="ui-input ui-input-sm !w-auto" style="font-size: 11px;" aria-label="When to take {{ $line['name'] ?? 'it' }} with meals">
                                <option value="">Meals: any time</option>
                                @foreach(\App\Enums\MealTiming::options() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        @endif
                        <label class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 font-semibold cursor-pointer {{ $taper ? 'bg-amber-100 text-amber-800' : 'bg-slate-50 text-slate-600 border border-slate-200' }}" style="font-size: 10px;" title="Mark a drug that must be reduced step by step, not stopped at once">
                            <input type="checkbox" wire:model.live="{{ $model }}.taper" class="h-3 w-3"> Taper
                        </label>
                        <input type="text" maxlength="{{ \App\Support\Clinical\DrugInstructions::MAX }}" wire:model.blur="{{ $model }}.instructions"
                            class="ui-input ui-input-sm flex-1 !w-auto {{ $taper && $note === '' ? 'border-amber-400' : '' }}" style="min-width: 200px; font-size: 11px;"
                            placeholder="{{ $taper ? 'Taper steps, e.g. 4× a day 1 week, 3× a day 1 week, 2× a day 1 week, then stop' : 'Note for the patient (optional)' }}"
                            aria-label="Note for {{ $line['name'] ?? 'this drug' }}">
                        <button type="button" x-on:click="picks = !picks" class="ui-button ui-button-sm ui-button-secondary py-0 px-1.5" style="font-size: 10px;" x-bind:aria-expanded="picks">
                            Quick notes <i class="fas fa-chevron-down" x-bind:class="picks && 'fa-rotate-180'"></i>
                        </button>
                    </div>
                    <div x-show="picks" x-cloak class="flex flex-wrap gap-1 mt-1">
                        @foreach(\App\Support\Clinical\DrugInstructions::CHIPS as $c => $chip)
                            <button type="button" wire:click="addInstructionChip('{{ $list }}', {{ $index }}, {{ $c }})"
                                class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-slate-600 hover:bg-slate-100" style="font-size: 10px;">+ {{ $chip }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
        </td>
    </tr>
@endif
