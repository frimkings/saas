@props(['variant' => 'secondary', 'href' => null])
@php($classes = 'ui-button ui-button-'.(in_array($variant, ['primary', 'secondary', 'danger']) ? $variant : 'secondary'))
@if($href)
<a href="{{ $href }}" {{ $attributes->class([$classes]) }}>{{ $slot }}</a>
@else
<button {{ $attributes->merge(['type' => 'button'])->class([$classes]) }}>{{ $slot }}</button>
@endif

