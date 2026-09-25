@props(['label', 'name', 'type' => 'text', 'options' => null])
@php($id = 'ui-'.str_replace('.', '-', $name))
<div class="ui-field">
<label for="{{ $id }}">{{ $label }}</label>
@if($options !== null)
<select id="{{ $id }}" {{ $attributes->class(['ui-input'])->merge(['aria-invalid' => $errors->has($name) ? 'true' : 'false', 'aria-describedby' => $errors->has($name) ? $id.'-error' : null]) }}>
@foreach($options as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
</select>
@else
<input id="{{ $id }}" type="{{ $type }}" {{ $attributes->class(['ui-input'])->merge(['aria-invalid' => $errors->has($name) ? 'true' : 'false', 'aria-describedby' => $errors->has($name) ? $id.'-error' : null]) }}>
@endif
@error($name)<p id="{{ $id }}-error" class="ui-error" role="alert">{{ $message }}</p>@enderror
</div>

