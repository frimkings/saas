@props(['title', 'state'])
{{-- Close and Esc close the dialog in the browser (dismissLocal, resources/js/dismiss.js); pages without that script ask the server. --}}
@php($close = "window.dismissLocal ? dismissLocal(\$el, \$wire, '{$state}') : \$wire.set('{$state}', false)")
<dialog wire:ignore.self class="ui-dialog" aria-labelledby="ui-dialog-{{ $state }}" x-data x-init="$el.showModal()" x-on:cancel.prevent="{{ $close }}">
<header class="ui-panel-heading"><h2 id="ui-dialog-{{ $state }}">{{ $title }}</h2><x-ui.button x-on:click="{{ $close }}" aria-label="Close dialog">Close</x-ui.button></header>
{{ $slot }}
</dialog>
