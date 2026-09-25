@props(['links' => [], 'current'])
@if(count($links) > 1)
<nav aria-label="Business line" {{ $attributes->class(['ui-actions no-print']) }}>
    @foreach($links as $key => $link)
        <x-ui.button :href="$link['url']" :variant="$key === $current ? 'primary' : 'secondary'" :aria-current="$key === $current ? 'page' : null">{{ $link['label'] }}</x-ui.button>
    @endforeach
</nav>
@endif
