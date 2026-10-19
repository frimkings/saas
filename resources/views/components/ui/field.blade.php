{{--
    A labelled form field: the label is always tied to the control, with an optional hint under it and the
    validation error read out with it. type: text, number, date, email, tel, password, textarea; options make
    a select. Pass id= to pick the id; otherwise it comes from name. Extra attributes (wire:model, placeholder,
    min…) go on the control.

        <x-ui.field label="Amount" name="amount" type="number" hint="In cedis" wire:model="amount" required />
--}}
@props(['label', 'name', 'type' => 'text', 'options' => null, 'hint' => null, 'id' => null, 'placeholderOption' => null])
@php
    $errors ??= new \Illuminate\Support\ViewErrorBag;
    $id = $id ?? 'ui-'.str_replace(['.', '[', ']'], '-', $name);
    $described = trim(($hint ? $id.'-hint ' : '').($errors->has($name) ? $id.'-error' : '')) ?: null;
    $control = $attributes->class(['ui-input'])->merge(['aria-invalid' => $errors->has($name) ? 'true' : 'false', 'aria-describedby' => $described]);
@endphp
<div class="ui-field">
<label for="{{ $id }}">{{ $label }}@if($attributes->has('required'))<span class="text-red-700" aria-hidden="true"> *</span>@endif</label>
@if($options !== null)
<select id="{{ $id }}" {{ $control }}>
@if($placeholderOption !== null)<option value="">{{ $placeholderOption }}</option>@endif
@foreach($options as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
</select>
@elseif($type === 'textarea')
<textarea id="{{ $id }}" {{ $control }}></textarea>
@else
<input id="{{ $id }}" type="{{ $type }}" {{ $control }}>
@endif
@if($hint)<p id="{{ $id }}-hint" class="ui-hint">{{ $hint }}</p>@endif
@error($name)<p id="{{ $id }}-error" class="ui-error" role="alert">{{ $message }}</p>@enderror
</div>
