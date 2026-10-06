<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('layouts.partials.page-title')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('backend/plugins/fontawesome-free/css/all.min.css') }}">
    <link rel="stylesheet" href="{{ asset('backend/plugins/vendor-css/pikaday.css') }}">
    {{-- SweetAlert: POS checkout confirmations and Managers' discount approval prompts. --}}
    <link rel="stylesheet" href="{{ asset('backend/plugins/sweetalert2/sweetalert2.min.css') }}">
    <script src="{{ asset('backend/plugins/sweetalert2/sweetalert2.js') }}"></script>
    <script src="{{ asset('backend/plugins/vendor-js/chart.min.js') }}"></script>
    @livewireStyles
    {{-- In the head: the bell and messages widgets subscribe to appPulse as they render. --}}
    @include('layouts.partials.session-scripts')
</head>
{{-- Clinic shell for every clinic screen (reception, doctors, admins): Tailwind only.
     `$menu` picks the sidebar (App\Support\ClinicNavigation); see docs/design-system-migration.md. --}}
<body class="clinic-ui min-h-screen bg-slate-100 text-slate-800">
@include('layouts.partials.toasts')
@php
    // Livewire pages pass menu as layout data; <x-clinic-layout menu="…"> passes it as an attribute.
    $menuName = $menu ?? (isset($attributes) ? $attributes->get('menu') : null) ?? 'reception';
    // Super Admins and Managers keep their own menu on reception and doctor pages too.
    if (auth()->user()?->hasAnyRole(['Super Admin', 'Manager'])) $menuName = 'admin';
    $menu = \App\Support\ClinicNavigation::menu($menuName);
    $settings = \App\Models\Setting::getSettings();
    $tenant = app(\App\Support\Tenancy\TenantContext::class);
    $hosted = config('tenancy.enabled') && auth()->check();
    $branches = $hosted ? $tenant->availableBranches() : collect();
    $clinics = $hosted ? $tenant->availableClinics() : collect();
@endphp
<div x-data="{ nav: false }" x-on:keydown.escape.window="nav = false" class="min-h-screen md:flex">
    {{-- Sidebar: fixed on desktop, a drawer on phones and tablets. --}}
    <div x-show="nav" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-slate-900/50 md:hidden" x-on:click="nav = false" aria-hidden="true"></div>
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-slate-900 text-slate-300 transition-transform md:sticky md:top-0 md:h-screen md:translate-x-0"
           :class="nav && '!translate-x-0'" aria-label="Main menu">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-3 border-b border-slate-800 px-4 py-3 text-white no-underline hover:text-white">
            @if($settings->clinic_logo)
                <img src="{{ asset('storage/' . $settings->clinic_logo) }}" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover">
            @else
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-teal-600" aria-hidden="true"><i class="fas fa-clinic-medical"></i></span>
            @endif
            <span class="min-w-0 leading-tight">
                <span class="block truncate text-[15px] font-semibold">{{ $tenant->clinic()?->name ?? $settings->clinic_name ?? config('app.name') }}</span>
                <span class="block truncate text-xs text-slate-400">{{ auth()->user()?->name }}</span>
            </span>
        </a>
        <div class="border-b border-slate-800 px-4 py-2 text-xs empty:hidden">@include('components.navigation-workspace', ['inline' => true])</div>
        <nav class="flex-1 space-y-1 overflow-y-auto p-3 text-sm">
            @foreach($menu as $item)
                @if(isset($item['items']))
                    @php $groupActive = \App\Support\ClinicNavigation::isActive($item); @endphp
                    <div x-data="{ open: @js($groupActive) }">
                        <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()"
                                class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left {{ $groupActive ? 'text-white' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200' }}">
                            <i class="fas {{ $item['icon'] }} w-4 text-center" aria-hidden="true"></i>
                            <span class="flex-1">{{ $item['label'] }}</span>
                            <i class="fas fa-chevron-down text-[10px] transition-transform" :class="open && 'rotate-180'" aria-hidden="true"></i>
                        </button>
                        <div x-show="open" x-cloak class="mt-1 space-y-1 pl-7">
                            @foreach($item['items'] as $child)
                                @include('layouts.partials.clinic-nav-link', ['item' => $child])
                            @endforeach
                        </div>
                    </div>
                @else
                    @include('layouts.partials.clinic-nav-link', ['item' => $item])
                @endif
            @endforeach
        </nav>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-2 md:px-6">
            <button type="button" class="-ml-1 flex h-9 w-9 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100 md:hidden" x-on:click="nav = true" aria-label="Open menu">
                <i class="fas fa-bars" aria-hidden="true"></i>
            </button>
            <div class="flex flex-1 flex-wrap items-center justify-end gap-2 text-xs">
                @if($hosted && auth()->user()->is_platform_admin)
                    <form method="POST" action="{{ route('tenant.mode.switch') }}">@csrf<input type="hidden" name="mode" value="platform">
                        <button class="h-8 rounded-md border border-slate-300 px-3 text-slate-700 hover:bg-slate-50"><i class="fas fa-server mr-1" aria-hidden="true"></i>Platform</button>
                    </form>
                @endif
                @if($clinics->count() > 1)
                    <a href="{{ route('tenant.clinic.select') }}" class="flex h-8 items-center gap-1.5 rounded-md border border-slate-300 px-3 text-slate-700 no-underline hover:bg-slate-50">
                        <i class="fas fa-hospital" aria-hidden="true"></i>{{ $tenant->clinic()?->name }}<i class="fas fa-exchange-alt text-slate-400" aria-hidden="true"></i>
                    </a>
                @endif
                @if($branches->count() > 1)
                    <form method="POST" action="{{ route('tenant.branch.switch') }}">@csrf
                        <select name="branch_id" onchange="this.form.submit()" aria-label="Active branch" class="h-8 rounded-md border-slate-300 py-0 pl-2 pr-8 text-xs">
                            @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($tenant->branchId() === $branch->id)>{{ $branch->name }}</option>@endforeach
                        </select>
                    </form>
                @elseif($hosted && $tenant->branch())
                    <span class="hidden h-8 items-center rounded-md bg-slate-100 px-3 text-slate-600 sm:flex">{{ $tenant->branch()->name }}</span>
                @endif
                @auth
                    <livewire:staff-messages-dropdown-component />
                    <livewire:notification-bell-component />
                    <div x-data="{ open: false }" class="relative" x-on:click.outside="open = false">
                        <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-haspopup="menu"
                                class="flex h-9 items-center gap-2 rounded-md px-2 text-slate-700 hover:bg-slate-100">
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-teal-100 text-xs font-semibold text-teal-800" aria-hidden="true">{{ \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}</span>
                            <span class="hidden max-w-[10rem] truncate text-sm sm:inline">{{ auth()->user()->name }}</span>
                            <i class="fas fa-chevron-down text-[10px] text-slate-400" aria-hidden="true"></i>
                        </button>
                        <div x-show="open" x-cloak x-transition.opacity.duration.100ms role="menu"
                             class="absolute right-0 z-30 mt-1 w-48 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg">
                            <a href="{{ route('user.profile') }}" role="menuitem" class="block px-4 py-2 text-slate-700 no-underline hover:bg-slate-50">My profile</a>
                            <a href="{{ route('staff.messages') }}" role="menuitem" class="block px-4 py-2 text-slate-700 no-underline hover:bg-slate-50">Messages</a>
                            <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100">@csrf
                                <button type="submit" role="menuitem" class="block w-full px-4 py-2 text-left text-slate-700 hover:bg-slate-50">Sign out</button>
                            </form>
                        </div>
                    </div>
                @endauth
            </div>
        </header>
        @php $notices = trim(view('components.subscription-notice')->render() . view('components.license-notice')->render()); @endphp
        @if($notices !== '')<div class="app-notices">{!! $notices !!}</div>@endif
        <main class="min-w-0 flex-1">
            {{ $slot }}
        </main>
    </div>
</div>
<x-ui.flash />
@include('layouts.partials.confirm-dialog')
@livewireScripts
@include('layouts.partials.till-scripts')
@include('layouts.partials.ui-toggles')
@include('layouts.partials.app-events')
@if($menuName === 'doctor')@include('layouts.partials.doctor-clearance-alert')@endif
@stack('scripts')
</body>
</html>
