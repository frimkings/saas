@props(['title', 'state'])
<dialog wire:ignore.self class="ui-dialog" aria-labelledby="ui-dialog-{{ $state }}" x-data x-init="$el.showModal()" x-on:cancel.prevent="$wire.set('{{ $state }}', false)">
<header class="ui-panel-heading"><h2 id="ui-dialog-{{ $state }}">{{ $title }}</h2><x-ui.button wire:click="$set('{{ $state }}', false)" aria-label="Close dialog">Close</x-ui.button></header>
{{ $slot }}
</dialog>
