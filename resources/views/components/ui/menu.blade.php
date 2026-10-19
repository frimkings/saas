{{--
    Secondary actions folded into one button, so a page shows a single primary action.

        <x-ui.menu label="More">
            <button type="button" wire:click="exportCsv" class="ui-menu-item" role="menuitem"><i class="fas fa-file-csv" aria-hidden="true"></i>Export CSV</button>
            <a href="…" class="ui-menu-item" role="menuitem">Template</a>
        </x-ui.menu>
--}}
@props(['label' => 'More', 'icon' => 'fa-ellipsis-h', 'align' => 'right'])
<div {{ $attributes->class(['relative inline-block']) }} x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false">
    <button type="button" class="ui-button ui-button-secondary" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-haspopup="menu">
        <i class="fas {{ $icon }}" aria-hidden="true"></i>{{ $label }}
    </button>
    <div x-show="open" x-cloak x-transition.opacity.duration.100ms role="menu" x-on:click="open = false"
         class="ui-menu {{ $align === 'left' ? 'left-0' : 'right-0' }}">
        {{ $slot }}
    </div>
</div>
