{{--
    The top of every page: the title (the same words as its menu entry), one line saying what the page is for,
    an optional status badge, and the page's actions on the right. One primary action at most; the rest secondary
    or in <x-ui.menu>.

        <x-ui.page-header title="Products" description="What you sell, with prices and stock.">
            <x-slot:badge><span class="ui-badge">Open</span></x-slot:badge>
            <x-ui.button variant="primary">Add product</x-ui.button>
        </x-ui.page-header>
--}}
@props(['title', 'description' => null, 'badge' => null])
<header {{ $attributes->class(['ui-heading']) }}>
    <div class="min-w-0">
        <h1 class="flex flex-wrap items-center gap-2">{{ $title }}@if($badge){{ $badge }}@endif</h1>
        @if($description)<p class="ui-muted">{{ $description }}</p>@endif
    </div>
    @if(trim($slot) !== '')
        <div class="ui-actions">{{ $slot }}</div>
    @endif
</header>
