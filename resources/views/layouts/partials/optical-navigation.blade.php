<nav class="mt-2" aria-label="Optical navigation">
    <ul class="nav nav-pills nav-sidebar flex-column">
        @foreach(\App\Support\OpticalNavigation::links() as [$route, $label, $icon])
            @if(! \App\Support\OpticalNavigation::allowed($route)) @continue @endif
            <li class="nav-item"><a href="{{ route($route) }}" class="nav-link {{ \App\Support\OpticalNavigation::active($route) ? 'active' : '' }}"><i class="nav-icon fas {{ $icon }}"></i><p>{{ $label }}</p></a></li>
        @endforeach
    </ul>
</nav>
