<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Optical Suite · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('backend/plugins/fontawesome-free/css/all.min.css') }}">
    @livewireStyles
    <style>
        .optical-notices:empty { display: none; }
        .optical-notices .alert { margin: 16px 16px 0; padding: 10px 14px; border-radius: 8px; border: 1px solid #bfdbfe; background: #eff6ff; color: #1e3a8a; font-size: 13px; }
        .optical-notices .alert-warning { border-color: #fde68a; background: #fffbeb; color: #92400e; }
        .optical-notices .alert-danger { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
        .optical-notices .alert-link { font-weight: 600; text-decoration: underline; margin-left: 4px; }
        .optical-notices + .clinic-ui.ui-page { padding-top: 16px; }
        /* Pages that start with a <style> block: space-y-* would otherwise push the heading down. */
        .clinic-ui.ui-page > style:first-child + * { margin-top: 0; }
        @media (min-width: 768px) { .optical-notices .alert { margin: 16px 24px 0; } }
    </style>
</head>
<body class="clinic-ui min-h-screen bg-slate-100 text-slate-800">
@include('layouts.partials.toasts')
@php
    $tenant = app(\App\Support\Tenancy\TenantContext::class);
    $links = \App\Support\OpticalNavigation::links();
    // Menu badges: everything needing attention, and late or uncollected jobs on Orders.
    $attentionCounts = auth()->check() && ! auth()->user()->is_platform_admin ? app(\App\Services\Reminders\AttentionItems::class)->counts('optical') : [];
    $navBadges = ['optical.attention' => $attentionCounts['total'] ?? 0, 'optical.orders' => $attentionCounts['orders'] ?? 0];
@endphp
<div class="min-h-screen flex flex-col">
    {{-- One row: brand on the left, workspace / branch / sign out on the right, all the same height.
         The brand is not a heading: each page's own title is the page's single h1. --}}
    <header class="bg-slate-900 text-white px-4 md:px-6 py-2.5 flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-b border-slate-700 shadow-md">
        <a href="{{ route('optical.dashboard') }}" wire:navigate class="flex items-center gap-3 min-w-0 text-white no-underline hover:text-white">
            <span class="w-9 h-9 shrink-0 rounded-lg bg-teal-600 flex items-center justify-center text-sm font-bold" aria-hidden="true">OP</span>
            <span class="min-w-0 leading-tight">
                <span class="block text-[15px] font-semibold truncate">{{ $tenant->clinic()?->name ?? config('app.name') }}</span>
                <span class="block text-xs text-slate-400">Optical Suite</span>
            </span>
        </a>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            @include('components.navigation-workspace', ['inline' => true])
            <div class="h-8 flex items-center gap-1.5 rounded-md border border-slate-700 bg-slate-800 pl-2.5 pr-1">
                <span class="text-slate-400">Branch</span>
                <span class="rounded bg-slate-700 px-2 py-0.5 font-semibold text-teal-300">{{ $tenant->branch()?->name ?? 'Main Branch' }}</span>
            </div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="h-8 rounded-md px-3 text-slate-300 hover:bg-slate-800 hover:text-white">Sign out</button></form>
        </div>
    </header>
    <div class="flex flex-1 min-h-0">
        <aside class="w-64 bg-slate-900 text-slate-300 flex-shrink-0 border-r border-slate-800 flex flex-col justify-between overflow-y-auto hidden md:flex">
            <div>
                <nav class="p-3 space-y-6">
                    <div class="space-y-1">
                        <div class="px-3 text-[11px] font-bold text-teal-400 uppercase tracking-wider flex justify-between"><span>Optical Suite</span><span>●</span></div>
                        @foreach($links as [$route, $label, $icon])
                            @if($route === 'optical.settings' || ! \App\Support\OpticalNavigation::allowed($route)) @continue @endif
                            <a href="{{ route($route) }}" @if(\App\Support\OpticalNavigation::navigable($route)) wire:navigate @endif class="block px-3 py-2 rounded-lg text-sm {{ \App\Support\OpticalNavigation::active($route) ? 'bg-teal-600 text-white font-semibold' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200' }}">{{ $label }}@if($navBadges[$route] ?? 0)<span style="margin-left:8px;display:inline-block;min-width:20px;padding:0 6px;border-radius:999px;background:#ef4444;color:#fff;font-size:11px;font-weight:700;text-align:center">{{ $navBadges[$route] }}</span>@endif</a>
                        @endforeach
                    </div>
                    @hasanyrole('Manager|Super Admin')
                        <div class="space-y-1"><div class="px-3 text-[11px] font-bold text-slate-500 uppercase tracking-wider">Management</div>
                            <a href="{{ route('optical.categories') }}" wire:navigate class="block px-3 py-2 text-sm {{ request()->routeIs('optical.categories') ? 'bg-teal-600 text-white font-semibold' : 'text-slate-400 hover:bg-slate-800' }} rounded-lg">Optical Categories</a>
                            <a href="{{ route('optical.products') }}" wire:navigate class="block px-3 py-2 text-sm {{ request()->routeIs('optical.products') ? 'bg-teal-600 text-white font-semibold' : 'text-slate-400 hover:bg-slate-800' }} rounded-lg">Optical Products</a>
                            <a href="{{ route('optical.stock') }}" wire:navigate class="block px-3 py-2 text-sm {{ request()->routeIs('optical.stock') ? 'bg-teal-600 text-white font-semibold' : 'text-slate-400 hover:bg-slate-800' }} rounded-lg">Stock Restocking &amp; Batches</a>
                        </div>
                        <a href="{{ route('optical.settings') }}" wire:navigate class="block px-3 py-2 rounded-lg text-sm {{ request()->routeIs('optical.settings') ? 'bg-teal-600 text-white font-semibold' : 'text-slate-400 hover:bg-slate-800' }}">Settings</a>
                    @endhasanyrole
                    @if(\App\Support\OpticalNavigation::canManageStaff())
                        {{-- Staff are managed on the shared staff screen; it links back here. --}}
                        <a href="{{ route('admin.users', ['from' => 'optical']) }}" class="block px-3 py-2 rounded-lg text-sm text-slate-400 hover:bg-slate-800">Staff &amp; roles</a>
                    @endif
                </nav>
            </div>
            <div class="p-3 border-t border-slate-800 text-xs text-slate-500">Optical Suite</div>
        </aside>
        <main class="flex-1 bg-slate-50 min-w-0 overflow-y-auto">
            <nav class="md:hidden flex gap-2 overflow-x-auto p-2 bg-slate-900 text-white text-xs" aria-label="Optical navigation">
                @foreach($links as [$route, $label])@if(! \App\Support\OpticalNavigation::allowed($route)) @continue @endif<a href="{{ route($route) }}" @if(\App\Support\OpticalNavigation::navigable($route)) wire:navigate @endif class="whitespace-nowrap px-2 py-1 rounded {{ \App\Support\OpticalNavigation::active($route) ? 'bg-teal-600' : '' }}">{{ $label }}</a>@endforeach
                @hasanyrole('Manager|Super Admin')<a href="{{ route('optical.categories') }}" wire:navigate class="whitespace-nowrap px-2 py-1 rounded {{ request()->routeIs('optical.categories') ? 'bg-teal-600' : '' }}">Optical Categories</a><a href="{{ route('optical.products') }}" wire:navigate class="whitespace-nowrap px-2 py-1 rounded {{ request()->routeIs('optical.products') ? 'bg-teal-600' : '' }}">Optical Products</a><a href="{{ route('optical.stock') }}" wire:navigate class="whitespace-nowrap px-2 py-1 rounded {{ request()->routeIs('optical.stock') ? 'bg-teal-600' : '' }}">Stock Restocking &amp; Batches</a>@endhasanyrole
                @if(\App\Support\OpticalNavigation::canManageStaff())<a href="{{ route('admin.users', ['from' => 'optical']) }}" class="whitespace-nowrap px-2 py-1 rounded">Staff &amp; roles</a>@endif
            </nav>
            {{-- Notices line up with the page content below and sit close to it. --}}
            @php $opticalNotices = trim(view('components.license-notice')->render()); @endphp
            @if($opticalNotices !== '')<div class="optical-notices">{!! $opticalNotices !!}</div>@endif
            {{ $slot }}
        </main>
    </div>
</div>
<x-ui.flash />
@include('layouts.partials.confirm-dialog')
@livewireScripts
</body>
</html>
