{{-- Catalogue & Stock, Products and Categories are one place in the menu: these tabs move between them. --}}
@php
    $tabs = array_filter([
        'optical.catalogue' => 'Catalogue & Stock',
        'optical.products' => 'Products',
        'optical.categories' => 'Categories',
    ], fn ($label, $route) => \App\Support\OpticalNavigation::allowed($route), ARRAY_FILTER_USE_BOTH);
@endphp
@if(count($tabs) > 1)
    <nav class="flex flex-wrap gap-1 border-b border-slate-200" aria-label="Catalogue sections">
        @foreach($tabs as $route => $label)
            <a href="{{ route($route) }}" wire:navigate @if(request()->routeIs($route)) aria-current="page" @endif
               class="-mb-px border-b-2 px-3 py-2 text-sm {{ request()->routeIs($route) ? 'border-teal-700 font-semibold text-teal-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}">{{ $label }}</a>
        @endforeach
    </nav>
@endif
