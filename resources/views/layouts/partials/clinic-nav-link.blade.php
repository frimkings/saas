{{-- One sidebar link in the clinic layout; $item is an App\Support\ClinicNavigation entry. --}}
@php $active = \App\Support\ClinicNavigation::isActive($item); @endphp
<a href="{{ route($item['route']) }}" @if($active) aria-current="page" @endif
   class="flex items-center gap-3 rounded-lg px-3 py-2 no-underline {{ $active ? 'bg-teal-600 font-semibold text-white hover:text-white' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200' }}">
    @isset($item['icon'])<i class="fas {{ $item['icon'] }} w-4 text-center" aria-hidden="true"></i>@endisset
    <span class="flex-1">{{ $item['label'] }}</span>
    @if($item['badge'] ?? 0)
        <span class="min-w-[1.25rem] rounded-full bg-red-500 px-1.5 text-center text-[11px] font-bold leading-5 text-white">{{ $item['badge'] }}</span>
    @endif
</a>
