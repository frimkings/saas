{{-- Sent / Owed / Optical centres: one sidebar link (Optical Centres) and this tab bar on each of the three pages. --}}
@php $opticalTabs = \App\Support\ClinicNavigation::opticalTabs(); @endphp
@if(count($opticalTabs) > 1)
    <nav class="ui-tabs !mb-0" aria-label="Optical centres" data-tour="optical-tabs">
        @foreach($opticalTabs as $tab)
            <a wire:navigate href="{{ route($tab['route']) }}" @if($tab['route'] === $current) aria-current="page" @endif>
                {{ $tab['label'] }}@if($tab['badge'])<span class="ui-badge !bg-red-100 !text-red-700">{{ $tab['badge'] }}</span>@endif
            </a>
        @endforeach
    </nav>
@endif
