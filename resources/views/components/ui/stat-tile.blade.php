{{--
    One figure: a label, the value, and a short hint. Tiles in a row are all the same height; put them in
    <div class="ui-tiles">. tone: neutral, accent, good, warning, danger. With href the whole tile is a link.

        <x-ui.stat-tile label="Collected" :value="currency().' 545.00'" hint="All methods" icon="fa-money-bill-wave" tone="accent" />
--}}
@props(['label', 'value', 'hint' => null, 'icon' => null, 'tone' => 'neutral', 'href' => null])
@php
    $tone = in_array($tone, ['neutral', 'accent', 'good', 'warning', 'danger'], true) ? $tone : 'neutral';
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class(['ui-tile', 'ui-tile--'.$tone, 'ui-tile--link' => (bool) $href]) }}>
    <span class="ui-tile__label">@if($icon)<i class="fas {{ $icon }}" aria-hidden="true"></i>@endif{{ $label }}</span>
    <span class="ui-tile__value">{{ $value }}</span>
    @if($hint || trim($slot) !== '')
        <span class="ui-tile__hint">{{ $hint }}{{ $slot }}</span>
    @endif
</{{ $tag }}>
