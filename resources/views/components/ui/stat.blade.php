@props(['label', 'value'])
<x-ui.panel class="ui-stat"><p class="ui-muted">{{ $label }}</p><p class="ui-value">{{ $value }}</p>{{ $slot }}</x-ui.panel>

