<nav class="mt-2" aria-label="Optical navigation">
    <ul class="nav nav-pills nav-sidebar flex-column">
        @foreach(\App\Support\OpticalNavigation::links() as [$route, $label, $icon])
            @if(! \App\Support\OpticalNavigation::allowed($route)) @continue @endif
            <li class="nav-item"><a href="{{ route($route) }}" class="nav-link {{ \App\Support\OpticalNavigation::active($route) ? 'active' : '' }}"><i class="nav-icon fas {{ $icon }}"></i><p>{{ $label }}</p></a></li>
        @endforeach

        {{-- Communications (patient recall is for clinic consultations, so not listed here) --}}
        @php $communicationLinks = array_filter(\App\Support\CommunicationsNavigation::links(), fn ($link) => $link[0] !== 'admin.patient-recall'); @endphp
        @if($communicationLinks)
            <li class="nav-header text-uppercase small">Communications</li>
            @foreach($communicationLinks as [$route, $label, $icon])
                <li class="nav-item"><a href="{{ route($route) }}" class="nav-link {{ request()->routeIs($route) ? 'active' : '' }}"><i class="nav-icon {{ $icon }}"></i><p>{{ $label }}</p></a></li>
            @endforeach
        @endif
    </ul>
</nav>
